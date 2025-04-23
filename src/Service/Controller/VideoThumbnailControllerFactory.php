<?php
namespace VideoThumbnail\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Controller\Admin\VideoThumbnailController;
use Laminas\ServiceManager\Exception\ServiceNotFoundException;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;

class VideoThumbnailControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        error_log('VideoThumbnailControllerFactory: Starting controller creation');
        
        try {
            // Get required services with validation
            error_log('VideoThumbnailControllerFactory: Getting required services');
            $services = $this->getRequiredServices($container);
            
            error_log('VideoThumbnailControllerFactory: Creating controller instance');
            // Create and configure controller
            $controller = new VideoThumbnailController(
                $services['entityManager'],
                $services['fileManager'],
                $container
            );
            
            error_log('VideoThumbnailControllerFactory: Setting controller settings');
            // Set settings
            $controller->setSettings($services['settings']);
            
            error_log('VideoThumbnailControllerFactory: Controller created successfully');
            return $controller;
            
        } catch (\Exception $e) {
            // Log the detailed error
            error_log(sprintf(
                'VideoThumbnailControllerFactory detailed error: %s\nTrace: %s',
                $e->getMessage(),
                $e->getTraceAsString()
            ));
            
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
            error_log(sprintf('VideoThumbnailControllerFactory: Checking for service %s', $serviceName));
            
            if (!$container->has($serviceName)) {
                error_log(sprintf('VideoThumbnailControllerFactory: Service %s not found', $serviceName));
                throw new ServiceNotFoundException(
                    sprintf('Required service %s not found', $serviceName)
                );
            }
            
            try {
                error_log(sprintf('VideoThumbnailControllerFactory: Getting service %s', $serviceName));
                $services[$key] = $container->get($serviceName);
                error_log(sprintf('VideoThumbnailControllerFactory: Successfully got service %s', $serviceName));
            } catch (\Exception $e) {
                error_log(sprintf('VideoThumbnailControllerFactory: Failed to create service %s: %s', 
                    $serviceName, $e->getMessage()));
                throw new ServiceNotCreatedException(
                    sprintf('Failed to create service %s: %s', $serviceName, $e->getMessage())
                );
            }
        }
        
        return $services;
    }
}
