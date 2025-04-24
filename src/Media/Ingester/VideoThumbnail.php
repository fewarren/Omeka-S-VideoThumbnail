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

    private static function debugLog($message, $entityManager = null)
    {
        try {
            $settings = null;
            $container = null;
            if ($entityManager && method_exists($entityManager, 'getConfiguration')) {
                $container = $entityManager->getConfiguration()->getAttribute('container');
                if ($container && $container->has('Omeka\\Settings')) {
                    $settings = $container->get('Omeka\\Settings');
                }
            }
            if (!$settings && class_exists('Omeka\\Settings\Settings')) {
                $settings = \Omeka\Settings\Settings::class;
            }
            $debug = false;
            if ($settings && is_object($settings)) {
                $debug = $settings->get('videothumbnail_debug', false);
            }
            // Diagnostic: log debug flag value
            error_log('[VideoThumbnail] Debug flag is ' . ($debug ? 'ON' : 'OFF'));
            if (!$debug) return;

            // Use OMEKA_PATH for log directory if defined
            $logDir = defined('OMEKA_PATH') ? OMEKA_PATH . '/logs' : dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            $logFile = $logDir . DIRECTORY_SEPARATOR . 'videothumbnail_debug.log';
            // Diagnostic: log log file path
            error_log('[VideoThumbnail] Debug log file path: ' . $logFile);
            $entry = date('Y-m-d H:i:s') . ' ' . $message . "\n";
            if (@file_put_contents($logFile, $entry, FILE_APPEND) === false) {
                error_log('[VideoThumbnail] Failed to write to log file: ' . $logFile);
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
