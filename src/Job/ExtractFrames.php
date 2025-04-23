<?php

namespace VideoThumbnail\Job;

use Omeka\Job\AbstractJob;
use VideoThumbnail\Stdlib\Debug;
use Omeka\Entity\Media;

class ExtractFrames extends AbstractJob
{
    protected $mediaId;
    protected $videoFrameExtractor;
    protected $entityManager;
    protected $totalFrames;
    protected $processedFrames = 0;

    public function perform()
    {
        Debug::logEntry(__METHOD__, ['job_id' => $this->job->getId()]);

        try {
            // Get services
            $services = $this->getServiceLocator();
            $entityManager = $services->get('Omeka\EntityManager');
            $api = $services->get('Omeka\ApiManager');
            $settings = $services->get('Omeka\Settings');

            // Get video frame extractor
            $extractor = $services->get('VideoThumbnail\VideoFrameExtractor');
            $fileStore = $services->get('Omeka\File\Store');

            // Get supported formats
            $supportedFormats = $settings->get('videothumbnail_supported_formats', [
                'video/mp4', 'video/quicktime', 'video/x-msvideo',
                'video/x-ms-wmv', 'video/x-matroska', 'video/webm',
                'video/3gpp', 'video/3gpp2', 'video/x-flv'
            ]);

            Debug::log("Starting batch processing with supported formats: " . implode(', ', $supportedFormats), __METHOD__);

            // Query for all video media
            $qb = $entityManager->createQueryBuilder();
            $qb->select('m')
               ->from('Omeka\Entity\Media', 'm')
               ->where($qb->expr()->in('m.mediaType', ':formats'))
               ->setParameter('formats', $supportedFormats);

            $query = $qb->getQuery();
            $totalVideos = count($query->getResult());
            Debug::log("Found {$totalVideos} videos to process", __METHOD__);

            $processedCount = 0;
            $failedCount = 0;
            $skippedCount = 0;

            foreach ($query->iterate() as $row) {
                $media = $row[0];
                $processedCount++;

                Debug::log(sprintf(
                    "Processing video %d/%d: %s (ID: %d)", 
                    $processedCount, 
                    $totalVideos, 
                    $media->getFilename(),
                    $media->getId()
                ), __METHOD__);

                try {
                    // Update job progress
                    $this->job->setProgress($processedCount / $totalVideos);
                    $entityManager->flush();

                    // Get file path
                    $storagePath = sprintf('original/%s', $media->getFilename());
                    $filePath = $fileStore->getLocalPath($storagePath);

                    if (!file_exists($filePath)) {
                        Debug::logWarning(sprintf(
                            "File not found for video ID %d: %s", 
                            $media->getId(), 
                            $filePath
                        ), __METHOD__);
                        $skippedCount++;
                        continue;
                    }

                    // Get video duration
                    $duration = $extractor->getVideoDuration($filePath);
                    if ($duration <= 0) {
                        Debug::logWarning(sprintf(
                            "Could not determine duration for video ID %d", 
                            $media->getId()
                        ), __METHOD__);
                        $duration = 5.0; // fallback duration
                    }

                    // Calculate frame position
                    $position = $this->args['frame_position'] ?? 50;
                    $timeInSeconds = ($duration * $position) / 100;
                    Debug::log(sprintf(
                        "Extracting frame at position %d%% (%.2f seconds) for video ID %d",
                        $position,
                        $timeInSeconds,
                        $media->getId()
                    ), __METHOD__);

                    // Extract frame
                    $framePath = $extractor->extractFrame($filePath, $timeInSeconds);
                    if (!$framePath) {
                        Debug::logWarning(sprintf(
                            "Frame extraction failed for video ID %d",
                            $media->getId()
                        ), __METHOD__);
                        $failedCount++;
                        continue;
                    }

                    // Create and store thumbnails
                    $tempFile = $this->storeThumbnail($media, $framePath);
                    if ($tempFile) {
                        Debug::log(sprintf(
                            "Successfully updated thumbnails for video ID %d",
                            $media->getId()
                        ), __METHOD__);
                    } else {
                        Debug::logWarning(sprintf(
                            "Failed to store thumbnails for video ID %d",
                            $media->getId()
                        ), __METHOD__);
                        $failedCount++;
                    }

                    // Clean up
                    @unlink($framePath);
                    $entityManager->clear();

                } catch (\Exception $e) {
                    Debug::logError(sprintf(
                        "Error processing video ID %d: %s",
                        $media->getId(),
                        $e->getMessage()
                    ), __METHOD__, $e);
                    $failedCount++;
                }
            }

            $summary = sprintf(
                "Job completed. Processed: %d, Failed: %d, Skipped: %d",
                $processedCount,
                $failedCount,
                $skippedCount
            );
            Debug::logExit(__METHOD__, ['summary' => $summary]);

        } catch (\Exception $e) {
            Debug::logError("Job failed: " . $e->getMessage(), __METHOD__, $e);
            throw $e;
        }
    }

