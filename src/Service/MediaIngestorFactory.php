<?php
namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Media\Ingester\VideoThumbnail;

class MediaIngestorFactory implements FactoryInterface
{
    /**
     * Creates and returns a new VideoThumbnail ingester with required dependencies.
     *
     * Retrieves the TempFileFactory and Settings services from the container and injects them into the VideoThumbnail ingester.
     *
     * @return VideoThumbnail Instance of the VideoThumbnail ingester.
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $settings = $services->get('Omeka\Settings');
        
        return new VideoThumbnail(
            $tempFileFactory,
            $settings
        );
    }
}
