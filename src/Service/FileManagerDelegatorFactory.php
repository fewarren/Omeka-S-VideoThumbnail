<?php

namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;
use VideoThumbnail\Service\Thumbnail\ThumbnailSynchronizer;
use Omeka\File\Manager as FileManager;
use VideoThumbnail\Stdlib\Debug;

class FileManagerDelegatorFactory implements DelegatorFactoryInterface
{
    public function __invoke(ContainerInterface $container, $name, callable $callback, array $options = null)
    {
        Debug::log('FileManagerDelegatorFactory: Starting delegator process', __METHOD__);

        try {
            // Get original FileManager
            $fileManager = $callback();
            Debug::log('Successfully retrieved original FileManager service', __METHOD__);

            // Get ThumbnailSynchronizer service
            if ($container->has(ThumbnailSynchronizer::class)) {
                $thumbnailSynchronizer = $container->get(ThumbnailSynchronizer::class);
                Debug::log('Successfully retrieved ThumbnailSynchronizer service', __METHOD__);

                // Set synchronizer if method exists
                if (method_exists($fileManager, 'setThumbnailSynchronizer')) {
                    $fileManager->setThumbnailSynchronizer($thumbnailSynchronizer);
                    Debug::log('Successfully set ThumbnailSynchronizer on FileManager', __METHOD__);
                } else {
                    Debug::logWarning('FileManager does not support ThumbnailSynchronizer', __METHOD__);
                }
            } else {
                Debug::logWarning('ThumbnailSynchronizer service not found', __METHOD__);
            }
        } catch (\Exception $e) {
            Debug::logError('Error in FileManagerDelegator: ' . $e->getMessage(), __METHOD__);
            // Return original file manager on error
            return $callback();
        }

        return $fileManager;
    }
}