<?php
namespace VideoThumbnail\Stdlib;

use Laminas\Log\Logger;
use Laminas\Log\Writer\Stream;

class Debug
{
    /**
     * Configuration settings
     */
    protected static $config = [
        'enabled' => false,
        'log_dir' => null,
        'log_file' => 'videothumbnail.log',
        'max_size' => 10485760, // 10MB
        'max_files' => 5
    ];

    /**
     * Logger instance
     */
    protected static $logger = null;

    /**
     * Initialization status
     */
    protected static $isInitialized = false;

    /**
     * Debug enabled flag
     */
    protected static $debugEnabled = false;

    /**
     * Log file path
     */
    protected static $logFile = null;

    /**
     * Log directory
     */
    protected static $logDir = null;

    /**
     * Start time
     */
    protected static $timeStart = null;

    /**
     * Peak memory usage
     */
    protected static $memoryPeak = 0;

    /**
     * Memory reset counter
     */
    protected static $memoryResetCount = 0;

    /**
     * Initialize debug system
     */
    public static function init($config = null)
    {
        // Prevent multiple initialization
        if (self::$isInitialized) {
            return;
        }

        try {
            // Early exit if config is null
            if ($config === null) {
                self::$debugEnabled = false;
                return;
            }

            // Merge config safely
            if (is_array($config)) {
                self::$config = array_merge(self::$config, $config);
            }

            // Enable debugging if configured
            self::$debugEnabled = !empty(self::$config['enabled']);

            // If debugging is disabled, exit early
            if (!self::$debugEnabled) {
                return;
            }

            // Determine log directory safely
            if (!empty(self::$config['log_dir']) && is_string(self::$config['log_dir'])) {
                self::$logDir = rtrim(self::$config['log_dir'], DIRECTORY_SEPARATOR);
            } elseif (defined('OMEKA_PATH')) {
                self::$logDir = rtrim(OMEKA_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'logs';
            } else {
                error_log('VideoThumbnail Debug: Cannot determine log directory');
                self::$debugEnabled = false;
                return;
            }

            // Ensure log directory exists
            if (!is_dir(self::$logDir)) {
                if (!@mkdir(self::$logDir, 0755, true)) {
                    error_log('VideoThumbnail Debug: Failed to create log directory');
                    self::$debugEnabled = false;
                    return;
                }
            }

            // Check if log directory is writable
            if (!is_writable(self::$logDir)) {
                error_log('VideoThumbnail Debug: Log directory not writable');
                self::$debugEnabled = false;
                return;
            }

            // Construct log file path
            if (!empty(self::$config['log_file']) && is_string(self::$config['log_file'])) {
                self::$logFile = self::$logDir . DIRECTORY_SEPARATOR . self::$config['log_file'];
            } else {
                self::$logFile = self::$logDir . DIRECTORY_SEPARATOR . 'videothumbnail.log';
            }

            // Initialize logger
            try {
                $writer = new Stream(self::$logFile);
                self::$logger = new Logger();
                self::$logger->addWriter($writer);
                self::$isInitialized = true;
                self::$timeStart = microtime(true);
                self::$memoryPeak = memory_get_usage(true);
            } catch (\Exception $e) {
                error_log('VideoThumbnail Debug: Failed to initialize logger: ' . $e->getMessage());
                self::$debugEnabled = false;
            }

            // Force garbage collection
            gc_enable();

        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug: Initialization failed: ' . $e->getMessage());
            self::$debugEnabled = false;
        }
    }

    /**
     * Check if debugging is enabled
     */
    public static function isEnabled()
    {
        return self::$debugEnabled && self::$isInitialized && self::$logger !== null;
    }

    /**
     * Log a message
     */
    public static function log($message, $method = null)
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            $formatted = self::formatMessage($message, $method);
            self::$logger->info($formatted);
            self::rotateLogIfNeeded();
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug log error: ' . $e->getMessage());
        }
    }

    /**
     * Log an entry point (method start)
     */
    public static function logEntry($method, $params = null)
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            $message = "ENTRY: $method";
            if ($params !== null) {
                if (is_array($params)) {
                    $message .= ' - Params: ' . json_encode($params);
                } else {
                    $message .= ' - Params: ' . $params;
                }
            }
            self::log($message, $method);
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug logEntry error: ' . $e->getMessage());
        }
    }

    /**
     * Log an exit point (method end)
     */
    public static function logExit($method, $result = null)
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            $message = "EXIT: $method";
            if ($result !== null) {
                if (is_array($result) || is_object($result)) {
                    $message .= ' - Result: ' . json_encode($result);
                } else {
                    $message .= ' - Result: ' . $result;
                }
            }
            self::log($message, $method);
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug logExit error: ' . $e->getMessage());
        }
    }

    /**
     * Log a warning message
     */
    public static function logWarning($message, $method = null)
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            $formatted = self::formatMessage("WARNING: $message", $method);
            self::$logger->warn($formatted);
            self::rotateLogIfNeeded();
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug logWarning error: ' . $e->getMessage());
        }
    }

    /**
     * Log an error message
     */
    public static function logError($message, $method = null, $exception = null)
    {
        if (!self::isEnabled()) {
            // Always log errors to PHP error log if debug is disabled
            error_log('VideoThumbnail Error: ' . $message);
            return;
        }

        try {
            $formatted = self::formatMessage("ERROR: $message", $method);
            
            if ($exception instanceof \Exception) {
                $formatted .= "\nException: " . get_class($exception) . 
                             "\nMessage: " . $exception->getMessage() .
                             "\nTrace: " . $exception->getTraceAsString();
            }
            
            self::$logger->err($formatted);
            self::rotateLogIfNeeded();
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug logError error: ' . $e->getMessage());
        }
    }

    /**
     * Log form validation errors
     */
    public static function logFormValidation($messages, $method = null)
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            $formatted = self::formatMessage("FORM VALIDATION: " . json_encode($messages), $method);
            self::$logger->info($formatted);
            self::rotateLogIfNeeded();
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug logFormValidation error: ' . $e->getMessage());
        }
    }

    /**
     * Log form data
     */
    public static function dumpFormData($data, $phase = null, $method = null)
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            $message = "FORM DATA";
            if ($phase !== null) {
                $message .= " ($phase)";
            }
            $message .= ": " . json_encode($data);
            
            $formatted = self::formatMessage($message, $method);
            self::$logger->info($formatted);
            self::rotateLogIfNeeded();
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug dumpFormData error: ' . $e->getMessage());
        }
    }

    /**
     * Log setting changes
     */
    public static function logSettingChange($setting, $oldValue, $newValue, $method = null)
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            $message = "SETTING CHANGE: $setting";
            
            // Handle special cases for output formatting
            if (is_array($oldValue)) {
                $oldValueStr = json_encode($oldValue);
            } elseif ($oldValue === null) {
                $oldValueStr = 'null';
            } elseif (is_bool($oldValue)) {
                $oldValueStr = $oldValue ? 'true' : 'false';
            } else {
                $oldValueStr = (string) $oldValue;
            }
            
            if (is_array($newValue)) {
                $newValueStr = json_encode($newValue);
            } elseif ($newValue === null) {
                $newValueStr = 'null';
            } elseif (is_bool($newValue)) {
                $newValueStr = $newValue ? 'true' : 'false';
            } else {
                $newValueStr = (string) $newValue;
            }
            
            $message .= " from '$oldValueStr' to '$newValueStr'";
            
            $formatted = self::formatMessage($message, $method);
            self::$logger->info($formatted);
            self::rotateLogIfNeeded();
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug logSettingChange error: ' . $e->getMessage());
        }
    }

    /**
     * Log configuration actions
     */
    public static function logConfigAction($action, $data, $method = null)
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            $message = "CONFIG ACTION: $action";
            
            if (!empty($data)) {
                $message .= " - " . json_encode($data);
            }
            
            $formatted = self::formatMessage($message, $method);
            self::$logger->info($formatted);
            self::rotateLogIfNeeded();
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug logConfigAction error: ' . $e->getMessage());
        }
    }

    /**
     * Log a call stack trace
     */
    public static function traceCallStack($limit = 10, $reason = null)
    {
        if (!self::isEnabled()) {
            return;
        }

        try {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit + 1);
            array_shift($trace); // Remove this method from stack
            
            $callStack = '';
            if ($reason !== null) {
                $callStack = "TRACE ($reason):\n";
            } else {
                $callStack = "TRACE:\n";
            }
            
            foreach ($trace as $i => $call) {
                $class = isset($call['class']) ? $call['class'] : '';
                $type = isset($call['type']) ? $call['type'] : '';
                $function = isset($call['function']) ? $call['function'] : '';
                $file = isset($call['file']) ? $call['file'] : 'unknown';
                $line = isset($call['line']) ? $call['line'] : 0;
                
                $callStack .= sprintf("#%d %s%s%s() called at [%s:%d]\n", 
                    $i, $class, $type, $function, $file, $line);
            }
            
            $formatted = self::formatMessage($callStack);
            self::$logger->info($formatted);
            self::rotateLogIfNeeded();
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug traceCallStack error: ' . $e->getMessage());
        }
    }

    /**
     * Format a log message
     */
    protected static function formatMessage($message, $method = null)
    {
        $formattedMessage = '';
        
        // Add timestamp
        $formattedMessage .= '[' . date('Y-m-d H:i:s') . '] ';
        
        // Add memory usage
        $memoryUsage = memory_get_usage(true);
        $formattedMessage .= self::formatBytes($memoryUsage) . ' ';
        
        // Add method if provided
        if ($method !== null) {
            $formattedMessage .= "[$method] ";
        }
        
        // Add the actual message
        $formattedMessage .= $message;
        
        return $formattedMessage;
    }

    /**
     * Format bytes to human-readable format
     */
    protected static function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Check if log file needs rotation
     */
    private static function rotateLogIfNeeded()
    {
        if (!self::$debugEnabled || !self::$isInitialized) {
            return;
        }
        
        try {
            // Ensure we have a valid log file path
            if (empty(self::$logFile) || !is_string(self::$logFile)) {
                return;
            }
            
            // Check if file exists and is readable
            if (!file_exists(self::$logFile)) {
                return;
            }
            
            // Check size threshold
            $maxSize = isset(self::$config['max_size']) ? self::$config['max_size'] : 10485760;
            
            if (filesize(self::$logFile) > $maxSize) {
                self::rotateLog();
            }
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug rotateLogIfNeeded error: ' . $e->getMessage());
        }
    }

    /**
     * Rotate log files
     */
    private static function rotateLog()
    {
        if (!self::$debugEnabled || !self::$isInitialized) {
            return;
        }
        
        try {
            // Check if we have necessary configuration
            if (empty(self::$config['max_files']) || !is_numeric(self::$config['max_files']) || 
                empty(self::$logFile) || !is_string(self::$logFile)) {
                return;
            }
            
            $maxFiles = (int)self::$config['max_files'];
            
            // Remove oldest log file
            $oldLog = self::$logFile . '.' . $maxFiles;
            if (file_exists($oldLog)) {
                @unlink($oldLog);
            }
            
            // Shift log files - safeguarded version
            for ($i = $maxFiles - 1; $i >= 0; $i--) {
                $oldFile = ($i == 0) ? self::$logFile : (self::$logFile . '.' . $i);
                $newFile = self::$logFile . '.' . ($i + 1);
                
                // Ensure oldFile is valid before checking if it exists
                if (is_string($oldFile) && $oldFile !== '' && file_exists($oldFile)) {
                    @rename($oldFile, $newFile);
                }
            }
            
            // Create a new empty log file
            @touch(self::$logFile);
            
            // Reinitialize logger
            try {
                $writer = new Stream(self::$logFile);
                self::$logger = new Logger();
                self::$logger->addWriter($writer);
                
                // Log rotation success
                self::$logger->info(self::formatMessage("Log rotated successfully"));
            } catch (\Exception $e) {
                error_log('VideoThumbnail Debug: Failed to reinitialize logger after rotation: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug rotateLog error: ' . $e->getMessage());
        }
    }

    /**
     * Initialize memory management
     */
    public static function initializeMemoryManagement($config = null)
    {
        if (!self::$debugEnabled || !self::$isInitialized) {
            return;
        }
        
        try {
            // Set initial memory peak
            self::$memoryPeak = memory_get_usage(true);
            self::$memoryResetCount = 0;
            
            // Force garbage collection
            if (gc_enabled()) {
                gc_collect_cycles();
            }
            
            self::log(sprintf(
                "Memory management initialized. Current usage: %s",
                self::formatBytes(memory_get_usage(true))
            ));
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug initializeMemoryManagement error: ' . $e->getMessage());
        }
    }

    /**
     * Check memory usage and clean up if needed
     */
    public static function checkMemoryUsage($forceCleanup = false)
    {
        if (!self::$debugEnabled || !self::$isInitialized) {
            return;
        }
        
        try {
            $currentUsage = memory_get_usage(true);
            $peakUsage = memory_get_peak_usage(true);
            $percentUsed = 0;
            
            // Calculate memory limit
            $memoryLimit = ini_get('memory_limit');
            $memoryLimitBytes = self::convertToBytes($memoryLimit);
            
            if ($memoryLimitBytes > 0) {
                $percentUsed = ($currentUsage / $memoryLimitBytes) * 100;
            }
            
            $threshold = isset(self::$config['memory_management']['memory_reset_threshold']) ? 
                self::convertToBytes(self::$config['memory_management']['memory_reset_threshold']) : 
                384 * 1024 * 1024; // Default 384MB
            
            // Check if we need to clean up
            if ($forceCleanup || $currentUsage > $threshold || $percentUsed > 75) {
                self::performMemoryCleanup();
            }
            
            return [
                'current' => $currentUsage,
                'peak' => $peakUsage,
                'percentUsed' => $percentUsed,
            ];
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug checkMemoryUsage error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Perform memory cleanup
     */
    private static function performMemoryCleanup()
    {
        self::$memoryResetCount++;
        self::$memoryPeak = memory_get_peak_usage(true);
        
        // Force garbage collection
        if (gc_enabled()) {
            gc_collect_cycles();
        }
        
        // Log only if initialized
        if (self::$isInitialized && self::$logger) {
            self::log(sprintf(
                "Memory cleanup performed (%d). Before: %s, After: %s",
                self::$memoryResetCount,
                self::formatBytes(self::$memoryPeak),
                self::formatBytes(memory_get_usage(true))
            ));
        }
    }

    /**
     * Convert memory limit string to bytes
     */
    private static function convertToBytes($value)
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $value = (int)$value;
        
        switch($unit) {
            case 'g':
                $value *= 1024;
                // Fall through
            case 'm':
                $value *= 1024;
                // Fall through
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
}