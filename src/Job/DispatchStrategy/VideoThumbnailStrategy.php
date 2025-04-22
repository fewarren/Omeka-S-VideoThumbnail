<?php
namespace VideoThumbnail\Job\DispatchStrategy;

use Omeka\Entity\Job;
use Omeka\Job\Dispatcher;
use Omeka\Job\DispatchStrategy\StrategyInterface;
use VideoThumbnail\Stdlib\Debug;

class VideoThumbnailStrategy implements StrategyInterface
{
    protected $jobDispatcher;
    protected $entityManager;
    protected $dispatcher;
    protected $defaultStrategy;
    protected $config;

    public function __construct($jobDispatcher, $entityManager, Dispatcher $dispatcher, StrategyInterface $defaultStrategy, array $config)
    {
        $this->jobDispatcher = $jobDispatcher;
        $this->entityManager = $entityManager;
        $this->dispatcher = $dispatcher;
        $this->defaultStrategy = $defaultStrategy;
        $this->config = $config;
    }

    public function start(Job $job)
    {
        try {
            // Initialize job status
            $job->setStatus('Starting');
            $job->setData('progress', 0);
            $this->entityManager->flush();

            // Dispatch the actual job
            $this->jobDispatcher->dispatch($job);

            return true;
        } catch (\Exception $e) {
            Debug::logError(sprintf(
                'Failed to start job %d: %s',
                $job->getId(),
                $e->getMessage()
            ), __METHOD__);
            
            $job->setStatus('Error: ' . $e->getMessage());
            $this->entityManager->flush();
            
            return false;
        }
    }

    public function stop(Job $job)
    {
        try {
            $job->setStatus('Stopped');
            $this->entityManager->flush();
            
            // Additional cleanup if needed
            return true;
        } catch (\Exception $e) {
            Debug::logError(sprintf(
                'Failed to stop job %d: %s',
                $job->getId(),
                $e->getMessage()
            ), __METHOD__);
            return false;
        }
    }

    public function handleExpired(Job $job)
    {
        // Check if job can be recovered
        if ($this->canRecover($job)) {
            return $this->recoverJob($job);
        }

        // Mark as failed if recovery not possible
        $job->setStatus('Failed: Job expired');
        $this->entityManager->flush();
        return false;
    }

    protected function canRecover(Job $job)
    {
        // Check if job has made some progress
        $progress = $job->getData('progress', 0);
        if ($progress > 0 && $progress < 100) {
            return true;
        }

        // Check number of previous attempts
        $attempts = $job->getData('recovery_attempts', 0);
        return $attempts < 3;
    }

    protected function recoverJob(Job $job)
    {
        try {
            $attempts = $job->getData('recovery_attempts', 0);
            $job->setData('recovery_attempts', $attempts + 1);
            
            // Create recovery point data
            $recoveryData = [
                'progress' => $job->getData('progress', 0),
                'last_frame' => $job->getData('last_processed_frame', 0),
                'timestamp' => time()
            ];
            $job->setData('recovery_point', $recoveryData);
            
            // Restart the job
            $job->setStatus('Recovering');
            $this->entityManager->flush();
            
            return $this->start($job);
            
        } catch (\Exception $e) {
            Debug::logError(sprintf(
                'Failed to recover job %d: %s',
                $job->getId(),
                $e->getMessage()
            ), __METHOD__);
            return false;
        }
    }

    public function send(Job $job)
    {
        // First try the default strategy
        try {
            return $this->defaultStrategy->send($job);
        } catch (\Exception $e) {
            Debug::logWarning(
                sprintf('Default strategy failed for job %s: %s', $job->getId(), $e->getMessage()),
                __METHOD__
            );
            
            // If default strategy fails, try our recovery process
            return $this->handleRecovery($job);
        }
    }

    protected function handleRecovery(Job $job)
    {
        $args = $job->getArgs();
        $maxRetries = $this->config['videothumbnail']['job_settings']['max_retries'] ?? 3;
        $retryCount = $args['__retry_count'] ?? 0;

        if ($retryCount >= $maxRetries) {
            Debug::logError(
                sprintf('Job %s exceeded maximum retry attempts (%d)', $job->getId(), $maxRetries),
                __METHOD__
            );
            throw new \RuntimeException('Exceeded maximum retry attempts');
        }

        // Increment retry count
        $args['__retry_count'] = $retryCount + 1;
        $job->setArgs($args);

        // Add exponential backoff delay
        $delay = min(
            30, // Maximum delay of 30 seconds
            pow(2, $retryCount) * ($this->config['videothumbnail']['job_settings']['base_delay'] ?? 1)
        );
        
        Debug::log(
            sprintf(
                'Retrying job %s (attempt %d of %d) after %d second delay',
                $job->getId(),
                $retryCount + 1,
                $maxRetries,
                $delay
            ),
            __METHOD__
        );

        // Wait before retry
        sleep($delay);

        // Try sending again with updated retry count
        return $this->defaultStrategy->send($job);
    }
}
