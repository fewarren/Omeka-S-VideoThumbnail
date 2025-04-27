<?php
namespace VideoThumbnail\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Controller\Admin\VideoThumbnailController;
use VideoThumbnail\Stdlib\Debug;
use Laminas\ServiceManager\Exception\ServiceNotFoundException;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;

class VideoThumbnailControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        try {
            // Simplified service retrieval with fallbacks to prevent failures
            $entityManager = null;
            $fileManager = null;
            $settings = null;
            
            // Get EntityManager with fallback
            try {
                if ($container->has('Omeka\EntityManager')) {
                    $entityManager = $container->get('Omeka\EntityManager');
                }
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Could not get EntityManager: ' . $e->getMessage());
            }
            
            // Get FileManager with fallback
            try {
                if ($container->has('Omeka\File\Store')) {
                    $fileManager = $container->get('Omeka\File\Store');
                }
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Could not get File Store: ' . $e->getMessage());
            }
            
            // Get Settings with fallback
            try {
                if ($container->has('Omeka\Settings')) {
                    $settings = $container->get('Omeka\Settings');
                }
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Could not get Settings: ' . $e->getMessage());
            }
            
            // Create and configure controller with what we have
            $controller = new VideoThumbnailController(
                $entityManager,
                $fileManager,
                $container
            );
            
            // Set settings if available
            if ($settings) {
                $controller->setSettings($settings);
            }
            
            return $controller;
            
        } catch (\Exception $e) {
            // Log the error but don't throw exceptions
            error_log('VideoThumbnail: Error creating controller: ' . $e->getMessage());
            
            // Return a minimal controller instance to avoid breaking the system
            return new VideoThumbnailController(null, null, null);
        }
    }
    
    protected function getRequiredServices(ContainerInterface $container)
    {
        $services = [];
        $required = [
            'entityManager' => 'Omeka\EntityManager',
            'fileManager' => 'Omeka\File\Store',
            'settings' => 'Omeka\Settings'
        ];
        
        foreach ($required as $key => $serviceName) {
            Debug::log(sprintf('VideoThumbnailControllerFactory: Checking for service %s', $serviceName), __METHOD__);
            
            if (!$container->has($serviceName)) {
                Debug::logWarning(sprintf('VideoThumbnailControllerFactory: Service %s not found', $serviceName), __METHOD__);
                throw new ServiceNotFoundException(
                    sprintf('Required service %s not found', $serviceName)
                );
            }
            
            try {
                Debug::log(sprintf('VideoThumbnailControllerFactory: Getting service %s', $serviceName), __METHOD__);
                $services[$key] = $container->get($serviceName);
                Debug::log(sprintf('VideoThumbnailControllerFactory: Successfully got service %s', $serviceName), __METHOD__);
            } catch (\Exception $e) {
                Debug::logError(sprintf('VideoThumbnailControllerFactory: Failed to create service %s: %s', 
                    $serviceName, $e->getMessage()), __METHOD__);
                throw new ServiceNotCreatedException(
                    sprintf('Failed to create service %s: %s', $serviceName, $e->getMessage())
                );
            }
        }
        
        return $services;
    }
}
