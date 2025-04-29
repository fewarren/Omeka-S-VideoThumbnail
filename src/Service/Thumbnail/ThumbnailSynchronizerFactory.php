<?php
namespace VideoThumbnail\Service\Thumbnail;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Exception\ServiceNotFoundException;

class ThumbnailSynchronizerFactory
{
    public function __invoke(ContainerInterface $services)
    {
        try {
            // Get required services without using Debug class
            $fileManager = $services->get('Omeka\File\Store'); // Changed from 'Omeka\File\Manager' to 'Omeka\File\Store'
            $entityManager = $services->get('Omeka\EntityManager');
            $logger = $services->get('Omeka\Logger');
            $settings = $services->get('Omeka\Settings');
            
            // Ensure the service manager is available to the file manager (for service retrieval)
            if (method_exists($fileManager, 'setServiceLocator')) {
                try {
                    $fileManager->setServiceLocator($services);
                } catch (\Exception $e) {
                    error_log('VideoThumbnail: Could not set service locator on file manager: ' . $e->getMessage());
                }
            }

            return new ThumbnailSynchronizer(
                $fileManager,
                $entityManager,
                $logger,
                $settings
            );
        } catch (\Exception $e) {
            // Use error_log instead of Debug class
            error_log('VideoThumbnail: Failed to create ThumbnailSynchronizer: ' . $e->getMessage());
            throw $e;
        }
    }
}