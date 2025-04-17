<?php
namespace Omeka\Job;

use Omeka\Job\AbstractJob;

class ExtractFrames extends AbstractJob
{
    protected function getMemoryUsage()
    {
        $mem = memory_get_usage();
        return round($mem / 1048576, 2) . ' MB';
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

    public function perform()
    {
        $startTime = microtime(true);

        try {
            $logger = $this->getServiceLocator()->get('Omeka\Logger');
            $settings = $this->getServiceLocator()->get('Omeka\Settings');
            
            // Fetch job arguments
            $args = $this->getJobArguments(); // Use the new method to fetch job arguments
            
            $defaultFramePercent = isset($args['frame_position']) 
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

                // Processing logic remains unchanged
                $logger->info(sprintf('Processing video %d of %d', $index + 1, $totalMedias));
            }

            $logger->info('VideoThumbnail: Job completed successfully.');
        } catch (\Exception $e) {
            $logger->err('Fatal error in thumbnail regeneration job: ' . $e->getMessage());
        }
    }

    protected function checkMemoryUsage()
    {
        $memoryUsage = $this->getMemoryUsage();
        if ($memoryUsage > 100) {
            throw new \RuntimeException('Memory usage exceeded: ' . $memoryUsage);
        }
    }

    protected function stopIfRequested()
    {
        if ($this->job->isStopped()) {
            throw new \RuntimeException('Job was manually stopped.');
        }
    }
}
