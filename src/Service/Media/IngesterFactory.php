<?php
namespace VideoThumbnail\Service\Media;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Media\Ingester\VideoThumbnail;
use VideoThumbnail\Service\VideoFrameExtractorFactory; // Correct namespace
use Laminas\Log\LoggerInterface; // Import LoggerInterface

class IngesterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $settings = $services->get('Omeka\Settings');
        $uploader = $services->get('Omeka\File\Uploader');
        $fileStore = $services->get('Omeka\File\Store');
        $entityManager = $services->get('Omeka\EntityManager');
        $logger = $services->get('Omeka\Logger'); // Get logger

        // Get VideoFrameExtractor via its factory to ensure logger is injected
        $videoFrameExtractor = $services->get('VideoThumbnail\Stdlib\VideoFrameExtractor');
        
        return new VideoThumbnail(
            $tempFileFactory,
            $settings,
            $videoFrameExtractor, // Pass the correctly instantiated extractor
            $uploader,
            $fileStore,
            $entityManager,
            $logger // Pass logger to Ingester
        );
    }
}
