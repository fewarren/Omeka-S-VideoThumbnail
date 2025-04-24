<?php
namespace VideoThumbnail\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Controller\Admin\VideoThumbnailController;

/**
 * Factory for VideoThumbnail controller
 */
class VideoThumbnailControllerFactory implements FactoryInterface
{
    /**
     * Create an instance of VideoThumbnailController
     *
     * @param ContainerInterface $container
     * @param string $requestedName
     * @param array|null $options
     * @return VideoThumbnailController
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        try {
            // Verify we have required services
            if (!$container->has('Omeka\EntityManager')) {
                error_log('VideoThumbnail: Entity manager service not found');
            }
            
            if (!$container->has('Omeka\Settings')) {
                error_log('VideoThumbnail: Settings service not found');
            }
            
            // Get required services
            $entityManager = $container->get('Omeka\EntityManager');
            $settings = $container->get('Omeka\Settings');
            
            // Get file manager
            $fileManager = null;
            if ($container->has('Omeka\File\Manager')) {
                $fileManager = $container->get('Omeka\File\Manager');
            } elseif ($container->has('Omeka\File\Store')) {
                $fileManager = $container->get('Omeka\File\Store');
            }
            
            // Log service availability
            error_log('VideoThumbnail: Creating controller with services:');
            error_log('VideoThumbnail: - EntityManager: ' . ($entityManager ? 'Yes' : 'No'));
            error_log('VideoThumbnail: - Settings: ' . ($settings ? 'Yes' : 'No'));
            error_log('VideoThumbnail: - FileManager: ' . ($fileManager ? 'Yes' : 'No'));
            
            // Instantiate controller
            $controller = new VideoThumbnailController($entityManager, $fileManager, $container);
            
            // Set settings
            if ($settings) {
                $controller->setSettings($settings);
                
                // Verify settings are working
                $settingsTest = $settings->get('videothumbnail_default_frame', 'NOT SET');
                error_log('VideoThumbnail: Settings test - default_frame: ' . $settingsTest);
            }
            
            return $controller;
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error creating controller: ' . $e->getMessage());
            throw $e;
        }
    }
}