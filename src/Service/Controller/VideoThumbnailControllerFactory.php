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
        try {
            // Get required services with validation
            $services = $this->getRequiredServices($container);
            
            // Create and configure controller
            $controller = new VideoThumbnailController(
                $services['entityManager'],
                $services['fileManager'],
                $container
            );
            
            // Set settings
            $controller->setSettings($services['settings']);
            
            return $controller;
            
        } catch (\Exception $e) {
            // Log the error
            error_log(sprintf(
                'VideoThumbnailControllerFactory error: %s',
                $e->getMessage()
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
            if (!$container->has($serviceName)) {
                throw new ServiceNotFoundException(
                    sprintf('Required service %s not found', $serviceName)
                );
            }
            
            try {
                $services[$key] = $container->get($serviceName);
            } catch (\Exception $e) {
                throw new ServiceNotCreatedException(
                    sprintf('Failed to create service %s: %s', $serviceName, $e->getMessage())
                );
            }
        }
        
        return $services;
    }
}
