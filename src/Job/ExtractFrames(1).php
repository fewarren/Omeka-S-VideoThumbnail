<?php

namespace VideoThumbnail\Job;

use Omeka\Job\AbstractJob;

class ExtractFrames extends AbstractJob
{
    /**
     * @var float Last recorded memory usage for reset/tracking purposes
     */
    protected $lastMemoryUsage = 0;
    /****
     * Returns the peak memory usage in megabytes.
     *
     * Optionally records the current memory usage to track new peaks from this point onward if $reset is true.
     *
     * @param bool $reset If true, records the current memory usage for future peak tracking.
     * @return float Peak memory usage in megabytes.
     */
    protected function getMemoryUsage($reset = false)
    {
        // Use memory_get_peak_usage with real_usage=true to get actual memory allocated from system
        $mem = memory_get_peak_usage(true);
        
        // Optionally reset peak tracking after checking
        if ($reset) {
            // No direct way to reset peak in PHP, but we can record current usage
            // to track new peaks from this point forward in application logic
            $this->lastMemoryUsage = memory_get_usage(true);
        }
        
        return $mem / 1048576; // Return memory usage in MB
    }

    /**
     * Retrieves relevant job arguments for frame extraction.
     *
     * Returns an associative array containing the 'frame_position' and 'force_strategy' arguments if they are set for the job.
     *
     * @return array Associative array of job arguments.
     */
    protected function getJobArguments()
    {
        // Access arguments directly as they are passed by the controller
        $args = [];
        
        // Get frame_position if set
        if ($this->hasArg('frame_position')) {
            $args['frame_position'] = $this->getArg('frame_position');
        }
        
        // Get force_strategy if set
        if ($this->hasArg('force_strategy')) {
            $args['force_strategy'] = $this->getArg('force_strategy');
        }
        
        return $args;
    }

    /****
     * Executes the job to extract video frames and generate thumbnails for supported video media.
     *
     * Iterates over all media entities with supported video MIME types, extracts a frame at a configurable position within each video, and generates thumbnails from the extracted frame. Updates media entities with thumbnail metadata and handles errors, memory usage, and manual stop requests during processing. Logs progress and summary statistics upon completion.
     */
    public function perform()
    {
        $startTime = microtime(true);

        try {
            $logger = $this->getServiceLocator()->get('Omeka\Logger');
            $settings = $this->getServiceLocator()->get('Omeka\Settings');
            
            // Fetch job arguments
            $args = $this->getJobArguments();
            $logger->info('Job arguments received: ' . print_r($args, true));

            // Validate and set the frame position
            $defaultFramePercent = isset($args['frame_position']) && is_numeric($args['frame_position']) 
                ? (float)$args['frame_position'] 
                : (float)$settings->get('videothumbnail_default_frame', 10);
                
            // Clamp frame position percentage to valid range [0,100]
            $defaultFramePercent = max(0, min(100, $defaultFramePercent));

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
                    
                    // Confirm frame percentage is still in valid range [0,100] at point of use
                    $framePercentage = max(0, min(100, $defaultFramePercent));
                    
                    // Convert percentage to seconds
                    $position = ($duration * $framePercentage) / 100;
                    
                    // Ensure the position is within valid range (at least 0.1s from start or end)
                    $position = max(0.1, min($position, $duration - 0.1));
                    
                    $logger->info(sprintf(
                        'Converting frame position %f%% to %f seconds (duration: %f seconds)',
                        $framePercentage,
                        $position,
                        $duration
                    ));

                    // Extract a frame at the calculated position
                    $framePath = $videoFrameExtractor->extractFrame($filePath, $position);
                    
                    if (!$framePath) {
                        $logger->warn(sprintf('Failed to extract frame from video: %s', $filePath));
                        $failed++;
                        continue;
                    }
                    
                    // Store the extracted frame in Omeka's file system and generate thumbnails
                    try {
                        // Get the necessary services for file handling
                        $tempFileFactory = $this->getServiceLocator()->get('Omeka\File\TempFileFactory');
                        $fileStore = $this->getServiceLocator()->get('Omeka\File\Store');
                        $thumbnailSynchronizer = $this->getServiceLocator()->get('VideoThumbnail\ThumbnailSynchronizer');
                        
                        // Create a TempFile object from the extracted frame
                        $tempFile = $tempFileFactory->build();
                        $tempFile->setSourceName(basename($framePath));
                        $tempFile->setTempPath($framePath);
                        
                        // Get MIME type for the extracted frame (should be image/jpeg)
                        $finfo = new \finfo(FILEINFO_MIME_TYPE);
                        $mediaType = $finfo->file($framePath);
                        $tempFile->setMediaType($mediaType);
                        
                        // Set the storage ID to match the media's
                        $tempFile->setStorageId($media->getStorageId());
                        
                        // Generate and store thumbnails
                        $hasThumbnails = $tempFile->storeThumbnails();
                        
                        // Update the media entity with thumbnail info
                        if ($hasThumbnails) {
                            $media->setHasThumbnails(true);
                            
                            // Getting existing media data
                            $mediaData = $media->getData() ?: [];
                            
                            // Update the videothumbnail_frame field
                            $mediaData['videothumbnail_frame'] = $position;
                            $mediaData['videothumbnail_timestamp'] = time();
                            
                            // Set the updated data back to the media
                            $media->setData($mediaData);
                            
                            // Ensure the thumbnails are synchronized in the database
                            $thumbnailSynchronizer->updateThumbnailStoragePaths($media);
                            
                            $logger->info(sprintf('Thumbnails generated successfully for video %d', $media->getId()));
                        } else {
                            $logger->warn(sprintf('Failed to generate thumbnails for media %d', $media->getId()));
                        }
                    } catch (\Exception $e) {
                        $logger->err(sprintf('Error storing thumbnails for video %d: %s', $media->getId(), $e->getMessage()));
                        // Continue with the next media even if thumbnail generation fails
                    } finally {
                        // Clean up the temporary file if it still exists
                        if (isset($tempFile) && method_exists($tempFile, 'delete')) {
                            $tempFile->delete();
                        }
                        
                        if (file_exists($framePath)) {
                            @unlink($framePath);
                        }
                    }
                    
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
     * Checks if the current peak memory usage exceeds the configured limit and throws an exception if it does.
     *
     * @throws \RuntimeException If memory usage exceeds the configured threshold.
     */
    protected function checkMemoryUsage()
    {
        // Get configurable memory limit from settings, default to 100MB if not set
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $memoryLimit = (int)$settings->get('videothumbnail_memory_limit', 100);
        
        // Ensure a reasonable minimum limit
        $memoryLimit = max(50, $memoryLimit);
        
        // Check peak memory usage
        $memoryUsage = $this->getMemoryUsage(true); // Get and reset peak tracking
        
        // Log memory usage periodically
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info(sprintf('Memory usage: %.2f MB (limit: %d MB)', $memoryUsage, $memoryLimit));
        
        if ($memoryUsage > $memoryLimit) {
            throw new \RuntimeException(sprintf(
                'Memory usage exceeded: %.2f MB (limit: %d MB)',
                $memoryUsage,
                $memoryLimit
            ));
        }
    }

    /****
     * Throws an exception to halt execution if the job has been manually requested to stop.
     *
     * @throws \RuntimeException If the job was manually stopped.
     */
    protected function stopIfRequested()
    {
        if ($this->shouldStop()) {
            throw new \RuntimeException('Job was manually stopped.');
        }
    }
}
