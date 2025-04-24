<?php
namespace VideoThumbnail\Api\Adapter;

use Omeka\Api\Adapter\AbstractAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

/**
 * Simple API adapter for VideoThumbnail module
 */
class VideoThumbnailAdapter extends AbstractAdapter
{
    /**
     * Create entity
     */
    public function create(Request $request)
    {
        // This adapter only provides read operations
        return false;
    }
    
    /**
     * Read an entity - not applicable for this adapter
     */
    public function read(Request $request)
    {
        // This adapter only provides batch operations
        return false;
    }
    
    /**
     * Update entity
     */
    public function update(Request $request)
    {
        // This adapter only provides read operations
        return false;
    }
    
    /**
     * Delete entity
     */
    public function delete(Request $request)
    {
        // This adapter only provides read operations
        return false;
    }
    
    /**
     * Search for entities
     */
    public function search(Request $request)
    {
        // Only search for videos
        $query = $request->getContent();
        $response = $this->searchVideos($query);
        
        return $response;
    }
    
    /**
     * Search for videos
     */
    protected function searchVideos($query)
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        
        // Get supported formats
        $settings = $services->get('Omeka\Settings');
        $supportedFormats = $settings->get('videothumbnail_supported_formats', [
            'video/mp4',
            'video/quicktime',
            'video/x-msvideo'
        ]);
        
        // Create query builder
        $qb = $entityManager->createQueryBuilder();
        $qb->select('m')
           ->from('Omeka\Entity\Media', 'm')
           ->where($qb->expr()->in('m.mediaType', ':formats'))
           ->orderBy('m.id', 'DESC')
           ->setParameter('formats', $supportedFormats);
        
        // Execute query
        $result = $qb->getQuery()->getResult();
        
        return $result;
    }
    
    /**
     * hydrate entity data
     */
    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore)
    {
        // Not used for this adapter
    }
}