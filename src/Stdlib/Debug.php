<?php
namespace VideoThumbnail\Stdlib;

use Laminas\Log\Logger;
use Laminas\Log\Writer\Stream;
use Laminas\Log\Filter\Priority;

class Debug
{
    protected static $isInitialized = false;
    protected static $logger = null;
    protected static $logDir = null;
    protected static $logFile = null;
    protected static $methodDepth = [];
    protected static $debugEnabled = null;
    private static $config = null;
    private static $memoryPeak = 0;
    private static $timeStart = null;
    private static $initializingFlag = false;

    /**
     * Initialize the debug system
     * 
     * @param array $config Configuration array with enabled, log_dir, log_file, max_size, max_files
     */
    public static function init($config)
    {
        $startMemory = memory_get_usage(true);
        error_log(sprintf('VideoThumbnail Debug: Starting init() - Memory: %s', self::formatBytes($startMemory)));
        
        // Prevent possible endless loop or repeated initialization
        if (self::$initializingFlag) {
            error_log('VideoThumbnail Debug: Preventing recursive initialization');
            return;
        }
        
        // If already initialized with the same config, just return
        if (self::$isInitialized && self::$config !== null && isset($config['enabled']) && 
            isset(self::$config['enabled']) && $config['enabled'] === self::$config['enabled'] &&
            isset($config['log_file']) && isset(self::$config['log_file']) &&
            $config['log_file'] === self::$config['log_file']) {
            error_log('VideoThumbnail Debug: Already initialized with same configuration');
            return;
        }
        
        // Set initialization flag to prevent recursive calls
        self::$initializingFlag = true;
        
        // Set a timeout to avoid hanging
        $startTime = microtime(true);
        
        // Log the config parameter
        error_log('VideoThumbnail Debug: Config parameter: ' . print_r($config, true));
        
        self::$config = $config;
        self::$timeStart = microtime(true);
        self::$isInitialized = false; // Reset initialization flag
        
        // Attempt to log basic init even before we fully initialize
        error_log('VideoThumbnail Debug: Initializing debug system with enabled=' . 
            (isset($config['enabled']) && $config['enabled'] ? 'true' : 'false'));
        
        if (!isset($config['enabled']) || !$config['enabled']) {
            self::$config['enabled'] = false;
            self::$initializingFlag = false;
            error_log('VideoThumbnail Debug: Debug not enabled, exiting init early');
            return;
        }

        error_log('VideoThumbnail Debug: Before ensureLogDirectory - Memory: ' . self::formatBytes(memory_get_usage(true)));
        if (!self::ensureLogDirectory()) {
            self::$config['enabled'] = false;
            error_log('VideoThumbnail Debug: Failed to ensure log directory exists');
            self::$initializingFlag = false;
            return;
        }
        
        error_log('VideoThumbnail Debug: Before initLogger - Memory: ' . self::formatBytes(memory_get_usage(true)));
        if (!self::initLogger()) {
            self::$config['enabled'] = false;
            error_log('VideoThumbnail Debug: Failed to initialize logger');
            self::$initializingFlag = false;
            return;
        }
        
        self::$isInitialized = true;
        self::$initializingFlag = false;
        
        // Log system info at startup - but only if initLogger succeeded and we don't take too long
        if (self::$logger && (microtime(true) - $startTime) < 1.0) {
            try {
                error_log('VideoThumbnail Debug: Before logSystemInfo - Memory: ' . self::formatBytes(memory_get_usage(true)));
                self::logSystemInfo();
                error_log('VideoThumbnail Debug: After logSystemInfo - Memory: ' . self::formatBytes(memory_get_usage(true)));
            } catch (\Exception $e) {
                error_log('VideoThumbnail Debug: Error logging system info: ' . $e->getMessage());
            }
        }
        
        $duration = round(microtime(true) - $startTime, 3);
        $endMemory = memory_get_usage(true);
        $memoryUsed = $endMemory - $startMemory;
        error_log(sprintf(
            "VideoThumbnail Debug: Initialization completed in %.3f seconds - Memory: %s (Used: %s)",
            $duration,
            self::formatBytes($endMemory),
            self::formatBytes($memoryUsed)
        ));
    }
    
    /**
     * Format bytes to human-readable format
     */
    private static function formatBytes($bytes, $precision = 2)
    {
        if ($bytes > 0) {
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $base = floor(log($bytes) / log(1024));
            if ($base >= count($units)) $base = count($units) - 1;
            return round($bytes / pow(1024, $base), $precision) . ' ' . $units[$base];
        }
        return '0 B';
    }

