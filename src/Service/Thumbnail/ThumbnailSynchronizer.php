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

    /**
     * Generate thumbnails - handles both FileManager and fallback to manual creation
     *
     * @param \Omeka\File\TempFile $tempFile
     * @param \Omeka\Entity\Media $media
     * @throws \RuntimeException
     */
    protected function generateThumbnails($tempFile, $media)
    {
        $success = false;
        
        // Try to get proper file manager if available through service manager
        try {
            // Get service manager through fileManager if available
            if (method_exists($this->fileManager, 'getServiceLocator')) {
                $serviceLocator = $this->fileManager->getServiceLocator();
                
                if ($serviceLocator && $serviceLocator->has('Omeka\File\Manager')) {
                    $fileManager = $serviceLocator->get('Omeka\File\Manager');
                    $success = $fileManager->storeThumbnails(
                        $tempFile->getTempPath(),
                        $media->getStorageId()
                    );
                }
            }
        } catch (\Exception $e) {
            \VideoThumbnail\Stdlib\Debug::logError(
                sprintf('Error using FileManager: %s - falling back to manual method', $e->getMessage()),
                __METHOD__
            );
        }
        
        // If FileManager failed or wasn't available, use manual method
        if (!$success) {
            $success = $this->manuallyCreateThumbnails(
                $tempFile->getTempPath(),
                $media->getStorageId()
            );
        }
        
        if (!$success) {
            throw new \RuntimeException('Failed to store thumbnails');
        }
    }
    
    /**
     * Manually create thumbnails as a fallback when FileManager is not available
     * 
     * @param string $sourcePath Path to source image
     * @param string $storageId Storage ID for the media
     * @return bool True if successful
     */
    protected function manuallyCreateThumbnails($sourcePath, $storageId)
    {
        $thumbnailTypes = ['large', 'medium', 'square'];
        $thumbnailSizes = [
            'large' => [800, 800],
            'medium' => [400, 400],
            'square' => [200, 200, true] // Square thumbnail
        ];
        
        try {
            // Make sure image is readable
            if (!is_readable($sourcePath)) {
                \VideoThumbnail\Stdlib\Debug::logError(
                    sprintf('Source image not readable: %s', $sourcePath),
                    __METHOD__
                );
                return false;
            }
            
            // Use GD to resize the image
            $sourceImg = imagecreatefromjpeg($sourcePath);
            if (!$sourceImg) {
                \VideoThumbnail\Stdlib\Debug::logError(
                    'Failed to create image from source',
                    __METHOD__
                );
                return false;
            }
            
            $sourceDimensions = [imagesx($sourceImg), imagesy($sourceImg)];
            $success = true;
            
            foreach ($thumbnailTypes as $type) {
                $width = $thumbnailSizes[$type][0];
                $height = $thumbnailSizes[$type][1];
                $square = isset($thumbnailSizes[$type][2]) ? $thumbnailSizes[$type][2] : false;
                
                // Calculate dimensions for resize
                $sourceRatio = $sourceDimensions[0] / $sourceDimensions[1];
                
                if ($square) {
                    // For square thumbnails, crop to square
                    $targetWidth = $targetHeight = $width;
                    
                    // Create new image
                    $targetImg = imagecreatetruecolor($targetWidth, $targetHeight);
                    
                    // Calculate crop dimensions
                    if ($sourceRatio > 1) {
                        $cropHeight = $sourceDimensions[1];
                        $cropWidth = $cropHeight;
                        $cropX = floor(($sourceDimensions[0] - $cropWidth) / 2);
                        $cropY = 0;
                    } else {
                        $cropWidth = $sourceDimensions[0];
                        $cropHeight = $cropWidth;
                        $cropX = 0;
                        $cropY = floor(($sourceDimensions[1] - $cropHeight) / 2);
                    }
                    
                    // Resize and crop
                    imagecopyresampled(
                        $targetImg, $sourceImg,
                        0, 0, $cropX, $cropY,
                        $targetWidth, $targetHeight, $cropWidth, $cropHeight
                    );
                } else {
                    // For non-square thumbnails, maintain aspect ratio
                    if ($sourceRatio > ($width / $height)) {
                        $targetWidth = $width;
                        $targetHeight = round($width / $sourceRatio);
                    } else {
                        $targetHeight = $height;
                        $targetWidth = round($height * $sourceRatio);
                    }
                    
                    // Create target image
                    $targetImg = imagecreatetruecolor($targetWidth, $targetHeight);
                    
                    // Resize
                    imagecopyresampled(
                        $targetImg, $sourceImg,
                        0, 0, 0, 0,
                        $targetWidth, $targetHeight, $sourceDimensions[0], $sourceDimensions[1]
                    );
                }
                
                // Create temp file for the resized image
                $tempResized = tempnam(sys_get_temp_dir(), 'thumb');
                imagejpeg($targetImg, $tempResized, 85);
                imagedestroy($targetImg);
                
                // Define storage path
                $storagePath = sprintf('%s/%s.jpg', $type, $storageId);
                
                // Store using file storage
                try {
                    $this->fileManager->put($tempResized, $storagePath);
                    \VideoThumbnail\Stdlib\Debug::log(
                        sprintf('Stored %s thumbnail at %s', $type, $storagePath),
                        __METHOD__
                    );
                } catch (\Exception $e) {
                    \VideoThumbnail\Stdlib\Debug::logError(
                        sprintf('Failed to store %s thumbnail: %s', $type, $e->getMessage()),
                        __METHOD__
                    );
                    $success = false;
                }
                
                // Cleanup
                @unlink($tempResized);
            }
            
            imagedestroy($sourceImg);
            return $success;
            
        } catch (\Exception $e) {
            \VideoThumbnail\Stdlib\Debug::logError(
                sprintf('Manual thumbnail creation error: %s', $e->getMessage()),
                __METHOD__
            );
            return false;
        }
    }

    /**
     * Get the VideoFrameExtractor service from the service manager
     * 
     * @return \VideoThumbnail\Stdlib\VideoFrameExtractor
     */
    protected function getVideoFrameExtractor()
    {
        // Access the service manager (we have access through the file manager)
        if (method_exists($this->fileManager, 'getServiceLocator')) {
            $serviceLocator = $this->fileManager->getServiceLocator();
            if ($serviceLocator && $serviceLocator->has('VideoThumbnail\Stdlib\VideoFrameExtractor')) {
                return $serviceLocator->get('VideoThumbnail\Stdlib\VideoFrameExtractor');
            }
        }
        
        // Fallback to direct instantiation only if absolutely necessary
        $ffmpegPath = $this->settings->get('videothumbnail_ffmpeg_path');
        \VideoThumbnail\Stdlib\Debug::logWarning('Creating VideoFrameExtractor without service manager', __METHOD__);
        return new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
    }
}