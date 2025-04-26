<?php
namespace VideoThumbnail\Service\Thumbnail;

use Interop\Container\ContainerInterface;
use VideoThumbnail\Stdlib\Debug;

class ThumbnailSynchronizerFactory
{
    /**
     * Creates and returns a new ThumbnailSynchronizer instance using dependencies from the service container.
     *
     * Retrieves the entity manager, file store, and configuration from the container and injects them into the ThumbnailSynchronizer.
     *
     * @return ThumbnailSynchronizer
     * @throws \Exception If dependencies cannot be retrieved or instantiation fails.
     */
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