<?php
namespace VideoThumbnail\Service\Thumbnail;

use Omeka\Entity\Media;
use VideoThumbnail\Stdlib\Debug;

/**
 * Service for synchronizing thumbnails between Omeka's file system and database
 */
class ThumbnailSynchronizer
{
    protected $fileManager;
    protected $entityManager;
    protected $logger;
    protected $settings;
    protected $thumbnailTypes = ['large', 'medium', 'square'];

    public function __construct($fileManager, $entityManager, $logger, $settings)
    {
        $this->fileManager = $fileManager;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->settings = $settings;
    }

    public function updateThumbnailStoragePaths($media)
    {
        try {
            \VideoThumbnail\Stdlib\Debug::logEntry(__METHOD__, ['mediaId' => $media->getId()]);
            
            $storageId = $media->getStorageId();
            $hasThumbnails = false;

            // Check each thumbnail type
            foreach ($this->thumbnailTypes as $type) {
                $path = $this->getThumbnailPath($type, $storageId);
                $exists = $this->validateThumbnail($path);
                
                if ($exists) {
                    $hasThumbnails = true;
                    \VideoThumbnail\Stdlib\Debug::log(
                        sprintf('Found valid thumbnail: %s', $path),
                        __METHOD__
                    );
                } else {
                    \VideoThumbnail\Stdlib\Debug::logWarning(
                        sprintf('Missing thumbnail: %s', $path),
                        __METHOD__
                    );
                }
            }

            // Update media entity
            if ($hasThumbnails !== $media->hasThumbnails()) {
                $media->setHasThumbnails($hasThumbnails);
                $this->entityManager->persist($media);
                $this->entityManager->flush();
                
                \VideoThumbnail\Stdlib\Debug::log(
                    sprintf('Updated hasThumbnails flag to %s for media %d', 
                        $hasThumbnails ? 'true' : 'false',
                        $media->getId()
                    ),
                    __METHOD__
                );
            }

            return $hasThumbnails;

        } catch (\Exception $e) {
            \VideoThumbnail\Stdlib\Debug::logError(
                sprintf('Error updating thumbnail paths: %s', $e->getMessage()),
                __METHOD__
            );
            throw $e;
        }
    }

    public function regenerateThumbnails($media)
    {
        try {
            \VideoThumbnail\Stdlib\Debug::logEntry(__METHOD__, ['mediaId' => $media->getId()]);

            // Get the video file path
            $originalPath = $this->getOriginalFilePath($media);
            if (!file_exists($originalPath)) {
                throw new \RuntimeException('Original video file not found');
            }

            // Get video metadata
            $mediaData = $media->getData() ?: [];
            $framePosition = $mediaData['videothumbnail_frame'] ?? null;
            
            if ($framePosition === null) {
                $framePosition = $this->settings->get('videothumbnail_default_frame', 10);
            }

            // Extract frame
            $extractor = $this->getVideoFrameExtractor();
            $duration = $extractor->getVideoDuration($originalPath);
            $timeInSeconds = ($duration * $framePosition) / 100;
            
            $framePath = $extractor->extractFrame($originalPath, $timeInSeconds);
            if (!$framePath || !file_exists($framePath)) {
                throw new \RuntimeException('Failed to extract video frame');
            }

            try {
                // Generate thumbnails
                $tempFile = $this->createTempFile($framePath);
                $this->generateThumbnails($tempFile, $media);

                // Update media entity
                $media->setHasThumbnails(true);
                $this->entityManager->persist($media);
                $this->entityManager->flush();

                \VideoThumbnail\Stdlib\Debug::log(
                    sprintf('Successfully regenerated thumbnails for media %d', $media->getId()),
                    __METHOD__
                );

                return true;

            } finally {
                // Clean up temporary files
                if (isset($tempFile) && method_exists($tempFile, 'delete')) {
                    $tempFile->delete();
                }
                if (file_exists($framePath)) {
                    @unlink($framePath);
                }
            }

        } catch (\Exception $e) {
            \VideoThumbnail\Stdlib\Debug::logError(
                sprintf('Error regenerating thumbnails: %s', $e->getMessage()),
                __METHOD__
            );
            throw $e;
        }
    }

    protected function getThumbnailPath($type, $storageId)
    {
        return sprintf('%s/%s.jpg', $type, $storageId);
    }

    protected function validateThumbnail($path)
    {
        try {
            $localPath = $this->fileManager->getLocalPath($path);
            return file_exists($localPath) && filesize($localPath) > 0;
        } catch (\Exception $e) {
            \VideoThumbnail\Stdlib\Debug::logError(
                sprintf('Error validating thumbnail %s: %s', $path, $e->getMessage()),
                __METHOD__
            );
            return false;
        }
    }

    protected function getOriginalFilePath($media)
    {
        $storagePath = sprintf('original/%s', $media->getFilename());
        return $this->fileManager->getLocalPath($storagePath);
    }

    protected function createTempFile($sourcePath)
    {
        $tempFile = new \Omeka\File\TempFile;
        $tempFile->setSourceName(basename($sourcePath));
        $tempFile->setTempPath($sourcePath);
        return $tempFile;
    }

    protected function generateThumbnails($tempFile, $media)
    {
        $tempFile->setStorageId($media->getStorageId());
        
        if (!$tempFile->storeThumbnails()) {
            throw new \RuntimeException('Failed to store thumbnails');
        }
    }

    protected function getVideoFrameExtractor()
    {
        $ffmpegPath = $this->settings->get('videothumbnail_ffmpeg_path');
        return new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
    }
}