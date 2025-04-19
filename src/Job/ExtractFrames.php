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
     * Optionally records the current memory usage to track new peaks from this point forward.
     *
     * @param bool $reset If true, records current memory usage for future peak tracking.
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
     * Retrieves the frame extraction job arguments if provided.
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
     * Executes the batch job to extract video frames and generate thumbnails for all supported video media.
     *
     * Iterates through all video media entities of supported formats, extracts a frame at a configurable position (with fallbacks), and stores the resulting image as thumbnails. Handles memory usage checks, job stop requests, error logging, and updates media metadata with thumbnail information.
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
            
            // Log the frame position value being used
            $logger->info(sprintf('Using frame position: %f%% of video duration', $defaultFramePercent));

            $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
            $mediaRepository = $entityManager->getRepository('Omeka\Entity\Media');

            // Get supported video formats from settings
            $defaultSupportedFormats = [
                'video/mp4',          // MP4 files
                'video/quicktime',    // MOV files
                'video/x-msvideo',    // AVI files
                'video/x-ms-wmv',     // WMV files
                'video/x-matroska',   // MKV files
                'video/webm',         // WebM files
                'video/3gpp',         // 3GP files
                'video/3gpp2',        // 3G2 files
                'video/x-flv'         // FLV files
            ];
            $supportedFormats = $settings->get('videothumbnail_supported_formats', $defaultSupportedFormats);
            if (!is_array($supportedFormats) || empty($supportedFormats)) {
                $supportedFormats = $defaultSupportedFormats;
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

                    // Get file format info
                    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                    $isQuickTime = ($extension === 'mov');
                    
                    // Calculate frame position based on settings
                    // Get video duration first
                    $duration = $videoFrameExtractor->getVideoDuration($filePath);
                    
                    // Get file size and basic info for validation and fallback
                    $fileSize = filesize($filePath);
                    $fileInfo = sprintf('%.2f MB', $fileSize / 1048576);
                    
                    // Log detailed file information for debugging
                    $logger->info(sprintf('Processing video file: %s (Format: %s, Size: %s)',
                        $filePath, $extension, $fileInfo));
                    
                    // Enhanced logging and validation for duration calculation
                    if ($duration <= 0) {
                        $logger->warn(sprintf('Could not determine video duration for: %s (%s) - using minimum default (1s)', $filePath, $fileInfo));
                        $duration = 1.0; // Use very small minimum for short videos
                    } else if ($duration < 3.0) {
                        $logger->info(sprintf('Very short video detected: %s (%s) (duration: %.2f seconds)', $filePath, $fileInfo, $duration));
                    } else if ($duration === 60.0 || $duration === 20.0) {
                        // Check if this is likely a default fallback value (60s from old code, 20s from new code)
                        if ($fileSize < 5242880) { // Less than 5MB
                            $logger->warn(sprintf('Default duration (%.1fs) likely inaccurate for very small video: %s (%s)', 
                                $duration, $filePath, $fileInfo));
                            // For very small files with default duration, use a smaller estimate
                            $duration = 3.0;
                        } else if ($fileSize < 20971520) { // Less than 20MB
                            $logger->warn(sprintf('Default duration (%.1fs) may be inaccurate for small video: %s (%s)', 
                                $duration, $filePath, $fileInfo));
                            // For small files with default duration, use a smaller estimate
                            $duration = 10.0;
                        } else {
                            $logger->info(sprintf('Using detected duration (%.1fs) for video: %s (%s)', 
                                $duration, $filePath, $fileInfo));
                        }
                    } else {
                        // For durations that seem reasonably detected
                        $logger->info(sprintf('Detected duration: %.2f seconds for video: %s (%s)', 
                            $duration, $filePath, $fileInfo));
                    }
                    
                    // Sanity check for extremely long durations (could be incorrect detections)
                    if ($duration > 7200.0) { // More than 2 hours
                        $mbPerSecond = $fileSize / (1048576 * $duration);
                        if ($mbPerSecond < 0.01) { // Less than 10KB/s - suspiciously low bitrate
                            $logger->warn(sprintf('Suspiciously long duration detected (%.1f seconds) for file size (%s). Capping at 30 minutes.', 
                                $duration, $fileInfo));
                            $duration = 1800.0; // Cap at 30 minutes
                        }
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
                    
                    // For MOV files, use a slightly longer timeout
                    $timeout = null; // Use default
                    if ($isQuickTime) {
                        $timeout = max(20, min(30, intval($fileSize / 1048576))); // Scale with file size
                        $logger->info(sprintf('Using extended timeout of %d seconds for MOV file', $timeout));
                    }

                    // Extract a frame at the calculated position
                    $framePath = $videoFrameExtractor->extractFrame($filePath, $position, $timeout);
                    
                    // If first extraction fails, try alternative positions
                    if (!$framePath || !file_exists($framePath) || filesize($framePath) <= 0) {
                        $logger->warn(sprintf('First frame extraction failed at position %.2f, trying earlier position', $position));
                        
                        // Try an earlier position (25% of duration)
                        $earlierPosition = $duration * 0.25;
                        $framePath = $videoFrameExtractor->extractFrame($filePath, $earlierPosition, $timeout);
                        
                        // If that also fails, try the beginning of the video
                        if (!$framePath || !file_exists($framePath) || filesize($framePath) <= 0) {
                            $logger->warn(sprintf('Second frame extraction failed at position %.2f, trying start of video', $earlierPosition));
                            $framePath = $videoFrameExtractor->extractFrame($filePath, 1.0, $timeout);
                            
                            // Update position if we succeeded with the fallback
                            if ($framePath && file_exists($framePath) && filesize($framePath) > 0) {
                                $position = 1.0;
                                $logger->info('Successfully extracted frame from start of video');
                            }
                        } else {
                            // Update position if we succeeded with the earlier time
                            $position = $earlierPosition;
                            $logger->info(sprintf('Successfully extracted frame at earlier position: %.2f seconds', $position));
                        }
                    }

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
                    
                    // Store the extracted frame as an Omeka thumbnail
                    try {
                        // Get the necessary services for file handling
                        $tempFileFactory = $this->getServiceLocator()->get('Omeka\File\TempFileFactory');
                        $fileStore = $this->getServiceLocator()->get('Omeka\File\Store');
                        $thumbnailSynchronizer = $this->getServiceLocator()->get('VideoThumbnail\ThumbnailSynchronizer');
                        
                        // Create a properly configured TempFile object
                        $tempFile = $tempFileFactory->build();
                        $tempFile->setSourceName(basename($framePath));
                        $tempFile->setTempPath($framePath);
                        
                        // Get MIME type for the extracted frame (should be image/jpeg)
                        $finfo = new \finfo(FILEINFO_MIME_TYPE);
                        $mediaType = $finfo->file($framePath);
                        $tempFile->setMediaType($mediaType);
                        
                        // Set the storage ID to match the media's for proper association
                        $tempFile->setStorageId($media->getStorageId());
                        
                        // Generate and store thumbnails using Omeka's built-in system
                        $hasThumbnails = $tempFile->storeThumbnails();
                        
                        if ($hasThumbnails) {
                            // Set the hasThumbnails flag on the media entity
                            $media->setHasThumbnails(true);
                            
                            // Update timestamp in media data
                            $mediaData = $media->getData() ?: [];
                            $mediaData['videothumbnail_frame'] = $position;
                            $mediaData['videothumbnail_timestamp'] = time();
                            $media->setData($mediaData);
                            
                            // Ensure the thumbnails are synchronized in the database
                            $thumbnailSynchronizer->updateThumbnailStoragePaths($media);
                            
                            $logger->info(sprintf('Successfully generated thumbnails for media %d', $media->getId()));
                        } else {
                            $logger->warn(sprintf('Failed to generate thumbnails for media %d', $media->getId()));
                        }
                    } catch (\Exception $e) {
                        $logger->err(sprintf('Error storing thumbnails for media %d: %s', $media->getId(), $e->getMessage()));
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
     * Checks if the current peak memory usage exceeds the configured limit.
     *
     * Retrieves the memory usage threshold from settings (default 100 MB, minimum 50 MB), logs current usage, and throws a RuntimeException if the limit is exceeded.
     *
     * @throws \RuntimeException If memory usage exceeds the configured limit.
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
     * Throws an exception to halt execution if the job has been manually stopped.
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
