<?php
namespace VideoThumbnail\Service\Thumbnail;

use Interop\Container\ContainerInterface;
use VideoThumbnail\Stdlib\Debug;

class ThumbnailSynchronizerFactory
{
    public function __invoke(ContainerInterface $services)
    {
        try {
            Debug::log('Creating ThumbnailSynchronizer', __METHOD__);
            
            return new ThumbnailSynchronizer(
                $services->get('Omeka\File\Store'), // fileManager
                $services->get('Omeka\EntityManager'), // entityManager 
                $services->get('Omeka\Logger'), // logger
                $services->get('Omeka\Settings') // settings
            );
        } catch (\Exception $e) {
            Debug::logError('Failed to create ThumbnailSynchronizer: ' . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }
}