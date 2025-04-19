<?php

namespace VideoThumbnail\Job;

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

            $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
            $mediaRepository = $entityManager->getRepository('Omeka\Entity\Media');
            
            // Get supported video formats from settings
            $supportedFormats = $settings->get('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']);
            if (!is_array($supportedFormats)) {
                $supportedFormats = ['video/mp4', 'video/quicktime'];
            }
            
            // Query for all supported video formats
            $queryBuilder = $mediaRepository->createQueryBuilder('media');
            $queryBuilder->where($queryBuilder->expr()->in('media.mediaType', ':formats'))
                        ->setParameter('formats', $supportedFormats);
            
            $medias = $queryBuilder->getQuery()->getResult();
            $totalMedias = count($medias);

            $logger->info(sprintf('VideoThumbnail: Starting thumbnail regeneration for %d videos', $totalMedias));

            if ($totalMedias === 0) {
                $logger->info('VideoThumbnail: No video files found to process');
                return;
            }
            
            // Get the video frame extractor service
            $videoFrameExtractor = $this->getServiceLocator()->get('VideoThumbnail\VideoFrameExtractor');
            $processed = 0;
            $failed = 0;

            foreach ($medias as $index => $media) {
                // Add periodic memory and stop checks
                $this->checkMemoryUsage();
                $this->stopIfRequested();

                try {
                    $logger->info(sprintf('Processing video %d of %d', $index + 1, $totalMedias));
                    
                    // Get the file path using storage path and file manager
                    $fileManager = $this->getServiceLocator()->get('Omeka\File\Store');
                    $storagePath = sprintf('original/%s', $media->getFilename());
                    $filePath = $fileManager->getLocalPath($storagePath);
                    
                    if (!file_exists($filePath) || !is_readable($filePath)) {
                        $logger->warn(sprintf('Video file not found or not readable: %s', $filePath));
                        $failed++;
                        continue;
                    }
                    
                    // Calculate frame position based on settings
                    // Get video duration first
                    $duration = $videoFrameExtractor->getVideoDuration($filePath);
                    if ($duration <= 0) {
                        $logger->warn(sprintf('Could not determine video duration for: %s', $filePath));
                        $duration = 60; // Fallback to 60 seconds
                    }
                    
                    // Convert percentage to seconds
                    $position = ($duration * $defaultFramePercent) / 100;

                    // Extract a frame at the calculated position
                    $framePath = $videoFrameExtractor->extractFrame($filePath, $position);
                    
                    if (!$framePath) {
                        $logger->warn(sprintf('Failed to extract frame from video: %s', $filePath));
                        $failed++;
                        continue;
                    }
                    
                    // Getting existing media data
                    $mediaData = $media->getData() ?: [];
                    
                    // Update just the videothumbnail_frame field
                    $mediaData['videothumbnail_frame'] = $position;
                    
                    // Set the updated data back to the media
                    $media->setData($mediaData);
                    
                    // Save the changes
                    $entityManager->persist($media);
                    $entityManager->flush();
                    
                    $processed++;
                    $logger->info(sprintf('Extracted thumbnail for video %d at position %f seconds (%f%% of %f seconds duration)', 
                        $media->getId(), $position, $defaultFramePercent, $duration));
                } catch (\Exception $e) {
                    $logger->err(sprintf('Error processing video %d: %s', $media->getId(), $e->getMessage()));
                    $failed++;
                }
            }
            
            $logger->info(sprintf('VideoThumbnail: Job completed. Processed %d videos successfully, %d failed.', $processed, $failed));
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
        if ($this->shouldStop()) {
            throw new \RuntimeException('Job was manually stopped.');
        }
    }

    /**
     * Get a storage path.
     *
     * @param string $prefix The storage prefix (e.g., 'original', 'thumbnail')
     * @param string $storageId The unique storage ID of the media
     * @param string $extension Optional file extension
     * @return string The constructed storage path
     */
    protected function getStoragePath(string $prefix, string $storageId, string $extension = ''): string
    {
        return sprintf('%s/%s%s', $prefix, $storageId, strlen($extension) ? '.' . $extension : '');
    }
}
