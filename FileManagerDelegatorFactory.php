<?php
namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;

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
        error_log('VideoThumbnail: Entering FileManagerDelegatorFactory...');
        $fileManager = $callback();
        error_log('VideoThumbnail: FileManagerDelegatorFactory - Original callback executed.');
        
        // Register the file manager as the Omeka\File\Manager service if it doesn't exist
        if (!$container->has('Omeka\File\Manager')) {
            error_log('VideoThumbnail: FileManagerDelegatorFactory - Omeka\\File\\Manager service not found, attempting to register.');
            // Register this service in the service manager
            $services = $container->get('ServiceManager');
            if (method_exists($services, 'setService')) {
                $services->setService('Omeka\File\Manager', $fileManager);
            } else {
                // For older Laminas versions
                $services->setService('Omeka\File\Manager', $fileManager);
            }
            error_log('VideoThumbnail: FileManagerDelegatorFactory - Omeka\\File\\Manager service registered.');
        } else {
             error_log('VideoThumbnail: FileManagerDelegatorFactory - Omeka\\File\\Manager service already exists.');
        }
        
        error_log('VideoThumbnail: Exiting FileManagerDelegatorFactory.');
        return $fileManager;
    }
}