    /**
     * Ensure the log directory exists and is writable
     */
    private static function ensureLogDirectory(): bool
    {
        if (!isset(self::$config) || !isset(self::$config['log_dir'])) {
            error_log('VideoThumbnail Debug Error: Log configuration is missing or invalid.');
            return false;
        }

        $logDir = self::$config['log_dir'];
        if (empty($logDir)) {
            error_log('VideoThumbnail Debug Error: Log directory path is empty.');
            return false;
        }

        // Check if directory exists
        if (!is_dir($logDir)) {
            error_log('VideoThumbnail Debug: Log directory does not exist, creating: ' . $logDir);
            // Try to create it with proper error handling
            try {
                if (!@mkdir($logDir, 0755, true)) {
                    $error = error_get_last();
                    error_log('VideoThumbnail Debug Error: Failed to create log directory: ' . $logDir . '. Error: ' . ($error['message'] ?? 'Unknown error'));
                    return false;
                }
                error_log('VideoThumbnail Debug: Log directory created successfully: ' . $logDir);
            } catch (\Exception $e) {
                error_log('VideoThumbnail Debug Error: Exception creating log directory: ' . $e->getMessage());
                return false;
            }
        }
        
        // Check if writable
        if (!is_writable($logDir)) {
            error_log('VideoThumbnail Debug Error: Log directory is not writable: ' . $logDir);
            return false;
        }
        
        return true;
    }

    /**
     * Initialize the logger
     * 
     * @return bool True if logger was initialized successfully
     */
    private static function initLogger()
    {
        // Already have a logger instance
        if (self::$logger !== null) {
            return true;
        }
        
        // Debug disabled
        if (!isset(self::$config['enabled']) || !self::$config['enabled']) {
            return false;
        }
        
        // Check log_file is configured
        if (!isset(self::$config['log_file']) || !self::$config['log_file']) {
            error_log('VideoThumbnail Debug Error: log_file not configured.');
            return false;
        }
        
        // Create log path
        $logPath = self::$config['log_dir'] . DIRECTORY_SEPARATOR . self::$config['log_file'];
        
        // Check file/directory permissions
        if ((file_exists($logPath) && !is_writable($logPath)) ||
            (!file_exists($logPath) && !is_writable(dirname($logPath)))) {
            error_log('VideoThumbnail Debug Error: Log file/directory not writable: ' . $logPath);
            return false;
        }
        
        try {
            // Create file if it doesn't exist
            if (!file_exists($logPath)) {
                $file = @fopen($logPath, 'w');
                if ($file) {
                    fclose($file);
                } else {
                    error_log('VideoThumbnail Debug Error: Could not create log file: ' . $logPath);
                    return false;
                }
            }
            
            // Create and configure logger with error handling
            $writer = new Stream($logPath);
            self::$logger = new Logger();
            self::$logger->addWriter($writer);
            
            // Success
            return true;
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug Error: Failed to initialize logger: ' . $e->getMessage());
            self::$logger = null;
            return false;
        }
    }

    /**
     * Rotate log file if it exceeds maximum size
     */
    private static function rotateLogIfNeeded()
    {
        try {
            if (!isset(self::$config['enabled']) || !self::$config['enabled'] || 
                !isset(self::$config['log_dir']) || !isset(self::$config['log_file']) || 
                !isset(self::$config['max_size'])) {
                return;
            }
            
            $logFile = self::$config['log_dir'] . DIRECTORY_SEPARATOR . self::$config['log_file'];
            if (file_exists($logFile) && @filesize($logFile) > self::$config['max_size']) {
                self::rotateLog();
            }
        } catch (\Exception $e) {
            // Don't use error_log here to avoid potential infinite recursion
            // This is just log rotation, not critical if it fails occasionally
        }
    }

    /**
     * Rotate log file
     */
    private static function rotateLog()
    {
        try {
            if (!isset(self::$config['log_dir']) || !isset(self::$config['log_file']) || !isset(self::$config['max_files'])) {
                error_log('VideoThumbnail Debug Error: Missing configuration for log rotation');
                return;
            }
            
            $logFile = self::$config['log_dir'] . DIRECTORY_SEPARATOR . self::$config['log_file'];
            $maxFiles = self::$config['max_files'];
    
            // Remove oldest log file if we've reached max_files
            $oldLog = $logFile . '.' . $maxFiles;
            if (file_exists($oldLog)) {
                @unlink($oldLog);
            }
    
            // Shift existing log files
            for ($i = $maxFiles - 1; $i >= 0; $i--) {
                $oldFile = $logFile . ($i > 0 ? '.' . $i : '');
                $newFile = $logFile . '.' . ($i + 1);
                if (file_exists($oldFile)) {
                    @rename($oldFile, $newFile);
                }
            }
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug Error: Failed to rotate log: ' . $e->getMessage());
        }
    }

    /**
     * Format a message for logging
     */
    private static function formatMessage($message, $method = null)
    {
        $prefix = 'VideoThumbnail: ';
        if ($method) {
            $message = "[$method] $message";
        }
        return $prefix . $message;
    }

    /**
     * Log an informational message
     * 
     * @param string $message Message to log
     * @param string $method Method identifier
     */
    public static function log($message, $method = null)
    {
        // Use PHP error log as a fallback when debug system isn't initialized
        if (!isset(self::$config) || !isset(self::$config['enabled']) || !self::$config['enabled']) {
            // Log to PHP error log as a backup
            error_log(self::formatMessage($message, $method));
            return;
        }
        
        if (!self::$logger) {
            // Logger not available, use PHP error log
            error_log(self::formatMessage($message, $method));
            return;
        }

        try {
            self::$logger->info(self::formatMessage($message, $method));
            self::rotateLogIfNeeded();
        } catch (\Exception $e) {
            // If we can't log to our logger, use PHP error log
            error_log(self::formatMessage($message, $method));
            error_log('VideoThumbnail Debug Error: Logger exception: ' . $e->getMessage());
        }
    }