    protected function storeThumbnail($media, $framePath)
    {
        Debug::logEntry(__METHOD__, ['media_id' => $media->getId()]);
        
        try {
            $services = $this->getServiceLocator();
            $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
            $tempFile = $tempFileFactory->build();
            
            if (!copy($framePath, $tempFile->getTempPath())) {
                Debug::logError("Failed to copy frame to temp location", __METHOD__);
                return null;
            }

            $tempFile->setStorageId($media->getStorageId());
            if (!$tempFile->storeThumbnails()) {
                Debug::logError("Failed to store thumbnails", __METHOD__);
                return null;
            }

            // Update media thumbnails flag and data
            $media->setHasThumbnails(true);
            $mediaData = $media->getData() ?: [];
            $mediaData['videothumbnail_frame_percentage'] = $this->args['frame_position'] ?? 50;
            $media->setData($mediaData);

            Debug::logExit(__METHOD__, ['success' => true]);
            return $tempFile;

        } catch (\Exception $e) {
            Debug::logError("Error storing thumbnail: " . $e->getMessage(), __METHOD__, $e);
            return null;
        }
    }

    protected function initializeJob()
    {
        $services = $this->getServiceLocator();
        
        $this->mediaId = $this->getArg('media_id');
        $this->videoFrameExtractor = $services->get('VideoThumbnail\VideoFrameExtractor');
        $this->entityManager = $services->get('Omeka\EntityManager');
    }

    protected function loadMedia()
    {
        return $this->entityManager->find(Media::class, $this->mediaId);
    }

    protected function saveFrameData(Media $media, array $frames)
    {
        $frameData = [];
        foreach ($frames as $frame) {
            $frameData[] = [
                'path' => $frame['path'],
                'timestamp' => $frame['timestamp'],
                'index' => $frame['index']
            ];
        }

        // Store frame data in media
        $mediaData = $media->getData() ?: [];
        $mediaData['video_frames'] = $frameData;
        $media->setData($mediaData);
        
        $this->entityManager->persist($media);
        $this->entityManager->flush();
    }

    public function onFrameExtracted($frameIndex)
    {
        $this->processedFrames++;
        $progress = ($this->processedFrames / $this->totalFrames) * 100;
        
        $this->reportProgress(
            $progress,
            sprintf('Extracted frame %d of %d', $this->processedFrames, $this->totalFrames)
        );
    }

    protected function reportProgress($percent, $message)
    {
        $job = $this->job;
        $status = $job->getStatus();

        // Only update if status has changed or progress has changed significantly
        if ($status !== $message || abs($job->getData('progress', 0) - $percent) >= 1) {
            $job->setStatus($message);
            $job->setData('progress', $percent);
            
            $this->entityManager->persist($job);
            $this->entityManager->flush();

            Debug::log(sprintf(
                'Job %d progress: %d%% - %s',
                $job->getId(),
                $percent,
                $message
            ), __METHOD__);
        }
    }
}
