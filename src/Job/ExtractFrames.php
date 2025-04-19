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
                    
                    // Convert percentage to seconds - ensure a minimum frame position
                    $position = ($duration * $defaultFramePercent) / 100;
                    
                    // Ensure the position is within valid range (at least 0.1s from start or end)
                    $position = max(0.1, min($position, $duration - 0.1));
                    
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
                    $tempFileFactory = $this->getServiceLocator()->get('Omeka\File\TempFileFactory');
                    $tempFile = $tempFileFactory->build();
                    
                    // Copy the extracted frame to the temp file
                    if (copy($framePath, $tempFile->getTempPath())) {
                        // Set the storage ID to match the media's
                        $tempFile->setStorageId($media->getStorageId());
                        
                        // Store thumbnails using Omeka's built-in system
                        $hasThumbnails = $tempFile->storeThumbnails();
                        
                        // Set the hasThumbnails flag on the media entity
                        $media->setHasThumbnails($hasThumbnails);
                        
                        // Update the storage locations in the database for the thumbnails
                        $this->updateThumbnailStoragePaths($media);
                        
                        // Clean up temporary files
                        $tempFile->delete();
                        @unlink($framePath);
                        
                        $logger->info(sprintf('Successfully stored thumbnails for media %d', $media->getId()));
                    } else {
                        $logger->warn(sprintf('Failed to copy extracted frame to temp file for media %d', $media->getId()));
                        @unlink($framePath);
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
    
    /**
     * Update the storage paths for thumbnails in the database
     *
     * @param \Omeka\Entity\Media $media The media entity to update
     * @return void
     */
    protected function updateThumbnailStoragePaths($media)
    {
        try {
            $logger = $this->getServiceLocator()->get('Omeka\Logger');
            $logger->info(sprintf('Updating thumbnail storage paths for media %d', $media->getId()));
            
            $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
            $storageId = $media->getStorageId();
            
            // Standard Omeka S thumbnail sizes
            $thumbnailTypes = ['large', 'medium', 'square'];
            
            foreach ($thumbnailTypes as $type) {
                // Construct expected path for this thumbnail type 
                $storagePath = $this->getStoragePath($type, $storageId);
                
                // Update thumbnail info in database if needed
                $logger->info(sprintf('Ensuring thumbnail path exists for %s: %s', $type, $storagePath));
                
                // Force re-association of thumbnail with media
                $this->forceStorageLinkage($media, $type, $storagePath);
            }
            
            // Also make sure original/thumbnails flags are set properly
            $media->setHasThumbnails(true);
            $entityManager->persist($media);
            $entityManager->flush();
            
            $logger->info(sprintf('Thumbnail storage paths updated for media %d', $media->getId()));
        } catch (\Exception $e) {
            $logger = $this->getServiceLocator()->get('Omeka\Logger');
            $logger->err(sprintf('Error updating thumbnail paths: %s', $e->getMessage()));
        }
    }
    
    /**
     * Force re-association of thumbnail with media by updating database thumbnail reference
     *
     * @param \Omeka\Entity\Media $media The media entity
     * @param string $type Thumbnail type (large, medium, square)
     * @param string $storagePath Path where thumbnail is stored
     * @return void
     */
    private function forceStorageLinkage($media, $type, $storagePath)
    {
        $fileManager = $this->getServiceLocator()->get('Omeka\File\Store');
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        
        // Get local path using the fileManager's getLocalPath method
        $localPath = $fileManager->getLocalPath($storagePath);
        
        // Check if the file exists using standard PHP function
        if (file_exists($localPath)) {
            $logger->info(sprintf('Thumbnail file exists for %s: %s', $type, $storagePath));
            
            // Force database to recognize the thumbnail paths
            $mediaId = $media->getId();
            $connection = $entityManager->getConnection();
            
            try {
                // Update the media entity's has_thumbnails flag directly
                $stmt = $connection->prepare('UPDATE media SET has_thumbnails = 1 WHERE id = :id');
                $stmt->bindValue('id', $mediaId, \PDO::PARAM_INT);
                $stmt->execute();
                
                $logger->info(sprintf('Updated has_thumbnails flag for media %d', $mediaId));
            } catch (\Exception $e) {
                $logger->err(sprintf('Database update error: %s', $e->getMessage()));
            }
        } else {
            $logger->warn(sprintf('Thumbnail file not found: %s', $storagePath));
        }
    }
}
