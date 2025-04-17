<?php
namespace Omeka\Job;

use Omeka\Job\AbstractJob;

class ExtractFrames extends AbstractJob
{
    /**
     * Get the current memory usage in MB.
     *
     * @return float Memory usage in MB
     */
    protected function getMemoryUsage()
    {
        $mem = memory_get_usage();
        return $mem / 1048576; // Return memory usage in MB
    }

    /**
     * Retrieve job arguments
     *
     * @return array The job arguments
     */
    protected function getJobArguments()
    {
        // Replace with actual logic to fetch job arguments
        return $this->getArg('args', []);
    }

    /**
     * Perform the job of extracting video frames.
     */
    public function perform()
    {
        $startTime = microtime(true);

        try {
            $logger = $this->getServiceLocator()->get('Omeka\Logger');
            $settings = $this->getServiceLocator()->get('Omeka\Settings');
            
            // Fetch job arguments
            $args = $this->getJobArguments();

            // Validate and set the frame position
            $defaultFramePercent = isset($args['frame_position']) && is_numeric($args['frame_position']) 
                ? (float)$args['frame_position'] 
                : (float)$settings->get('videothumbnail_default_frame', 10);

            $mediaRepository = $this->getServiceLocator()->get('Omeka\EntityManager')->getRepository('Omeka\Entity\Media');
            $medias = $mediaRepository->findBy(['mediaType' => 'video/mp4']);
            $totalMedias = count($medias);

            $logger->info(sprintf('VideoThumbnail: Starting thumbnail regeneration for %d videos', $totalMedias));

            if ($totalMedias === 0) {
                $logger->info('VideoThumbnail: No video files found to process');
                return;
            }

            foreach ($medias as $index => $media) {
                // Add periodic memory and stop checks
                $this->checkMemoryUsage();
                $this->stopIfRequested();

                // Processing logic (placeholder)
                $logger->info(sprintf('Processing video %d of %d', $index + 1, $totalMedias));
                // Add actual frame extraction logic here
            }

            $logger->info('VideoThumbnail: Job completed successfully.');
        } catch (\Exception $e) {
            $logger->err('Fatal error in thumbnail regeneration job: ' . $e->getMessage());
        }
    }

    /**
     * Check if memory usage exceeds the allowed threshold.
     *
     * @throws \RuntimeException If memory usage exceeds the limit
     */
    protected function checkMemoryUsage()
    {
        $memoryUsage = $this->getMemoryUsage();
        if ($memoryUsage > 100) { // Threshold set to 100MB
            throw new \RuntimeException('Memory usage exceeded: ' . $memoryUsage . ' MB');
        }
    }

    /**
     * Stop the job if requested.
     *
     * @throws \RuntimeException If the job was manually stopped
     */
    protected function stopIfRequested()
    {
        if ($this->job->isStopped()) {
            throw new \RuntimeException('Job was manually stopped.');
        }
    }
}
