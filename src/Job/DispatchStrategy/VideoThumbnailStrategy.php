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
     * Initializes the VideoThumbnailStrategy with a PhpCli dispatch strategy.
     *
     * @param \Omeka\Job\DispatchStrategy\PhpCli $phpCliStrategy The dispatch strategy used to execute jobs.
     */
    public function __construct($phpCliStrategy)
    {
        $this->phpCliStrategy = $phpCliStrategy;
    }

    /**
     * Initiates execution of a job using the PhpCli strategy, updating job status and handling errors.
     *
     * Sets the job status to starting, logs the initiation, and delegates execution to the PhpCli strategy.
     * If an exception occurs, logs the error, updates the job status to error, and rethrows the exception wrapped in a RuntimeException.
     *
     * @param Job $job The job to be executed.
     * @throws RuntimeException If job execution fails.
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

    /****
     * Dispatches a job for processing by delegating to the start method.
     *
     * @param Job $job The job instance to be dispatched.
     */
    public function dispatchJob(Job $job)
    {
        $this->start($job);
    }
}
