<?php
namespace VideoThumbnail\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Controller\Admin\VideoThumbnailController;

class VideoThumbnailControllerFactory implements FactoryInterface
{
    /**
     * Creates and configures a VideoThumbnailController instance.
     *
     * Retrieves required services from the container, including the entity manager, settings, and a file manager (if available), and injects them into the controller. If no file manager service is found, the controller is created with a null file manager.
     *
     * @param ContainerInterface $services The service container.
     * @param string $requestedName The name of the requested controller.
     * @param array|null $options Optional configuration options.
     * @return VideoThumbnailController The configured controller instance.
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $entityManager = $services->get('Omeka\EntityManager');
        $settings = $services->get('Omeka\Settings');
        
        // Get file manager using a more robust approach - prioritize the primary manager service
        $fileManager = null;
        if ($services->has('Omeka\File\Manager')) {
            $fileManager = $services->get('Omeka\File\Manager');
        } else {
            $fileManagerServices = [
                'Omeka\File\Store\Manager',
                'Omeka\File\TempFileFactory',
            ];
            
            foreach ($fileManagerServices as $service) {
                if ($services->has($service)) {
                    $fileManager = $services->get($service);
                    break;
                }
            }
        }
        
        if (!$fileManager) {
            // Log error but don't throw exception to prevent fatal error
            error_log('VideoThumbnail: Could not locate file manager service');
            // Create controller anyway with null file manager
            $fileManager = null;
        }
        
        $controller = new VideoThumbnailController(
            $entityManager,
            $fileManager,
            $services
        );
        $controller->setSettings($settings);
        
        return $controller;
    }
}
