<?php
namespace VideoThumbnail\Service\Thumbnail;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Service\Thumbnail\ThumbnailSynchronizer;

class ThumbnailSynchronizerFactory implements FactoryInterface
{
    /**
     * Creates and returns a new ThumbnailSynchronizer instance with required dependencies.
     *
     * Retrieves the file storage and entity manager services from the container and injects them into the ThumbnailSynchronizer constructor.
     *
     * @return ThumbnailSynchronizer The configured ThumbnailSynchronizer instance.
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $fileManager = $services->get('Omeka\File\Store');
        $entityManager = $services->get('Omeka\EntityManager');
        
        return new ThumbnailSynchronizer($fileManager, $entityManager);
    }
}