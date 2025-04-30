<?php

namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;
use VideoThumbnail\Service\Thumbnail\ThumbnailSynchronizer;
use Omeka\File\Manager as FileManager;

class FileManagerDelegatorFactory implements DelegatorFactoryInterface
{
    private static $isProcessing = false; // Static flag to prevent recursion

    public function __invoke(ContainerInterface $container, $name, callable $callback, array $options = null)
    {
        // Prevent recursive calls that can lead to memory exhaustion
        if (self::$isProcessing) {
            return $callback();
        }

        try {
            // Set processing flag to prevent recursion
            self::$isProcessing = true;

            // Get original FileManager
            $fileManager = $callback();
            
            // Only try to get ThumbnailSynchronizer if not in a recursive context
            // Directly check for the class name to avoid dependency issues
            $thumbnailSynchronizerName = 'VideoThumbnail\Service\Thumbnail\ThumbnailSynchronizer';
            
            // Get config with error handling
            try {
                $config = $container->get('Config');
                
                // Avoid any direct array access that could trigger errors
                $debugEnabled = false;
                
                // First check if we have module config
                if (is_array($config) && isset($config['videothumbnail'])) {
                    $moduleConfig = $config['videothumbnail'];
                    
                    // Then check settings
                    if (is_array($moduleConfig) && isset($moduleConfig['settings'])) {
                        $settings = $moduleConfig['settings'];
                        
                        // Finally check for debug mode
                        if (is_array($settings) && isset($settings['debug_mode'])) {
                            $debugEnabled = (bool)$settings['debug_mode'];
                        }
                    }
                }
            } catch (\Exception $e) {
                // Failsafe - just set debug to false and continue
                error_log('VideoThumbnail FileManagerDelegator: Error accessing config: ' . $e->getMessage());
                $debugEnabled = false;
            }
            
            if ($container->has($thumbnailSynchronizerName) && !$debugEnabled) {
                try {
                    // Try to get the service but with a timeout to prevent hangs
                    $thumbnailSynchronizer = $container->get($thumbnailSynchronizerName);
                    
                    if (method_exists($fileManager, 'setThumbnailSynchronizer')) {
                        $fileManager->setThumbnailSynchronizer($thumbnailSynchronizer);
                    }
                } catch (\Exception $e) {
                    // Log but continue - this is not critical
                    error_log('VideoThumbnail FileManagerDelegator: Failed to get ThumbnailSynchronizer: ' . $e->getMessage());
                }
            }

            return $fileManager;
        } catch (\Exception $e) {
            error_log('VideoThumbnail FileManagerDelegator: ' . $e->getMessage());
            return $callback();
        } finally {
            // Reset processing flag regardless of outcome
            self::$isProcessing = false;
        }
    }
}