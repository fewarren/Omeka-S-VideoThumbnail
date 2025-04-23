<?php

namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;
use VideoThumbnail\Service\Thumbnail\ThumbnailSynchronizer;
use Omeka\File\Manager as FileManager;
use VideoThumbnail\Stdlib\Debug;

class FileManagerDelegatorFactory implements DelegatorFactoryInterface
{
    /**
     * A delegator factory for the Omeka File Manager service.
     *
     * @param ContainerInterface $container
     * @param string $name Requested service name (Omeka\File\Manager)
     * @param callable $callback Callback that produces the original service
     * @param null|array $options Service creation options (optional)
     * @return FileManager
     */
    public function __invoke(ContainerInterface $container, $name, callable $callback, array $options = null)
    {
        // Log invocation info at the very start
        Debug::log(sprintf(
            'FileManagerDelegatorFactory: Starting delegator process for service "%s"',
            $name
        ), __METHOD__);

        // Get the original FileManager instance by invoking the callback
        try {
            $fileManager = $callback();
            Debug::log('Successfully retrieved original FileManager service', __METHOD__);
        } catch (\Exception $e) {
            Debug::logError('Failed to retrieve original FileManager service: ' . $e->getMessage(), __METHOD__, $e);
            // If we can't get the original service, we have to rethrow
            throw $e;
        }

        // Define all possible service identifiers to look for
        $possibleServiceNames = [
            ThumbnailSynchronizer::class,
            'VideoThumbnail\Thumbnail\ThumbnailSynchronizer',
            'VideoThumbnail\Service\Thumbnail\ThumbnailSynchronizer'
        ];

        $thumbnailSynchronizer = null;
        $serviceFound = false;
        
        // Attempt to retrieve the ThumbnailSynchronizer using any of the possible names
        foreach ($possibleServiceNames as $serviceName) {
            if ($container->has($serviceName)) {
                $serviceFound = true;
                try {
                    $thumbnailSynchronizer = $container->get($serviceName);
                    Debug::log(sprintf(
                        'Successfully retrieved ThumbnailSynchronizer using service name "%s"',
                        $serviceName
                    ), __METHOD__);
                    break;
                } catch (\Exception $e) {
                    Debug::logError(sprintf(
                        'Error retrieving ThumbnailSynchronizer using service name "%s": %s',
                        $serviceName,
                        $e->getMessage()
                    ), __METHOD__, $e);
                }
            }
        }
        
        if (!$serviceFound) {
            Debug::logWarning(
                'ThumbnailSynchronizer service not found under any known name. ' .
                'Make sure service_manager configuration is correct.',
                __METHOD__
            );
            return $fileManager;
        }
        
        if ($thumbnailSynchronizer === null) {
            Debug::logWarning(
                'Failed to retrieve ThumbnailSynchronizer despite service being registered. ' .
                'This may indicate a dependency issue in the service.',
                __METHOD__
            );
            return $fileManager;
        }
        
        // Check if the fileManager can accept the synchronizer
        if (!method_exists($fileManager, 'setThumbnailSynchronizer')) {
            Debug::logWarning(
                'FileManager does not have setThumbnailSynchronizer method. ' .
                'This suggests a version incompatibility with the current Omeka S version.',
                __METHOD__
            );
            return $fileManager;
        }
        
        // Set the synchronizer on the FileManager
        try {
            $fileManager->setThumbnailSynchronizer($thumbnailSynchronizer);
            Debug::log(
                'Successfully set ThumbnailSynchronizer on FileManager. ' .
                'Video thumbnail synchronization is now active.',
                __METHOD__
            );
        } catch (\Exception $e) {
            Debug::logError(
                'Exception occurred when setting ThumbnailSynchronizer: ' . $e->getMessage(),
                __METHOD__,
                $e
            );
        }
        
        // Return the modified FileManager instance
        return $fileManager;
    }
}