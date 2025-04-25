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
            // Robust log directory resolution
            $logDir = null;
            if (defined('OMEKA_PATH')) {
                $logDir = OMEKA_PATH . DIRECTORY_SEPARATOR . 'logs';
            } else {
                $logDir = realpath(__DIR__ . '/../../../../../logs');
            }
            if (!$logDir) {
                $logDir = getcwd() . DIRECTORY_SEPARATOR . 'logs';
            }
            if (!is_dir($logDir)) {
                if (!@mkdir($logDir, 0777, true)) {
                    error_log('[VideoThumbnail] Failed to create log directory: ' . $logDir);
                    return;
                }
            }
            $logFile = $logDir . DIRECTORY_SEPARATOR . 'VideoThumbnailDebug';

            $debug = false;
            $settings = null;
            if ($entityManager && method_exists($entityManager, 'getConfiguration')) {
                $config = $entityManager->getConfiguration();
                if (method_exists($config, 'getAttribute')) {
                    $container = $config->getAttribute('container');
                    if ($container && method_exists($container, 'has') && $container->has('Omeka\\Settings')) {
                        $settings = $container->get('Omeka\\Settings');
                    }
                }
            }
            if (!$settings && class_exists('Omeka\\Settings\\Settings')) {
                $settings = \Omeka\Settings\Settings::class;
            }
            if ($settings && is_object($settings) && method_exists($settings, 'get')) {
                $debug = $settings->get('videothumbnail_debug', false);
            }

            // Always write a [TEST] entry to verify file creation
            $testEntry = date('Y-m-d H:i:s') . " [TEST] VideoThumbnail debugLog called (flag: " . ($debug ? 'ON' : 'OFF') . ")\n";
            if (@file_put_contents($logFile, $testEntry, FILE_APPEND) === false) {
                error_log('[VideoThumbnail] Failed to write to log file: ' . $logFile);
            }

            if ($debug) {
                $entry = date('Y-m-d H:i:s') . ' [DEBUG] ' . $message . "\n";
                if (@file_put_contents($logFile, $entry, FILE_APPEND) === false) {
                    error_log('[VideoThumbnail] Failed to write to log file: ' . $logFile);
                }
            }
        } catch (\Exception $e) {
            error_log('[VideoThumbnail] Exception in debugLog: ' . $e->getMessage());
        }
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
