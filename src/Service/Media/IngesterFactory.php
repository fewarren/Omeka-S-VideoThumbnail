<?php
namespace VideoThumbnail\Service\Media;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Media\Ingester\VideoThumbnail;

class IngesterFactory implements FactoryInterface
{
    /**
     * Creates and returns a configured VideoThumbnail ingester instance.
     *
     * Retrieves required dependencies from the service container and constructs a VideoThumbnail object for handling video thumbnail ingestion.
     *
     * @param ContainerInterface $services Service container providing dependencies.
     * @param string $requestedName The requested service name.
     * @param array|null $options Optional configuration options.
     * @return VideoThumbnail Configured VideoThumbnail ingester instance.
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $settings = $services->get('Omeka\Settings');
        $uploader = $services->get('Omeka\File\Uploader');
        $fileStore = $services->get('Omeka\File\Store');
        $entityManager = $services->get('Omeka\EntityManager');
        $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor(
            $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg')
        );
        
        return new VideoThumbnail(
            $tempFileFactory,
            $settings,
            $videoFrameExtractor,
            $uploader,
            $fileStore,
            $entityManager
        );
    }
}
