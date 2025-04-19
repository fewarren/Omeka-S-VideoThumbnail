<?php
namespace VideoThumbnail\Job\DispatchStrategy;

use Omeka\Job\DispatchStrategy\StrategyInterface;
use Omeka\Entity\Job;
use Omeka\Job\Exception\RuntimeException;

class VideoThumbnailStrategy implements StrategyInterface
{
    /**
     * @var \Omeka\Job\DispatchStrategy\PhpCli
     */
    protected $phpCliStrategy;

    /**
     * Constructor.
     *
     * @param \Omeka\Job\DispatchStrategy\PhpCli $phpCliStrategy
     */
    public function __construct($phpCliStrategy)
    {
        $this->phpCliStrategy = $phpCliStrategy;
    }

    /**
     * Start a new job process.
     *
     * @param Job $job
     */
    public function start(Job $job)
    {
        try {
            // Get the job ID
            $jobId = $job->getId();

            // Set the job status
            $job->setStatus(Job::STATUS_STARTING);

            // Log that we're dispatching the job
            error_log(sprintf('VideoThumbnail job %s starting execution via PhpCli strategy', $jobId));
            
            // Use the PhpCli strategy to actually execute the job
            $this->phpCliStrategy->start($job);
            
        } catch (\Exception $e) {
            // Log the exception
            error_log(sprintf('Error dispatching VideoThumbnail job: %s', $e->getMessage()));
            error_log($e->getTraceAsString());
            
            // Set job status to error
            $job->setStatus(Job::STATUS_ERROR);
            
            // Rethrow the exception
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Dispatch a job to be processed.
     *
     * @param Job $job
     */
    public function dispatchJob(Job $job)
    {
        $this->start($job);
    }
}
