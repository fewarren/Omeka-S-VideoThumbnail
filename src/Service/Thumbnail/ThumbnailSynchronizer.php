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

    /**
     * Initializes the ThumbnailSynchronizer with required dependencies.
     *
     * @param mixed $fileManager Handles file system operations for thumbnails.
     * @param mixed $entityManager Manages database entity persistence.
     * @param mixed $logger Logs messages and errors.
     * @param mixed $settings Provides configuration settings.
     */
    public function __construct($fileManager, $entityManager, $logger, $settings)
    {
        $this->fileManager = $fileManager;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->settings = $settings;
    }

    /**
     * Verifies the existence of video thumbnails for a media entity and updates its thumbnail status.
     *
     * Checks for valid thumbnails of each configured type in the file system. If the presence of thumbnails differs from the media entity's current `hasThumbnails` flag, updates the flag and persists the change.
     *
     * @param object $media The media entity whose thumbnails are being verified and updated.
     * @return bool True if at least one valid thumbnail exists; false otherwise.
     * @throws \Exception If an error occurs during verification or persistence.
     */
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

    /**
     * Regenerates video thumbnails for the given media entity by extracting a frame and creating new thumbnail images.
     *
     * Extracts a frame from the original video file at a configured or metadata-specified position, generates new thumbnails, updates the media entity to reflect the presence of thumbnails, and persists changes. Cleans up temporary files after processing. Throws an exception if the original file is missing or frame extraction fails.
     *
     * @return bool True if thumbnails were successfully regenerated.
     * @throws \RuntimeException If the original video file is not found or frame extraction fails.
     */
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

    /**
     * Constructs the relative file path for a thumbnail image based on its type and storage ID.
     *
     * @param string $type The thumbnail type (e.g., 'large', 'medium', 'square').
     * @param string $storageId The unique storage identifier for the media.
     * @return string The relative path to the thumbnail image.
     */
    protected function getThumbnailPath($type, $storageId)
    {
        return sprintf('%s/%s.jpg', $type, $storageId);
    }

    /**
     * Checks if the thumbnail at the given path exists and is non-empty.
     *
     * @param string $path Relative path to the thumbnail file.
     * @return bool True if the thumbnail file exists and has a nonzero size, false otherwise.
     */
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

    /**
     * Returns the local file system path for the original video file associated with the given media entity.
     *
     * @param object $media Media entity containing the original filename.
     * @return string Absolute path to the original video file.
     */
    protected function getOriginalFilePath($media)
    {
        $storagePath = sprintf('original/%s', $media->getFilename());
        return $this->fileManager->getLocalPath($storagePath);
    }

    /**
     * Creates a temporary file object from the specified source path.
     *
     * @param string $sourcePath Path to the source file to be wrapped as a temporary file.
     * @return \Omeka\File\TempFile The temporary file object representing the source file.
     */
    protected function createTempFile($sourcePath)
    {
        $tempFile = new \Omeka\File\TempFile;
        $tempFile->setSourceName(basename($sourcePath));
        $tempFile->setTempPath($sourcePath);
        return $tempFile;
    }

    /**
     * Generates and stores thumbnails for the given media using the provided temporary file.
     *
     * Sets the storage ID on the temporary file to match the media entity and attempts to store thumbnails. Throws a RuntimeException if thumbnail storage fails.
     *
     * @throws \RuntimeException If storing thumbnails fails.
     */
    protected function generateThumbnails($tempFile, $media)
    {
        $tempFile->setStorageId($media->getStorageId());
        
        if (!$tempFile->storeThumbnails()) {
            throw new \RuntimeException('Failed to store thumbnails');
        }
    }

    /**
     * Creates and returns a video frame extractor using the configured ffmpeg path.
     *
     * @return \VideoThumbnail\Stdlib\VideoFrameExtractor Instance for extracting frames from video files.
     */
    protected function getVideoFrameExtractor()
    {
        $ffmpegPath = $this->settings->get('videothumbnail_ffmpeg_path');
        return new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
    }
}