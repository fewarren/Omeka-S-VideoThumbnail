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
        Debug::log('VideoThumbnailControllerFactory: Starting controller creation', __METHOD__);
        
        try {
            // Get required services with validation
            Debug::log('VideoThumbnailControllerFactory: Getting required services', __METHOD__);
            $services = $this->getRequiredServices($container);
            
            Debug::log('VideoThumbnailControllerFactory: Creating controller instance', __METHOD__);
            // Create and configure controller
            $controller = new VideoThumbnailController(
                $services['entityManager'],
                $services['fileManager'],
                $container
            );
            
            Debug::log('VideoThumbnailControllerFactory: Setting controller settings', __METHOD__);
            // Set settings
            $controller->setSettings($services['settings']);
            
            Debug::log('VideoThumbnailControllerFactory: Controller created successfully', __METHOD__);
            return $controller;
            
        } catch (\Exception $e) {
            // Log the detailed error
            Debug::logError(sprintf(
                'VideoThumbnailControllerFactory detailed error: %s\nTrace: %s',
                $e->getMessage(),
                $e->getTraceAsString()
            ), __METHOD__);
            
            // Re-throw as service not found exception
            throw new ServiceNotFoundException(
                'VideoThumbnailController',
                $e->getMessage()
            );
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
