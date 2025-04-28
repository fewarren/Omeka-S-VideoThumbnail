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
            // Get required services
            $services = $this->getRequiredServices($container);
            
            Debug::log('VideoThumbnailControllerFactory: Creating controller instance', __METHOD__);
            
            // Create controller with required dependencies
            $controller = new VideoThumbnailController(
                $services['entityManager'],
                $services['fileManager'],
                $container
            );
            
            // Set settings service
            $controller->setSettings($services['settings']);
            
            Debug::log('VideoThumbnailControllerFactory: Controller created successfully', __METHOD__);
            return $controller;
            
        } catch (ServiceNotFoundException $e) {
            Debug::logError('VideoThumbnailControllerFactory: Required service not found: ' . $e->getMessage(), __METHOD__);
            throw $e; // Let Laminas handle the service not found error
        } catch (ServiceNotCreatedException $e) {
            Debug::logError('VideoThumbnailControllerFactory: Service creation failed: ' . $e->getMessage(), __METHOD__);
            throw $e; // Let Laminas handle the service creation error
        } catch (\Exception $e) {
            Debug::logError('VideoThumbnailControllerFactory: Unexpected error: ' . $e->getMessage(), __METHOD__);
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
            Debug::log(sprintf('VideoThumbnailControllerFactory: Checking for service %s', $serviceName), __METHOD__);
            
            if (!$container->has($serviceName)) {
                throw new ServiceNotFoundException(
                    sprintf('Required service %s not found', $serviceName)
                );
            }
            
            try {
                Debug::log(sprintf('VideoThumbnailControllerFactory: Getting service %s', $serviceName), __METHOD__);
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
