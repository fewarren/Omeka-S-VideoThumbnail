<?php
namespace VideoThumbnail\Media\Ingester;

class VideoThumbnail
{
    public static function extractAndSaveThumbnail($media, $percent, $ffmpegPath, $entityManager)
    {
        self::debugLog("Starting thumbnail extraction for media ID {$media->getId()}", $entityManager);
        try {
            $sourcePath = $media->getSource();
            $duration = self::getVideoDuration($sourcePath, $ffmpegPath);
            self::debugLog("Video duration for media ID {$media->getId()}: $duration", $entityManager);
            if ($duration <= 0) {
                self::logError("Could not determine video duration for media ID {$media->getId()}");
                return;
            }
            $time = max(1, min($duration - 1, intval($duration * $percent / 100)));
            $outputPath = sys_get_temp_dir() . '/thumb_' . uniqid() . '.jpg';

            $cmd = escapeshellcmd($ffmpegPath) . " -ss $time -i " . escapeshellarg($sourcePath) . " -frames:v 1 -q:v 2 " . escapeshellarg($outputPath);
            self::debugLog("Running ffmpeg command: $cmd", $entityManager);
            exec($cmd, $output, $returnVar);

            if ($returnVar !== 0 || !file_exists($outputPath)) {
                self::logError("FFmpeg failed for media ID {$media->getId()} (cmd: $cmd)");
                return;
            }

            // Save thumbnail to Omeka's storage (pseudo-code, adjust for your Omeka version)
            if (method_exists($media, 'setThumbnail')) {
                $media->setThumbnail($outputPath);
                $entityManager->persist($media);
                $entityManager->flush();
                self::debugLog("Thumbnail saved for media ID {$media->getId()}", $entityManager);
            } else {
                self::logError("setThumbnail method not available on media entity for ID {$media->getId()}");
            }
            unlink($outputPath);
        } catch (\Exception $e) {
            self::logError("Exception extracting thumbnail for media ID {$media->getId()}: " . $e->getMessage());
        }
    }

    private static function logError($message)
    {
        // Try to use Omeka logger if available
        if (class_exists('Laminas\\Log\\Logger')) {
            $logger = null;
            if (class_exists('Omeka\\Log\\LoggerFactory')) {
                $logger = (new \Omeka\Log\LoggerFactory)();
            }
            if ($logger) {
                $logger->err($message);
                return;
            }
        }
        // Fallback to error_log
        error_log('[VideoThumbnail] ' . $message);
    }

    public static function debugLog($message, $entityManager = null)
    {
        try {
            // Improved log directory resolution
            $logDir = null;
            
            // First try OMEKA_PATH constant
            if (defined('OMEKA_PATH')) {
                $logDir = OMEKA_PATH . DIRECTORY_SEPARATOR . 'logs';
            } 
            // Then try relative path (assuming we're in src/Media/Ingester)
            else {
                $possiblePaths = [
                    __DIR__ . '/../../../../../logs',
                    __DIR__ . '/../../../../logs',
                    __DIR__ . '/../../../logs',
                    getcwd() . DIRECTORY_SEPARATOR . 'logs'
                ];
                
                foreach ($possiblePaths as $path) {
                    if (is_dir($path) || @mkdir($path, 0775, true)) {
                        $logDir = $path;
                        break;
                    }
                }
            }
            
            // If we couldn't find or create a logs directory, fallback to system temp
            if (!$logDir) {
                $logDir = sys_get_temp_dir();
            }
            
            // Make sure log directory is writable
            if (!is_writable($logDir)) {
                error_log('[VideoThumbnail] Log directory is not writable: ' . $logDir);
                return;
            }
            
            $logFile = $logDir . DIRECTORY_SEPARATOR . 'VideoThumbnailDebug.log';
            
            // Always log a basic entry to test file writing works
            $testEntry = date('Y-m-d H:i:s') . " [TEST] VideoThumbnail debug check. Log location: $logFile\n";
            if (@file_put_contents($logFile, $testEntry, FILE_APPEND) === false) {
                error_log('[VideoThumbnail] Failed to write to log file: ' . $logFile);
                return;
            }

            // First check if debug is enabled via file flag for easier testing
            $flagFile = $logDir . DIRECTORY_SEPARATOR . 'videothumbnail_debug_enabled';
            $debugEnabled = file_exists($flagFile);
            
            // If no flag file, check the settings
            if (!$debugEnabled) {
                $settings = self::getSettings($entityManager);
                $debugEnabled = $settings ? (bool)$settings->get('videothumbnail_debug', false) : false;
            }
            
            // Log the actual message if debugging is enabled
            if ($debugEnabled) {
                $entry = date('Y-m-d H:i:s') . ' [DEBUG] ' . $message . "\n";
                @file_put_contents($logFile, $entry, FILE_APPEND);
            }
        } catch (\Exception $e) {
            error_log('[VideoThumbnail] Exception in debugLog: ' . $e->getMessage());
        }
    }
    
    /**
     * Helper method to retrieve Omeka settings
     */
    private static function getSettings($entityManager = null)
    {
        // Try global $application variable if available
        global $application;
        if (isset($application) && method_exists($application, 'getServiceManager')) {
            $sm = $application->getServiceManager();
            if ($sm->has('Omeka\\Settings')) {
                return $sm->get('Omeka\\Settings');
            }
        }

        // Try Laminas Application::getInstance
        if (class_exists('Laminas\\Mvc\\Application') && method_exists('Laminas\\Mvc\\Application', 'getInstance')) {
            $app = \Laminas\Mvc\Application::getInstance();
            if ($app && method_exists($app, 'getServiceManager')) {
                $sm = $app->getServiceManager();
                if ($sm->has('Omeka\\Settings')) {
                    return $sm->get('Omeka\\Settings');
                }
            }
        }

        // Fallback to entity manager
        if ($entityManager && method_exists($entityManager, 'getConfiguration')) {
            $config = $entityManager->getConfiguration();
            if (method_exists($config, 'getAttribute')) {
                $container = $config->getAttribute('container');
                if ($container && method_exists($container, 'has') && $container->has('Omeka\\Settings')) {
                    return $container->get('Omeka\\Settings');
                }
            }
        }

        return null;
    }

    public static function getVideoDuration($file, $ffmpegPath)
    {
        $cmd = escapeshellcmd($ffmpegPath) . " -i " . escapeshellarg($file) . " 2>&1";
        $output = shell_exec($cmd);
        if (preg_match('/Duration: (\d+):(\d+):(\d+\.\d+)/', $output, $matches)) {
            return $matches[1] * 3600 + $matches[2] * 60 + (float)$matches[3];
        }
        return 0;
    }

    public static function getThumbnailUrl($media)
    {
        // If the media has a thumbnail, return its URL
        if (method_exists($media, 'thumbnailUrl')) {
            return $media->thumbnailUrl('large');
        }
        return '';
    }
}
