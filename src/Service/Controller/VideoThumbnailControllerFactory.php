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
            // Get required services
            $services = $this->getRequiredServices($container);
            
            // Create controller with required dependencies
            $controller = new VideoThumbnailController(
                $services['entityManager'],
                $services['fileManager'],
                $container
            );
            
            // Set settings service
            $controller->setSettings($services['settings']);
            
            return $controller;
            
        } catch (ServiceNotFoundException $e) {
            error_log('VideoThumbnailControllerFactory: Required service not found: ' . $e->getMessage());
            throw $e;
        } catch (ServiceNotCreatedException $e) {
            error_log('VideoThumbnailControllerFactory: Service creation failed: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            error_log('VideoThumbnailControllerFactory: Unexpected error: ' . $e->getMessage());
            throw new ServiceNotCreatedException($e->getMessage(), 0, $e);
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
