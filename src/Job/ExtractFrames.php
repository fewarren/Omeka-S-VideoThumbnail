<?php

namespace VideoThumbnail\Job;

use Omeka\Job\AbstractJob;
use Omeka\Entity\Media;

class ExtractFrames extends AbstractJob
{
    protected $mediaId;
    protected $videoFrameExtractor;
    protected $entityManager;
    protected $totalFrames;
    protected $processedFrames = 0;
    protected $logger;

    public function perform()
    {
        try {
            // Get services with proper error handling
            $services = $this->getServiceLocator();
            $this->entityManager = $services->get('Omeka\EntityManager');
            $settings = $services->get('Omeka\Settings');
            $this->logger = $services->get('Omeka\Logger');
            
            // Get supported formats from settings
            $supportedFormats = $settings->get('videothumbnail_supported_formats', [
                'video/mp4', 'video/quicktime', 'video/x-msvideo',
                'video/x-ms-wmv', 'video/x-matroska', 'video/webm',
                'video/3gpp', 'video/3gpp2', 'video/x-flv'
            ]);

            $this->log('Starting frame extraction job');

            // Initialize job properties
            $this->initializeJob();

            // Load media entity
            $media = $this->loadMedia();
            if (!$media) {
                throw new \Omeka\Job\Exception\RuntimeException(
                    "Media entity not found for ID: " . $this->mediaId
                );
            }

            $this->log("Media entity loaded: " . $media->getId());

            // Check if media type is supported
            $mediaType = $media->getMediaType();
            if (!in_array($mediaType, $supportedFormats)) {
                $this->log("Media type '{$mediaType}' is not supported. Skipping frame extraction.");
                return;
            }

            // Get media file path
            $fileStore = $services->get('Omeka\File\Store');
            $mediaPath = $fileStore->getLocalPath($media->getStorageId(), $media->getExtension());
            
            if (!$mediaPath || !file_exists($mediaPath)) {
                throw new \Omeka\Job\Exception\RuntimeException(
                    "Media file path not found or invalid for media ID: " . $media->getId()
                );
            }

            // Extract frames
            $this->log("Starting frame extraction process...");
            $frames = $this->videoFrameExtractor->extractFrames($mediaPath);
            $this->totalFrames = $this->videoFrameExtractor->getTotalFrames();

            // Save frame data
            if (!empty($frames)) {
                $this->saveFrameData($media, $frames);
            }

            // Clean up and force garbage collection
            unset($frames);
            if (gc_enabled()) {
                gc_collect_cycles();
            }

        } catch (\Exception $e) {
            $this->log("Error during frame extraction: " . $e->getMessage(), 'error');
            throw new \Omeka\Job\Exception\RuntimeException(
                "Error during frame extraction: " . $e->getMessage(), 
                0, 
                $e
            );
        }
    }

    protected function storeThumbnail($media, $framePath)
    {
        try {
            $services = $this->getServiceLocator();
            $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
            $tempFile = $tempFileFactory->build();
            
            if (!copy($framePath, $tempFile->getTempPath())) {
                $this->log("Failed to copy frame to temp location", 'error');
                return null;
            }

            $tempFile->setStorageId($media->getStorageId());
            if (!$tempFile->storeThumbnails()) {
                $this->log("Failed to store thumbnails", 'error');
                return null;
            }

            // Update media thumbnails flag and data
            $media->setHasThumbnails(true);
            $mediaData = $media->getData() ?: [];
            $mediaData['videothumbnail_frame_percentage'] = $this->args['frame_position'] ?? 50;
            $media->setData($mediaData);

            return $tempFile;

        } catch (\Exception $e) {
            $this->log("Error storing thumbnail: " . $e->getMessage(), 'error');
            return null;
        }
    }

    protected function initializeJob()
    {
        $services = $this->getServiceLocator();
        
        $this->mediaId = $this->getArg('media_id');
        $this->videoFrameExtractor = $services->get('VideoThumbnail\Stdlib\VideoFrameExtractor');
        $this->entityManager = $services->get('Omeka\EntityManager');
        
        // Set memory limit for the job if configured
        $memoryLimit = $this->getArg('memory_limit');
        if ($memoryLimit) {
            ini_set('memory_limit', $memoryLimit);
        }
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

    protected function onFrameExtracted($frameIndex)
    {
        $this->processedFrames++;
        $progress = ($this->totalFrames > 0) ? ($this->processedFrames / $this->totalFrames) * 100 : 0;
        
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
        if ($status !== $job::STATUS_IN_PROGRESS || $this->shouldUpdateProgress($percent)) {
            $job->setStatus($job::STATUS_IN_PROGRESS);
            $this->entityManager->persist($job);
            $this->entityManager->flush();
        }
    }

    protected function shouldUpdateProgress($newPercent)
    {
        static $lastPercent = 0;
        static $minimumChange = 5;

        if (abs($newPercent - $lastPercent) >= $minimumChange) {
            $lastPercent = $newPercent;
            return true;
        }
        return false;
    }

    protected function log($message, $level = 'info')
    {
        if ($this->logger) {
            switch ($level) {
                case 'error':
                    $this->logger->err($message);
                    break;
                case 'warn':
                    $this->logger->warn($message);
                    break;
                default:
                    $this->logger->info($message);
            }
        }
        error_log('VideoThumbnail Job: ' . $message);
    }
}