    /**
     * Log a warning message
     */
    public static function logWarning($message, $method = null)
    {
        if (!isset(self::$config['enabled']) || !self::$config['enabled']) {
            // For warnings, always log to error_log
            error_log(self::formatMessage($message, $method));
            return;
        }

        if (!self::$logger) {
            // Logger not available, use PHP error log
            error_log(self::formatMessage($message, $method));
            return;
        }

        try {
            self::$logger->warn(self::formatMessage($message, $method));
            error_log(self::formatMessage("WARNING: " . $message, $method)); // Also log to error_log
            self::rotateLogIfNeeded();
        } catch (\Exception $e) {
            // If we can't log to our logger, use PHP error log
            error_log(self::formatMessage($message, $method));
        }
    }

    /**
     * Log an error message
     * 
     * @param string $message Error message
     * @param string $method Method identifier
     * @param \Exception $exception Optional exception
     */
    public static function logError($message, $method = null, \Exception $exception = null)
    {
        // Format message with exception details if provided
        if ($method) {
            $message = "[$method] $message";
        }

        if ($exception) {
            $message .= "\nException: " . $exception->getMessage();
            $message .= "\nStack trace:\n" . $exception->getTraceAsString();
        }
        
        // Always log errors to PHP error_log regardless of debug settings
        $formattedMessage = self::formatMessage($message, $method);
        error_log($formattedMessage);
        
        // If logger is available, also log there
        if (isset(self::$config['enabled']) && self::$config['enabled'] && self::$logger) {
            try {
                self::$logger->err($formattedMessage);
                self::rotateLogIfNeeded();
            } catch (\Exception $e) {
                // If logger fails, we already output to error_log above
                error_log('VideoThumbnail Debug: Logger exception during error logging: ' . $e->getMessage());
            }
        }
    }

    /**
     * Log job progress
     */
    public static function logJobProgress($jobId, $progress, $status = null)
    {
        $message = sprintf(
            "[Job #%d] Progress: %d%% %s",
            $jobId,
            $progress,
            $status ? "Status: $status" : ''
        );

        self::log($message);
    }

    /**
     * Check if debug mode is enabled
     */
    public static function isEnabled()
    {
        // Use config value if available
        if (isset(self::$config) && isset(self::$config['enabled'])) {
            return (bool)self::$config['enabled'];
        }
        
        // Cache the result to avoid repeated settings lookup
        if (self::$debugEnabled !== null) {
            return self::$debugEnabled;
        }
        
        // Default to true during bootstrap
        return true;
    }

    /**
     * Log system information for debugging
     */
    private static function logSystemInfo()
    {
        if (!isset(self::$config['enabled']) || !self::$config['enabled'] || !self::$logger) {
            return;
        }

        try {
            $info = [
                'PHP Version' => phpversion(),
                'OS' => PHP_OS,
                'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'Memory Limit' => ini_get('memory_limit'),
                'Max Execution Time' => ini_get('max_execution_time') . 's',
                'Upload Max Filesize' => ini_get('upload_max_filesize'),
                'Post Max Size' => ini_get('post_max_size'),
            ];

            // Check for FFmpeg
            $ffmpegVersion = 'Not detected';
            if (function_exists('exec')) {
                $output = [];
                $returnVar = -1;
                @exec('ffmpeg -version 2>&1', $output, $returnVar);
                if ($returnVar === 0 && !empty($output)) {
                    // Just get the first line of FFmpeg version
                    $ffmpegVersion = $output[0];
                }
            }
            $info['FFmpeg'] = $ffmpegVersion;

            // Check key directories
            $info['Log Directory'] = self::$config['log_dir'] . ' (exists: ' . 
                (file_exists(self::$config['log_dir']) ? 'Yes' : 'No') . ', writable: ' . 
                (is_writable(self::$config['log_dir']) ? 'Yes' : 'No') . ')';
            
            if (defined('OMEKA_PATH')) {
                $tempDir = OMEKA_PATH . '/files/temp/video-thumbnails';
                $info['Temp Directory'] = $tempDir . ' (exists: ' . 
                    (file_exists($tempDir) ? 'Yes' : 'No') . ', writable: ' . 
                    (is_writable($tempDir) ? 'Yes' : 'No') . ')';
            }
            
            $message = "System Information:\n";
            foreach ($info as $key => $value) {
                $message .= sprintf("- %s: %s\n", $key, $value);
            }

            self::$logger->info(self::formatMessage($message, 'SystemInfo'));
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug Error: Failed to collect system information: ' . $e->getMessage());
        }
    }

    /**
     * Format bytes to human-readable format
     */
    private static function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        return sprintf('%.2f%s', $bytes, $units[$index]);
    }
}