<?php
namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;
use VideoThumbnail\Stdlib\Debug;

/**
 * Delegator factory to ensure backward compatibility with different versions of Omeka S
 * by making the File\Store\Manager service compatible with the File\Manager interface
 */
class FileManagerDelegatorFactory implements DelegatorFactoryInterface
{
    /**
     * Creates a delegator for the FileManager service
     */
    public function __invoke(ContainerInterface $container, $name, callable $callback, array $options = null)
    {
        Debug::log('Entering FileManagerDelegatorFactory...', __METHOD__);
        $fileManager = $callback();
        Debug::log('Original callback executed.', __METHOD__);
        
        // Register the file manager as the Omeka\File\Manager service if it doesn't exist
        if (!$container->has('Omeka\File\Manager')) {
            Debug::logWarning('FileManagerDelegatorFactory - Omeka\\File\\Manager service not found, attempting to register.', __METHOD__);
            // Register this service in the service manager
            $services = $container->get('ServiceManager');
            if (method_exists($services, 'setService')) {
                $services->setService('Omeka\File\Manager', $fileManager);
            } else {
                // For older Laminas versions
                $services->setService('Omeka\File\Manager', $fileManager);
            }
            Debug::log('FileManagerDelegatorFactory - Omeka\\File\\Manager service registered.', __METHOD__);
        } else {
             Debug::log('FileManagerDelegatorFactory - Omeka\\File\\Manager service already exists.', __METHOD__);
        }
        
        Debug::log('Exiting FileManagerDelegatorFactory.', __METHOD__);
        return $fileManager;
    }
}