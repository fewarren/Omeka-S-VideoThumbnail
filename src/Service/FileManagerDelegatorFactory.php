<?php

namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;
use VideoThumbnail\Service\Thumbnail\ThumbnailSynchronizer;
use Omeka\File\Manager as FileManager;

class FileManagerDelegatorFactory implements DelegatorFactoryInterface
{
    public function __invoke(ContainerInterface $container, $name, callable $callback, array $options = null)
    {
        try {
            // Get original FileManager
            $fileManager = $callback();
            
            // Attempt to get and set ThumbnailSynchronizer service
            if ($container->has(ThumbnailSynchronizer::class)) {
                $thumbnailSynchronizer = $container->get(ThumbnailSynchronizer::class);
                
                if (method_exists($fileManager, 'setThumbnailSynchronizer')) {
                    $fileManager->setThumbnailSynchronizer($thumbnailSynchronizer);
                }
            }

            return $fileManager;

        } catch (\Exception $e) {
            error_log('VideoThumbnail FileManagerDelegator: ' . $e->getMessage());
            return $callback();
        }
    }
}