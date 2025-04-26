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

    /**
     * Initializes the VideoThumbnailStrategy with required dependencies and configuration.
     *
     * @param mixed $jobDispatcher Service responsible for dispatching jobs.
     * @param mixed $entityManager Entity manager for persisting job state.
     * @param Dispatcher $dispatcher Omeka job dispatcher instance.
     * @param StrategyInterface $defaultStrategy Fallback strategy for job dispatching.
     * @param array $config Configuration options for job handling.
     */
    public function __construct($jobDispatcher, $entityManager, Dispatcher $dispatcher, StrategyInterface $defaultStrategy, array $config)
    {
        $this->jobDispatcher = $jobDispatcher;
        $this->entityManager = $entityManager;
        $this->dispatcher = $dispatcher;
        $this->defaultStrategy = $defaultStrategy;
        $this->config = $config;
    }

    /**
     * Starts a video thumbnail job by initializing its status and dispatching it.
     *
     * Sets the job status to "Starting", resets progress, and attempts to dispatch the job. Returns true on success, or false if an error occurs.
     *
     * @param Job $job The job instance to start.
     * @return bool True if the job was started successfully, false if an error occurred.
     */
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

    /**
     * Stops the given job and updates its status to "Stopped".
     *
     * Attempts to persist the status change. Returns true on success, or false if an error occurs.
     *
     * @param Job $job The job to stop.
     * @return bool True if the job was successfully stopped, false otherwise.
     */
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

    /**
     * Handles an expired job by attempting recovery or marking it as failed.
     *
     * If the job is eligible for recovery, initiates the recovery process. Otherwise, sets the job status to "Failed: Job expired" and persists the change.
     *
     * @param Job $job The job instance to handle.
     * @return bool True if the job was recovered; false if marked as failed.
     */
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

    /**
     * Determines if a job is eligible for recovery based on its progress and recovery attempts.
     *
     * Returns true if the job has made partial progress (greater than 0% but less than 100%) or if the number of recovery attempts is less than 3.
     *
     * @param Job $job The job to evaluate for recovery eligibility.
     * @return bool True if the job can be recovered; otherwise, false.
     */
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

    /**
     * Attempts to recover a failed or expired job by incrementing recovery attempts, saving a recovery point, and restarting the job.
     *
     * Updates the job's recovery attempt count and stores the current progress and last processed frame as a recovery point. Sets the job status to "Recovering" and restarts the job. Returns the result of the restart operation, or false if recovery fails.
     *
     * @param Job $job The job instance to recover.
     * @return bool True if the job was successfully restarted; false on failure.
     */
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

    /**
     * Sends a job using the default dispatch strategy, with automatic recovery on failure.
     *
     * Attempts to send the job via the default strategy. If an exception occurs, logs a warning and initiates a recovery process with retry logic.
     *
     * @param Job $job The job to be dispatched.
     * @return mixed The result of the job dispatch or recovery attempt.
     */
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

    /**
     * Handles job retry logic with exponential backoff when a job send attempt fails.
     *
     * Increments the job's retry count, applies a delay before retrying, and re-sends the job using the default strategy. Throws a RuntimeException if the maximum number of retries is exceeded.
     *
     * @param Job $job The job to retry.
     * @return mixed The result of the default strategy's send method.
     * @throws \RuntimeException If the maximum number of retry attempts is exceeded.
     */
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

    /**
     * Initializes and returns the PhpCli job dispatch strategy from the service locator.
     *
     * Checks for the presence of required services and throws a RuntimeException if the Omeka job dispatcher is missing. Falls back to the PhpCli strategy if necessary.
     *
     * @param mixed $serviceLocator Service locator providing required services.
     * @return mixed The PhpCli dispatch strategy instance.
     * @throws \RuntimeException If the required Omeka job dispatcher service is not found.
     */
    protected function initializeStrategy($serviceLocator)
    {
        \VideoThumbnail\Stdlib\Debug::log('Initializing VideoThumbnail strategy', __METHOD__);
        
        // Check for required services
        if (!$serviceLocator->has('Omeka\Job\Dispatcher')) {
            \VideoThumbnail\Stdlib\Debug::logError('Required service Omeka\Job\Dispatcher not found', __METHOD__);
            throw new \RuntimeException('Required service Omeka\Job\Dispatcher not found');
        }
        
        if (!$serviceLocator->has('Omeka\Job\DispatchStrategy\PhpCli')) {
            \VideoThumbnail\Stdlib\Debug::logWarning('Using PhpCli fallback strategy for video thumbnail processing', __METHOD__);
            // Use PhpCli as fallback
            return $serviceLocator->get('Omeka\Job\DispatchStrategy\PhpCli');
        }

        \VideoThumbnail\Stdlib\Debug::log('Strategy initialized successfully', __METHOD__);
        return $serviceLocator->get('Omeka\Job\DispatchStrategy\PhpCli');
    }
}
