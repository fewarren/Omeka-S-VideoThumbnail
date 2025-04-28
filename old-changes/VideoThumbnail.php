<?php
/**
 * VideoThumbnail Media Ingester
 * 
 * Extracts thumbnails from video files using FFmpeg
 * 
 * References:
 * @see https://github.com/Daniel-KM/Omeka-S-module-DerivativeMedia
 * For additional approaches to media derivatives in Omeka S
 */
namespace VideoThumbnail\Media\Ingester;

class VideoThumbnail
{
    public static function extractAndSaveThumbnail($media, $percent, $ffmpegPath, $entityManager)
    {
        self::debugLog("Starting thumbnail extraction for media ID {$media->getId()}", $entityManager);
        error_log("VideoThumbnail: Starting extraction for media ID {$media->getId()}");
        
        try {
            // IMPORTANT: Get file path using storage system for reliability
            $storageId = $media->getStorageId();
            $originalFilePath = '';
            
            if ($storageId) {
                // First try: Get file using proper storage system
                $serviceLocator = $entityManager->getConfiguration()->getAttribute('serviceLocator');
                $basePath = '';
                
                if ($serviceLocator && $serviceLocator->has('Config')) {
                    $config = $serviceLocator->get('Config');
                    if (isset($config['file_store']['local']['base_path'])) {
                        $basePath = $config['file_store']['local']['base_path'];
                    }
                }
                
                if (empty($basePath) && defined('OMEKA_PATH')) {
                    $basePath = OMEKA_PATH . '/files';
                }
                
                if ($basePath) {
                    $originalFilePath = $basePath . '/original/' . $storageId;
                    self::debugLog("Using storage ID path: {$originalFilePath}", $entityManager);
                    error_log("VideoThumbnail: Using storage ID path: {$originalFilePath}");
                    
                    if (!file_exists($originalFilePath)) {
                        self::debugLog("Storage ID path not found: {$originalFilePath}", $entityManager);
                        $originalFilePath = '';
                    }
                }
            }
            
            // Fallback: Try source path 
            if (empty($originalFilePath)) {
                $sourcePath = $media->getSource();
                self::debugLog("Media source: {$sourcePath}", $entityManager);
                error_log("VideoThumbnail: Media source: {$sourcePath}");
                
                // Check if source path exists directly
                if (!empty($sourcePath) && file_exists($sourcePath)) {
                    $originalFilePath = $sourcePath;
                    self::debugLog("Source path exists directly", $entityManager);
                } else {
                    // Try various path combinations
                    $basePath = defined('OMEKA_PATH') ? OMEKA_PATH : '';
                    $possiblePaths = [
                        $sourcePath,
                        $basePath . '/files/original/' . basename($sourcePath),
                        $basePath . '/files/' . $sourcePath,
                        '/var/www/omeka-s/files/original/' . basename($sourcePath),
                    ];
                    
                    foreach ($possiblePaths as $path) {
                        if (!empty($path) && file_exists($path)) {
                            $originalFilePath = $path;
                            self::debugLog("Found file at: {$path}", $entityManager);
                            error_log("VideoThumbnail: Found file at: {$path}");
                            break;
                        }
                    }
                }
            }
            
            // Final check
            if (empty($originalFilePath) || !file_exists($originalFilePath)) {
                self::logError("Could not locate source file for media ID {$media->getId()}");
                error_log("VideoThumbnail: ERROR - Could not locate source file for media ID {$media->getId()}");
                return false;
            }
            
            // Get video duration  
            $duration = self::getVideoDuration($originalFilePath, $ffmpegPath);
            self::debugLog("Video duration for media ID {$media->getId()}: {$duration}", $entityManager);
            
            if ($duration <= 0) {
                self::logError("Could not determine video duration for media ID {$media->getId()}");
                error_log("VideoThumbnail: ERROR - Could not determine duration for media ID {$media->getId()}");
                return false;
            }
            
            // Calculate timestamp based on percentage
            $time = max(1, min($duration - 1, intval($duration * $percent / 100)));
            $outputPath = sys_get_temp_dir() . '/thumb_' . uniqid() . '.jpg';

            // Check if this is a webm file and use appropriate settings
            $mediaType = '';
            if (method_exists($media, 'getMediaType')) {
                $mediaType = $media->getMediaType();
            } else if (method_exists($media, 'mediaType')) {
                $mediaType = $media->mediaType();
            }
            
            $isWebm = (strpos($mediaType, 'video/webm') !== false || 
                       (strpos($originalFilePath, '.webm') !== false));
            
            // For webm files, we need to use specific ffmpeg options for better compatibility
            if ($isWebm) {
                self::debugLog("Processing webm video with special options", $entityManager);
                error_log("VideoThumbnail: Processing webm video with special options");
                $cmd = escapeshellcmd($ffmpegPath) . " -ss $time -i " . escapeshellarg($originalFilePath) . 
                       " -frames:v 1 -q:v 2 -pix_fmt yuv420p -vf 'scale=trunc(iw/2)*2:trunc(ih/2)*2' " . 
                       escapeshellarg($outputPath);
            } else {
                // Standard command for other video formats
                $cmd = escapeshellcmd($ffmpegPath) . " -ss $time -i " . escapeshellarg($originalFilePath) . 
                       " -frames:v 1 -q:v 2 " . escapeshellarg($outputPath);
            }
            
            self::debugLog("Running ffmpeg command: $cmd", $entityManager);
            error_log("VideoThumbnail: Running ffmpeg command: $cmd");
            
            exec($cmd, $output, $returnVar);
            
            // Log the complete output from FFmpeg for debugging
            error_log("VideoThumbnail: FFmpeg complete output: " . print_r($output, true));
            self::debugLog("FFmpeg complete output: " . print_r($output, true), $entityManager);

            if ($returnVar !== 0 || !file_exists($outputPath)) {
                self::logError("FFmpeg failed for media ID {$media->getId()} (cmd: $cmd)");
                error_log("VideoThumbnail: ERROR - FFmpeg failed for media ID {$media->getId()}, return code: {$returnVar}");
                
                // Check common issues with FFmpeg
                $outputStr = implode(' ', $output);
                if (strpos($outputStr, 'not found') !== false) {
                    error_log("VideoThumbnail: FFmpeg reports file not found - this may be a permissions issue");
                } else if (strpos($outputStr, 'Invalid data found') !== false) {
                    error_log("VideoThumbnail: FFmpeg reports invalid data - this video may be corrupt or have unsupported codec");
                }
                
                return false;
            }
            
            // Verify the output file is a valid image and not empty/corrupt
            if (file_exists($outputPath)) {
                $filesize = filesize($outputPath);
                if ($filesize < 100) { // Less than 100 bytes is almost certainly not a valid image
                    error_log("VideoThumbnail: Generated thumbnail is too small ({$filesize} bytes) - likely invalid");
                    self::debugLog("Generated thumbnail is suspiciously small: {$filesize} bytes", $entityManager);
                    return false;
                }
            }

            self::debugLog("FFmpeg successfully created thumbnail at: $outputPath", $entityManager);
            error_log("VideoThumbnail: Successfully created thumbnail at: $outputPath");
            
            // STORE THUMBNAIL - Try multiple approaches for compatibility
            $stored = false;
            
            // Method 1: Direct entity method (modern Omeka)
            if (method_exists($media, 'setThumbnail')) {
                try {
                    self::debugLog("Saving thumbnail using setThumbnail method", $entityManager);
                    $media->setThumbnail($outputPath);
                    $entityManager->persist($media);
                    $entityManager->flush();
                    self::debugLog("Thumbnail saved using setThumbnail for media ID {$media->getId()}", $entityManager);
                    $stored = true;
                } catch (\Exception $e) {
                    self::debugLog("Error using setThumbnail: " . $e->getMessage(), $entityManager);
                }
            }
            
            // Method 2: File Manager (for various versions)
            if (!$stored && $serviceLocator) {
                try {
                    self::debugLog("Trying FileManager method", $entityManager);
                    
                    if ($serviceLocator->has('Omeka\File\TempFileFactory')) {
                        $tempFileFactory = $serviceLocator->get('Omeka\File\TempFileFactory');
                        $tempFile = $tempFileFactory->build();
                        $tempFile->setSourceName(basename($outputPath));
                        $tempFile->setTempPath($outputPath);
                        
                        $fileManager = $serviceLocator->get('Omeka\File\Manager');
                        // Try different methods based on version
                        if (method_exists($fileManager, 'storeThumbnails')) {
                            $fileManager->storeThumbnails($tempFile, $media);
                            self::debugLog("Thumbnail saved using storeThumbnails", $entityManager);
                            $stored = true;
                        } elseif (method_exists($fileManager, 'storeThumbnail')) {
                            $fileManager->storeThumbnail($tempFile, $media);
                            self::debugLog("Thumbnail saved using storeThumbnail", $entityManager);
                            $stored = true;
                        }
                    }
                } catch (\Exception $e) {
                    self::debugLog("Error using FileManager: " . $e->getMessage(), $entityManager);
                }
            }
            
            // Method 3: Direct file system as last resort
            if (!$stored) {
                try {
                    self::debugLog("Trying direct file storage method", $entityManager);
                    
                    $basePath = '';
                    if ($serviceLocator && $serviceLocator->has('Config')) {
                        $config = $serviceLocator->get('Config');
                        if (isset($config['file_store']['local']['base_path'])) {
                            $basePath = $config['file_store']['local']['base_path'];
                        }
                    }
                    
                    if (empty($basePath) && defined('OMEKA_PATH')) {
                        $basePath = OMEKA_PATH . '/files';
                    }
                    
                    if (!$storageId) {
                        // Generate a storage id if we don't have one
                        $storageId = 'thumb_' . $media->getId() . '_' . uniqid();
                    }
                    
                    // Create all thumbnail types
                    $thumbTypes = ['square', 'medium', 'large'];
                    foreach ($thumbTypes as $type) {
                        $thumbPath = $basePath . '/' . $type . '/' . $storageId . '.jpg';
                        self::debugLog("Creating direct thumbnail at: {$thumbPath}", $entityManager);
                        // Simple copy for direct saving - in a real system we'd resize for each type
                        if (!copy($outputPath, $thumbPath)) {
                            self::debugLog("Failed to copy thumbnail to {$thumbPath}", $entityManager);
                        } else {
                            @chmod($thumbPath, 0644);
                            $stored = true;
                        }
                    }
                    
                    if ($stored) {
                        // Set the hasThumbnails flag in the media entity
                        $media->setHasThumbnails(true);
                        $entityManager->persist($media);
                        $entityManager->flush();
                        self::debugLog("Set hasThumbnails flag to true", $entityManager);
                    }
                } catch (\Exception $e) {
                    self::debugLog("Error using direct file storage: " . $e->getMessage(), $entityManager);
                }
            }
            
            // Clean up the temporary file
            if (file_exists($outputPath)) {
                unlink($outputPath);
                self::debugLog("Temporary thumbnail file removed", $entityManager);
            }
            
            // Return success/failure
            if ($stored) {
                error_log("VideoThumbnail: Successfully created and saved thumbnails for media ID {$media->getId()}");
                return true;
            } else {
                error_log("VideoThumbnail: Failed to store thumbnails for media ID {$media->getId()}");
                return false;
            }
            
        } catch (\Exception $e) {
            self::logError("Exception extracting thumbnail for media ID {$media->getId()}: " . $e->getMessage());
            error_log("VideoThumbnail: EXCEPTION - " . $e->getMessage());
            return false;
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

    /**
     * Write debug log messages
     * 
     * @param string $message The message to log
     * @param \Doctrine\ORM\EntityManager|null $entityManager Optional entity manager
     */
    public static function debugLog($message, $entityManager = null)
    {
        try {
            // Try to get Omeka's standard logger
            $logger = null;
            
            // Method 1: Try to get logger from Laminas Application (best practice per Omeka S docs)
            if (class_exists('Laminas\\Mvc\\Application') && method_exists('Laminas\\Mvc\\Application', 'getInstance')) {
                try {
                    $app = \Laminas\Mvc\Application::getInstance();
                    if ($app) {
                        $sm = $app->getServiceManager();
                        if ($sm && $sm->has('Omeka\\Logger')) {
                            $logger = $sm->get('Omeka\\Logger');
                        }
                    }
                } catch (\Exception $e) {
                    // Fallback to error_log
                    error_log('[VideoThumbnail] Error getting logger from application: ' . $e->getMessage());
                }
            }
            
            // Check if we successfully got the logger
            if ($logger) {
                // Use the standard Omeka logger
                $logger->debug('[VideoThumbnail] ' . $message);
                
                // Continue with custom file logging if debug is enabled
                if (!defined('OMEKA_PATH')) {
                    define('OMEKA_PATH', dirname(dirname(dirname(dirname(dirname(__DIR__))))));
                }
            } else {
                // No standard logger available, use error_log for basic logging
                error_log('[VideoThumbnail] ' . $message);
                
                // If OMEKA_PATH isn't defined, try to define it
                if (!defined('OMEKA_PATH')) {
                    // Try various possible paths
                    $possiblePaths = [
                        dirname(dirname(dirname(dirname(dirname(__DIR__))))), // Most likely path
                        dirname(dirname(dirname(__DIR__))),
                        getcwd()
                    ];
                    
                    foreach ($possiblePaths as $path) {
                        if (file_exists($path . '/application') && file_exists($path . '/modules')) {
                            define('OMEKA_PATH', $path);
                            error_log('[VideoThumbnail] Defined OMEKA_PATH as: ' . OMEKA_PATH);
                            break;
                        }
                    }
                    
                    if (!defined('OMEKA_PATH')) {
                        define('OMEKA_PATH', dirname(dirname(dirname(dirname(dirname(__DIR__))))));
                    }
                }
            }
            
            // === Custom file logging for detailed debugging ===
            
            // Always enable debugging for now - PRODUCTION CODE SHOULD BE FIXED
            $debugEnabled = true;
            error_log('[VideoThumbnail] Debug logging always enabled');
            
            // Method 1: Check for flag file (logging the check but not using the result)
            $moduleDir = dirname(dirname(dirname(dirname(__DIR__))));
            $flagFile = $moduleDir . '/logs/videothumbnail_debug_enabled';
            
            if (file_exists($flagFile)) {
                error_log('[VideoThumbnail] Debug flag file exists: ' . $flagFile);
            }
            
            // Also check for flag file in Omeka S server logs directory
            $omekaLogDir = '/var/www/omkea-s/logs';
            $omekaFlagFile = $omekaLogDir . '/videothumbnail_debug_enabled';
            
            if (file_exists($omekaFlagFile)) {
                error_log('[VideoThumbnail] Omeka S server flag file exists: ' . $omekaFlagFile);
            }
            
            // For backward compatibility, also check for flag file in Downloads folder
            $altFlagFile = dirname($moduleDir) . '/logs/videothumbnail_debug_enabled';
            if (file_exists($altFlagFile)) {
                error_log('[VideoThumbnail] Alternate flag file exists: ' . $altFlagFile);
            }
            
            // Method 2: Check settings (if we have entity manager)
            if ($entityManager) {
                $settings = self::getSettings($entityManager);
                if ($settings) {
                    $settingValue = $settings->get('videothumbnail_debug', false);
                    error_log('[VideoThumbnail] Debug setting value from Omeka: ' . ($settingValue ? 'TRUE' : 'false'));
                }
            }
            
            // Use the Omeka S server logs directory
            $logDir = '/var/www/omkea-s/logs';
            
            // Fallback to module directory if Omeka S server logs directory doesn't exist or isn't writable
            if (!is_dir($logDir) || !is_writable($logDir)) {
                // Get the absolute path to the module directory as fallback
                $reflector = new \ReflectionClass('VideoThumbnail\Media\Ingester\VideoThumbnail');
                $filename = $reflector->getFileName();
                
                // Go from src/Media/Ingester/VideoThumbnail.php up to module root
                $moduleDir = dirname(dirname(dirname(dirname($filename))));
                $logDir = $moduleDir . '/logs';
                
                error_log('[VideoThumbnail] Fallback to module directory: ' . $moduleDir);
            } else {
                error_log('[VideoThumbnail] Using Omeka S server logs directory: ' . $logDir);
            }
            
            // Make sure log directory exists and is writable
            if (!is_dir($logDir)) {
                if (!@mkdir($logDir, 0777, true)) {
                    error_log('[VideoThumbnail] ERROR: Failed to create log directory at ' . $logDir);
                    // Fallback to using system temp directory if module directory isn't writable
                    $logDir = sys_get_temp_dir() . '/omeka_videothumbnail_logs';
                    @mkdir($logDir, 0777, true);
                    error_log('[VideoThumbnail] Fallback to temp directory: ' . $logDir);
                } else {
                    error_log('[VideoThumbnail] Created log directory at: ' . $logDir);
                }
            }
            
            // Ensure the directory is writable
            if (!is_writable($logDir)) {
                error_log('[VideoThumbnail] Log directory exists but is not writable: ' . $logDir);
                @chmod($logDir, 0777);
            }
            
            error_log('[VideoThumbnail] Using log directory at: ' . $logDir);
            
            // Check if log directory is writable
            if (!is_writable($logDir)) {
                error_log('[VideoThumbnail] ERROR: Log directory is not writable: ' . $logDir);
                return;
            }
            
            // Create debug flag file if it doesn't exist - for testing
            $flagFile = $logDir . DIRECTORY_SEPARATOR . 'videothumbnail_debug_enabled';
            if (!file_exists($flagFile)) {
                @touch($flagFile);
                error_log('[VideoThumbnail] Created debug flag file at: ' . $flagFile);
            }
            
            // 4. Write to the custom debug log file
            $logFile = $logDir . DIRECTORY_SEPARATOR . 'VideoThumbnailDebug.log';
            
            // Check if log file is writable
            if (file_exists($logFile) && !is_writable($logFile)) {
                error_log('[VideoThumbnail] ERROR: Log file exists but is not writable: ' . $logFile);
                
                // Try to fix permissions
                chmod($logFile, 0666);
                error_log('[VideoThumbnail] Attempted to fix log file permissions');
            }
            
            // Create log file if it doesn't exist
            if (!file_exists($logFile)) {
                $initEntry = date('Y-m-d H:i:s') . " [INIT] VideoThumbnail debug log initialized\n";
                $result = file_put_contents($logFile, $initEntry);
                
                if ($result === false) {
                    error_log('[VideoThumbnail] ERROR: Failed to create log file at: ' . $logFile);
                    
                    // Check permissions
                    error_log('[VideoThumbnail] Log directory writable: ' . (is_writable($logDir) ? 'Yes' : 'No'));
                    error_log('[VideoThumbnail] Current user: ' . get_current_user() . ', Current process ID: ' . getmypid());
                    return;
                }
                
                // Ensure the file is writable
                chmod($logFile, 0666);
            }
            
            // Write the actual debug message
            $entry = date('Y-m-d H:i:s') . ' [DEBUG] ' . $message . "\n";
            $result = file_put_contents($logFile, $entry, FILE_APPEND);
            
            if ($result === false) {
                error_log('[VideoThumbnail] ERROR: Failed to write to debug log file: ' . $logFile);
                error_log('[VideoThumbnail] Write error details - File exists: ' . (file_exists($logFile) ? 'Yes' : 'No') . 
                          ', Is writable: ' . (is_writable($logFile) ? 'Yes' : 'No'));
            } else {
                error_log('[VideoThumbnail] Successfully wrote debug message to log: ' . $message);
            }
        } catch (\Exception $e) {
            error_log('[VideoThumbnail] Exception in debugLog: ' . $e->getMessage());
        }
    }
    
    /**
     * Helper method to retrieve Omeka settings
     * @param \Doctrine\ORM\EntityManager|null $entityManager
     * @return \Omeka\Settings\Settings|null
     */
    private static function getSettings($entityManager = null)
    {
        // Method 1: Try global $application variable (often available in Omeka context)
        global $application;
        if (isset($application) && method_exists($application, 'getServiceManager')) {
            try {
                $sm = $application->getServiceManager();
                if ($sm && $sm->has('Omeka\\Settings')) {
                    return $sm->get('Omeka\\Settings');
                }
            } catch (\Exception $e) {
                error_log('[VideoThumbnail] Error accessing settings via global application: ' . $e->getMessage());
            }
        }

        // Method 2: Try to get application instance through Laminas
        if (class_exists('Laminas\\Mvc\\Application') && method_exists('Laminas\\Mvc\\Application', 'getInstance')) {
            try {
                $app = \Laminas\Mvc\Application::getInstance();
                if ($app && method_exists($app, 'getServiceManager')) {
                    $sm = $app->getServiceManager();
                    if ($sm && $sm->has('Omeka\\Settings')) {
                        return $sm->get('Omeka\\Settings');
                    }
                }
            } catch (\Exception $e) {
                error_log('[VideoThumbnail] Error accessing settings via Laminas Application: ' . $e->getMessage());
            }
        }

        // Method 3: Via GLOBALS['application']
        if (isset($GLOBALS['application']) && method_exists($GLOBALS['application'], 'getServiceManager')) {
            try {
                $sm = $GLOBALS['application']->getServiceManager();
                if ($sm && $sm->has('Omeka\\Settings')) {
                    return $sm->get('Omeka\\Settings');
                }
            } catch (\Exception $e) {
                error_log('[VideoThumbnail] Error accessing settings via GLOBALS application: ' . $e->getMessage());
            }
        }

        // Method 4: Fallback to entity manager (if available)
        if ($entityManager && method_exists($entityManager, 'getConfiguration')) {
            try {
                $config = $entityManager->getConfiguration();
                if (method_exists($config, 'getAttribute')) {
                    $container = $config->getAttribute('container');
                    if ($container && method_exists($container, 'has') && $container->has('Omeka\\Settings')) {
                        return $container->get('Omeka\\Settings');
                    }
                }
            } catch (\Exception $e) {
                error_log('[VideoThumbnail] Error accessing settings via entity manager: ' . $e->getMessage());
            }
        }

        // If we can't get settings, log a debug message but don't block execution
        error_log('[VideoThumbnail] Could not access Omeka settings');
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
            $url = $media->thumbnailUrl('large');
            // Validate that the URL is not empty and points to a valid thumbnail
            if (!empty($url)) {
                return $url;
            }
            
            // Try alternate sizes if 'large' is not available
            $url = $media->thumbnailUrl('medium');
            if (!empty($url)) {
                return $url;
            }
            
            $url = $media->thumbnailUrl('square');
            if (!empty($url)) {
                return $url;
            }
            
            error_log("VideoThumbnail: Media has thumbnailUrl method but returns empty URLs for ID: " . $media->id());
        }
        
        error_log("VideoThumbnail: No thumbnail available for media ID: " . $media->id());
        return '';
    }
}