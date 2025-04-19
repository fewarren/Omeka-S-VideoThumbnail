<?php
namespace VideoThumbnail\Service\Thumbnail;

use Omeka\Entity\Media;
use VideoThumbnail\Stdlib\Debug;

/**
 * Service for synchronizing thumbnails between Omeka's file system and database
 */
class ThumbnailSynchronizer
{
    /**
     * @var \Omeka\File\Store
     */
    protected $fileManager;
    
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;
    
    /****
     * Initializes the ThumbnailSynchronizer with file storage and database managers.
     */
    public function __construct($fileManager, $entityManager)
    {
        $this->fileManager = $fileManager;
        $this->entityManager = $entityManager;
    }
    
    /**
     * Synchronizes the database records for a media entity to reflect the presence of standard thumbnail files.
     *
     * For the given media entity, checks for the existence of 'large', 'medium', and 'square' thumbnail files and updates the database to indicate their presence. Sets the media's `hasThumbnails` flag to true and persists the change.
     *
     * @param Media $media The media entity whose thumbnail storage paths are to be synchronized.
     */
    public function updateThumbnailStoragePaths(Media $media)
    {
        try {
            Debug::log(sprintf('Updating thumbnail storage paths for media %d', $media->getId()), __METHOD__);
            
            $storageId = $media->getStorageId();
            
            // Standard Omeka S thumbnail sizes
            $thumbnailTypes = ['large', 'medium', 'square'];
            
            foreach ($thumbnailTypes as $type) {
                // Construct expected path for this thumbnail type 
                $storagePath = $this->getStoragePath($type, $storageId, 'jpg');
                
                // Update thumbnail info in database if needed
                Debug::log(sprintf('Ensuring thumbnail path exists for %s: %s', $type, $storagePath), __METHOD__);
                
                // Force re-association of thumbnail with media
                $this->forceStorageLinkage($media, $type, $storagePath);
            }
            
            // Also make sure original/thumbnails flags are set properly
            $media->setHasThumbnails(true);
            $this->entityManager->persist($media);
            
            Debug::log(sprintf('Thumbnail storage paths updated for media %d', $media->getId()), __METHOD__);
        } catch (\Exception $e) {
            Debug::logError(sprintf('Error updating thumbnail paths: %s', $e->getMessage()), __METHOD__);
        }
    }
    
    /****
     * Ensures the database reflects the presence of a thumbnail file for a media entity by updating the `has_thumbnails` flag if the file exists on disk.
     *
     * @param Media $media The media entity to update.
     * @param string $type The thumbnail type (e.g., large, medium, square).
     * @param string $storagePath The storage path of the thumbnail file.
     */
    private function forceStorageLinkage(Media $media, $type, $storagePath)
    {
        // Get the local file path
        $localPath = $this->fileManager->getLocalPath($storagePath);
        
        // Check if the file exists using standard PHP function
        if (file_exists($localPath)) {
            Debug::log(sprintf('Thumbnail file exists for %s: %s', $type, $storagePath), __METHOD__);
            
            // Force database to recognize the thumbnail paths
            $mediaId = $media->getId();
            $connection = $this->entityManager->getConnection();
            
            try {
                // Update the media entity's has_thumbnails flag directly
                $stmt = $connection->prepare('UPDATE media SET has_thumbnails = 1 WHERE id = :id');
                $stmt->bindValue('id', $mediaId, \PDO::PARAM_INT);
                $stmt->execute();
                
                Debug::log(sprintf('Updated has_thumbnails flag for media %d', $mediaId), __METHOD__);
            } catch (\Exception $e) {
                Debug::logError(sprintf('Database update error: %s', $e->getMessage()), __METHOD__);
            }
        } else {
            Debug::logError(sprintf('Thumbnail file not found: %s', $storagePath), __METHOD__);
        }
    }
    
    /****
     * Constructs a storage path for a media file or thumbnail.
     *
     * Returns a path in the format "{prefix}/{storageId}" or "{prefix}/{storageId}.{extension}" if an extension is provided.
     *
     * @param string $prefix Directory or type prefix for the storage path.
     * @param string $storageId Unique identifier for the stored file.
     * @param string $extension Optional file extension (without dot).
     * @return string The resulting storage path.
     */
    protected function getStoragePath(string $prefix, string $storageId, string $extension = ''): string
    {
        return sprintf('%s/%s%s', $prefix, $storageId, strlen($extension) ? '.' . $extension : '');
    }
}