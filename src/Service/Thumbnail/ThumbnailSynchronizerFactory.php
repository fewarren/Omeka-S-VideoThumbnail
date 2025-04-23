<?php
namespace VideoThumbnail\Service\Thumbnail;

use Interop\Container\ContainerInterface;
use VideoThumbnail\Stdlib\Debug;

class ThumbnailSynchronizerFactory
{
    public function __invoke(ContainerInterface $services)
    {
        try {
            Debug::log(sprintf(
                'Creating ThumbnailSynchronizer with services: %s',
                implode(', ', array_keys($services->getKnownServiceNames()))
            ), __METHOD__);
            
            return new ThumbnailSynchronizer(
                $services->get('Omeka\EntityManager'),
                $services->get('Omeka\File\Store'),
                $services->get('Config')
            );
        } catch (\Exception $e) {
            Debug::logError('Failed to create ThumbnailSynchronizer: ' . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }
}