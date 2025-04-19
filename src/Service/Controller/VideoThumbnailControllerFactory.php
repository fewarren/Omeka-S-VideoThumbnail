<?php
namespace VideoThumbnail\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Controller\Admin\VideoThumbnailController;

class VideoThumbnailControllerFactory implements FactoryInterface
{
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
        
        $logger = $services->has('Logger') ? $services->get('Logger') : null;
+        $tempFileFactory = $services->has('Omeka\File\TempFileFactory') ? 
+            $services->get('Omeka\File\TempFileFactory') : null;
+        
+        $controller = new VideoThumbnailController(
+            $entityManager,
+            $fileManager,
+            $logger,
+            $tempFileFactory
+        );
        $controller->setSettings($settings);
        
        return $controller;
    }
}
