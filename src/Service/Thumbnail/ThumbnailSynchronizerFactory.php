<?php
namespace VideoThumbnail\Service\Thumbnail;

use Interop\Container\ContainerInterface;
use VideoThumbnail\Stdlib\Debug;

class ThumbnailSynchronizerFactory
{
    public function __invoke(ContainerInterface $services)
    {
        try {
            Debug::log('Creating ThumbnailSynchronizer service', __METHOD__);
            
            // Make sure we have the required services
            if (!$services->has('Omeka\File\Manager')) {
                throw new \RuntimeException('Omeka\File\Manager service not found');
            }
            if (!$services->has('Omeka\EntityManager')) {
                throw new \RuntimeException('Omeka\EntityManager service not found');
            }
            if (!$services->has('Omeka\Logger')) {
                throw new \RuntimeException('Omeka\Logger service not found');
            }
            if (!$services->has('Omeka\Settings')) {
                throw new \RuntimeException('Omeka\Settings service not found');
            }
            
            // Get services required by ThumbnailSynchronizer constructor
            // Order matters! Must match the constructor parameters in ThumbnailSynchronizer
            return new ThumbnailSynchronizer(
                $services->get('Omeka\File\Manager'),   // fileManager
                $services->get('Omeka\EntityManager'),  // entityManager
                $services->get('Omeka\Logger'),         // logger
                $services->get('Omeka\Settings')        // settings
            );
        } catch (\Exception $e) {
            Debug::logError('Failed to create ThumbnailSynchronizer: ' . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }
}