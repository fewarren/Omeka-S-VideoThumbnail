<?php
namespace VideoThumbnail\Service\Thumbnail;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Service\Thumbnail\ThumbnailSynchronizer;

class ThumbnailSynchronizerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $fileManager = $services->get('Omeka\File\Store');
        $entityManager = $services->get('Omeka\EntityManager');
        
        return new ThumbnailSynchronizer($fileManager, $entityManager);
    }
}