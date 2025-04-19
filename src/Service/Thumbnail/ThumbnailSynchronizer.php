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
    
    /**
     * Constructor
     *
     * @param \Omeka\File\Store $fileManager
     * @param \Doctrine\ORM\EntityManager $entityManager
     */
    public function __construct($fileManager, $entityManager)
    {
        $this->fileManager = $fileManager;
        $this->entityManager = $entityManager;
    }
    
    /**
     * Update the storage paths for thumbnails in the database
     *
     * @param Media $media The media entity to update
     * @return void
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
    
    /**
     * Force re-association of thumbnail with media by updating database thumbnail reference
     *
     * @param Media $media The media entity
     * @param string $type Thumbnail type (large, medium, square)
     * @param string $storagePath Path where thumbnail is stored
     * @return void
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