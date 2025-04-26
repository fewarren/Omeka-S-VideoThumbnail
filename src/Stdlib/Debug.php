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

    /**
     * Initialize the logger if it hasn't been yet
     */
    protected static function init()
    {
        if (self::$isInitialized) {
            return;
        }
        
        try {
            // Set log directory and file
            $baseDir = dirname(dirname(dirname(__FILE__)));
            self::$logDir = $baseDir . '/log';
            
            // Create log directory if it doesn't exist
            if (!file_exists(self::$logDir)) {
                mkdir(self::$logDir, 0755, true);
            }
            
            self::$logFile = self::$logDir . '/videothumbnail-' . date('Y-m-d') . '.log';
            
            // Create and configure logger
            self::$logger = new Logger();
            $writer = new Stream(self::$logFile);
            self::$logger->addWriter($writer);
            
            self::$isInitialized = true;
            
            // Log initialization success
            self::log("Debug logger initialized. Log file: " . self::$logFile, __METHOD__);
        } catch (\Exception $e) {
            // If we can't initialize the logger, write to PHP error log
            error_log("VideoThumbnail Debug logger initialization failed: " . $e->getMessage());
            self::$isInitialized = false;
        }
    }

    public static function init($config)
    {
        self::$config = $config;
        self::$timeStart = microtime(true);
        
        // Attempt to log basic init even before we fully initialize
        error_log('VideoThumbnail Debug: Initializing debug system with enabled=' . 
            (self::$config['enabled'] ? 'true' : 'false'));
        
        if (!self::$config['enabled']) {
            return;
        }

        if (!self::ensureLogDirectory()) {
            self::$config['enabled'] = false;
            error_log('VideoThumbnail Debug: Failed to ensure log directory exists');
            return;
        }
        
        if (!self::initLogger()) {
            self::$config['enabled'] = false;
            error_log('VideoThumbnail Debug: Failed to initialize logger');
            return;
        }
        
        // Log system info at startup
        self::logSystemInfo();
    }

    private static function ensureLogDirectory(): bool
    {
        $logDir = self::$config['log_dir'];
        if (empty($logDir)) {
            error_log('VideoThumbnail Debug Error: Log directory path is empty.');
            return false;
        }

        if (!is_dir($logDir)) {
            if (!@mkdir($logDir, 0755, true)) {
                $error = error_get_last();
                error_log('VideoThumbnail Debug Error: Failed to create log directory: ' . $logDir . '. Error: ' . ($error['message'] ?? 'Unknown error'));
                return false;
            }
        } elseif (!is_writable($logDir)) {
            error_log('VideoThumbnail Debug Error: Log directory is not writable: ' . $logDir);
            return false;
        }
        return true;
    }

    private static function initLogger()
    {
        if (self::$logger !== null || !self::$config['enabled']) {
            // If already initialized or disabled (possibly by ensureLogDirectory), return.
            return; 
        }
        if (!isset(self::$config['log_file']) || !self::$config['log_file']) {
             error_log('VideoThumbnail Debug Error: log_file not configured.'); // Log error
            self::$config['enabled'] = false;
            return;
        }
        $logPath = self::$config['log_dir'] . DIRECTORY_SEPARATOR . self::$config['log_file']; // Use DIRECTORY_SEPARATOR
        if ((file_exists($logPath) && !is_writable($logPath)) ||
            (!file_exists($logPath) && !is_writable(dirname($logPath)))) {
            error_log('VideoThumbnail Debug Error: Log file/directory not writable: ' . $logPath); // Log error
            self::$config['enabled'] = false;
            return;
        }
        
        try { // Wrap in try-catch for safety
            $writer = new Stream($logPath);
            self::$logger = new Logger();
            self::$logger->addWriter($writer);
        } catch (\Exception $e) {
            error_log('VideoThumbnail Debug Error: Failed to initialize logger: ' . $e->getMessage()); // Log error
            self::$config['enabled'] = false;
            self::$logger = null; // Ensure logger is null if failed
        }
    }

    private static function rotateLogIfNeeded()
    {
        if (!self::$config['enabled']) {
            return;
        }

        $logFile = self::$config['log_dir'] . DIRECTORY_SEPARATOR . self::$config['log_file'];
        if (file_exists($logFile) && filesize($logFile) > self::$config['max_size']) {
            self::rotateLog();
        }
    }

    private static function rotateLog()
    {
        $logFile = self::$config['log_dir'] . DIRECTORY_SEPARATOR . self::$config['log_file'];
        $maxFiles = self::$config['max_files'];

        // Remove oldest log file if we've reached max_files
        $oldLog = $logFile . '.' . $maxFiles;
        if (file_exists($oldLog)) {
            unlink($oldLog);
        }

        // Shift existing log files
        for ($i = $maxFiles - 1; $i >= 0; $i--) {
            $oldFile = $logFile . ($i > 0 ? '.' . $i : '');
            $newFile = $logFile . '.' . ($i + 1);
            if (file_exists($oldFile)) {
                rename($oldFile, $newFile);
            }
        }
    }

    private static function formatMessage($message, $method = null)
    {
        $prefix = 'VideoThumbnail: ';
        if ($method) {
            $message = "[$method] $message";
        }
        return $prefix . $message;
    }

    public static function logEntry($method, $params = [])
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }

        $memory = memory_get_usage(true);
        self::$memoryPeak = max(self::$memoryPeak, $memory);

        $message = sprintf(
            "[PID:%d] [MEM:%s/%s] [TIME:%.3fs] %s",
            getmypid(),
            self::formatBytes($memory),
            self::formatBytes(self::$memoryPeak),
            microtime(true) - self::$timeStart,
            $method
        );

        if (!empty($params)) {
            $message .= "\nParameters: " . json_encode($params, JSON_PRETTY_PRINT);
        }

        self::$logger->debug(self::formatMessage($message));
        self::rotateLogIfNeeded();
    }

    public static function log($message, $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }

        self::$logger->info(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }

    public static function logWarning($message, $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }

        self::$logger->warn(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }

    public static function logError($message, $method = null, \Exception $exception = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }

        if ($method) {
            $message = "[$method] $message";
        }

        if ($exception) {
            $message .= "\nException: " . $exception->getMessage();
            $message .= "\nStack trace:\n" . $exception->getTraceAsString();
        }

        self::$logger->err(self::formatMessage($message, $method));
        error_log(self::formatMessage($message, $method)); // Also log to error_log for critical errors
        self::rotateLogIfNeeded();
    }

    public static function logJobProgress($jobId, $progress, $status = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }

        $message = sprintf(
            "[Job #%d] Progress: %d%% %s",
            $jobId,
            $progress,
            $status ? "Status: $status" : ''
        );

        self::$logger->info(self::formatMessage($message));
        self::rotateLogIfNeeded();
    }

    public static function logJobError($jobId, $error, $retry = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }

        $message = sprintf(
            "[Job #%d] Error: %s%s",
            $jobId,
            $error,
            $retry !== null ? " (Retry #$retry)" : ''
        );

        self::$logger->err(self::formatMessage($message));
        error_log(self::formatMessage($message)); // Also log to error_log for critical job errors
        self::rotateLogIfNeeded();
    }

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

    public static function getMemoryPeak()
    {
        return self::$memoryPeak;
    }

    public static function getElapsedTime()
    {
        return microtime(true) - self::$timeStart;
    }

    /**
     * Logs system environment information to help with troubleshooting
     */
    private static function logSystemInfo()
    {
        if (!self::$config['enabled'] || !self::$logger) {
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
            self::$logger->err(self::formatMessage(
                'Failed to collect system information: ' . $e->getMessage(),
                'SystemInfo'
            ));
        }
    }

    /**
     * Specialized method for logging admin configuration form events
     * 
     * @param string $action The action being performed (load, validate, save, etc)
     * @param array $data Associated data for the action
     * @param string $method Calling method identifier
     */
    public static function logConfigAction($action, array $data = [], $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }

        $message = sprintf(
            "ADMIN CONFIG: %s\nData: %s",
            $action,
            !empty($data) ? json_encode($data, JSON_PRETTY_PRINT) : 'No data'
        );

        self::$logger->info(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Log form validation issues
     * 
     * @param array $messages Form validation error messages
     * @param string $method Calling method identifier  
     */
    public static function logFormValidation(array $messages, $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }

        $formatted = [];
        foreach ($messages as $field => $errors) {
            $errorMessages = is_array($errors) ? implode(', ', array.map(function($e) {
                return is_array($e) ? implode(', ', $e) : $e;
            }, $errors)) : $errors;
            
            $formatted[] = "$field: $errorMessages";
        }

        $message = "FORM VALIDATION ERRORS:\n" . implode("\n", $formatted);
        self::$logger->warn(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Dump form data for debugging purposes
     * 
     * @param array $formData Form data to dump
     * @param string $stage Processing stage description 
     * @param string $method Calling method identifier
     */
    public static function dumpFormData(array $formData, $stage = 'unknown', $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        // Sanitize any sensitive data if needed
        $sanitized = $formData;
        
        $message = sprintf(
            "FORM DATA DUMP [Stage: %s]\n%s",
            $stage,
            json_encode($sanitized, JSON_PRETTY_PRINT)
        );
        
        self::$logger->debug(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Check if debug mode is enabled
     * 
     * @return bool True if debugging is enabled
     */
    public static function isEnabled()
    {
        // Cache the result to avoid repeated settings lookup
        if (self::$debugEnabled !== null) {
            return self::$debugEnabled;
        }
        
        try {
            // Try to get the setting from the service manager
            $serviceLocator = \Omeka\Module::getServiceLocator();
            if ($serviceLocator) {
                $settings = $serviceLocator->get('Omeka\Settings');
                self::$debugEnabled = (bool) $settings->get('videothumbnail_debug_mode', false);
            } else {
                // Default to false if service locator not available
                self::$debugEnabled = false;
            }
        } catch (\Exception $e) {
            // Default to false if any error occurs
            self::$debugEnabled = false;
            error_log("VideoThumbnail: Error checking debug mode: " . $e->getMessage());
        }
        
        return self::$debugEnabled;
    }
    
    /**
     * Log exit from method for tracing execution flow
     * 
     * @param string $method Method name
     * @param mixed $result Optional result data
     */
    public static function logExit($method, $result = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        $memory = memory_get_usage(true);
        self::$memoryPeak = max(self::$memoryPeak, $memory);

        $message = sprintf(
            "[PID:%d] [MEM:%s/%s] [TIME:%.3fs] EXIT: %s",
            getmypid(),
            self::formatBytes($memory),
            self::formatBytes(self::$memoryPeak),
            microtime(true) - self::$timeStart,
            $method
        );

        if ($result !== null) {
            if (is_array($result) || is_object($result)) {
                $message .= "\nResult: " . json_encode($result, JSON_PRETTY_PRINT);
            } else {
                $message .= "\nResult: " . $result;
            }
        }

        self::$logger->debug(self::formatMessage($message));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Log settings changes
     * 
     * @param string $key Setting key
     * @param mixed $oldValue Previous value
     * @param mixed $newValue New value
     * @param string $method Method identifier
     */
    public static function logSettingChange($key, $oldValue, $newValue, $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        // Format values for logging
        $oldFormatted = is_array($oldValue) || is_object($oldValue) 
            ? json_encode($oldValue, JSON_PRETTY_PRINT) 
            : (string)$oldValue;
        
        $newFormatted = is_array($newValue) || is_object($newValue) 
            ? json_encode($newValue, JSON_PRETTY_PRINT) 
            : (string)$newValue;
        
        $message = sprintf(
            "SETTING CHANGED: '%s'\nOld value: %s\nNew value: %s",
            $key,
            $oldFormatted,
            $newFormatted
        );
        
        self::$logger->info(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Log admin settings workflow state transitions
     * 
     * @param string $from Previous state
     * @param string $to New state 
     * @param array $context Additional context information
     * @param string $method Method identifier
     */
    public static function logWorkflowTransition($from, $to, array $context = [], $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        $message = sprintf(
            "WORKFLOW TRANSITION: %s → %s",
            $from,
            $to
        );
        
        if (!empty($context)) {
            $message .= "\nContext: " . json_encode($context, JSON_PRETTY_PRINT);
        }
        
        self::$logger->info(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Log detailed admin form processing steps
     * 
     * @param string $step Description of the processing step
     * @param array $data Data relevant to this step
     * @param string $method Method identifier
     */
    public static function logFormProcessingStep($step, array $data = [], $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        $message = sprintf(
            "FORM PROCESSING [%s]", 
            $step
        );
        
        if (!empty($data)) {
            // Sanitize sensitive data if needed
            $sanitized = $data;
            $message .= "\nData: " . json_encode($sanitized, JSON_PRETTY_PRINT);
        }
        
        self::$logger->debug(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Start timing an operation for performance monitoring
     * 
     * @param string $operationId Unique identifier for the operation
     * @param string $description Description of the operation
     */
    public static function startOperation($operationId, $description = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        // Store start time in static array
        static $operations = [];
        
        $operations[$operationId] = [
            'start' => microtime(true),
            'description' => $description,
            'memory_start' => memory_get_usage(true)
        ];
        
        $message = sprintf(
            "OPERATION STARTED: %s%s",
            $operationId,
            $description ? " - $description" : ''
        );
        
        self::$logger->debug(self::formatMessage($message));
        self::rotateLogIfNeeded();
    }
    
    /**
     * End timing an operation and log duration
     * 
     * @param string $operationId Identifier matching a previous startOperation call
     * @param array $result Optional result data
     */
    public static function endOperation($operationId, array $result = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        static $operations = [];
        
        if (!isset($operations[$operationId])) {
            self::$logger->warn(self::formatMessage("OPERATION END: No matching start found for '$operationId'"));
            return;
        }
        
        $end = microtime(true);
        $duration = $end - $operations[$operationId]['start'];
        $memoryDelta = memory_get_usage(true) - $operations[$operationId]['memory_start'];
        
        $message = sprintf(
            "OPERATION ENDED: %s - Duration: %.4fs - Memory delta: %s%s",
            $operationId,
            $duration,
            self::formatBytes($memoryDelta),
            $operations[$operationId]['description'] ? " - " . $operations[$operationId]['description'] : ''
        );
        
        if ($result !== null) {
            $message .= "\nResult: " . json_encode($result, JSON_PRETTY_PRINT);
        }
        
        self::$logger->debug(self::formatMessage($message));
        self::rotateLogIfNeeded();
        
        // Clean up
        unset($operations[$operationId]);
    }
    
    /**
     * Log admin user action for audit trail
     * 
     * @param string $action Description of user action
     * @param string $userId User ID if available
     * @param array $details Additional details about the action
     */
    public static function logAdminAction($action, $userId = null, array $details = [])
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        $message = sprintf(
            "ADMIN ACTION: %s%s",
            $action,
            $userId ? " (User: $userId)" : ''
        );
        
        if (!empty($details)) {
            $message .= "\nDetails: " . json_encode($details, JSON_PRETTY_PRINT);
        }
        
        self::$logger->info(self::formatMessage($message));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Log API interactions related to admin settings
     * 
     * @param string $endpoint API endpoint
     * @param string $method HTTP method (GET, POST, etc.)
     * @param array $requestData Request data (if applicable)
     * @param array $responseData Response data (if applicable)
     * @param int $statusCode HTTP status code (if applicable)
     */
    public static function logApiInteraction($endpoint, $method, array $requestData = null, array $responseData = null, $statusCode = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        $message = sprintf(
            "API INTERACTION: %s %s%s",
            $method,
            $endpoint,
            $statusCode ? " (Status: $statusCode)" : ''
        );
        
        if ($requestData !== null) {
            // Sanitize sensitive data if needed
            $message .= "\nRequest: " . json_encode($requestData, JSON_PRETTY_PRINT);
        }
        
        if ($responseData !== null) {
            // Possibly truncate very large responses
            $message .= "\nResponse: " . json_encode($responseData, JSON_PRETTY_PRINT);
        }
        
        self::$logger->info(self::formatMessage($message));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Log settings changes
     * 
     * @param string $key Setting key
     * @param mixed $oldValue Previous value
     * @param mixed $newValue New value
     * @param string $method Method identifier
     */
    public static function logSettingChange($key, $oldValue, $newValue, $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        // Format values for logging
        $oldFormatted = is_array($oldValue) || is_object($oldValue) 
            ? json_encode($oldValue, JSON_PRETTY_PRINT) 
            : (string)$oldValue;
        
        $newFormatted = is_array($newValue) || is_object($newValue) 
            ? json_encode($newValue, JSON_PRETTY_PRINT) 
            : (string)$newValue;
        
        $message = sprintf(
            "SETTING CHANGED: '%s'\nOld value: %s\nNew value: %s",
            $key,
            $oldFormatted,
            $newFormatted
        );
        
        self::$logger->info(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Log admin settings workflow state transitions
     * 
     * @param string $from Previous state
     * @param string $to New state 
     * @param array $context Additional context information
     * @param string $method Method identifier
     */
    public static function logWorkflowTransition($from, $to, array $context = [], $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        $message = sprintf(
            "WORKFLOW TRANSITION: %s → %s",
            $from,
            $to
        );
        
        if (!empty($context)) {
            $message .= "\nContext: " . json_encode($context, JSON_PRETTY_PRINT);
        }
        
        self::$logger->info(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Log detailed admin form processing steps
     * 
     * @param string $step Description of the processing step
     * @param array $data Data relevant to this step
     * @param string $method Method identifier
     */
    public static function logFormProcessingStep($step, array $data = [], $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        $message = sprintf(
            "FORM PROCESSING [%s]", 
            $step
        );
        
        if (!empty($data)) {
            // Sanitize sensitive data if needed
            $sanitized = $data;
            $message .= "\nData: " . json_encode($sanitized, JSON_PRETTY_PRINT);
        }
        
        self::$logger->debug(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Start timing an operation for performance monitoring
     * 
     * @param string $operationId Unique identifier for the operation
     * @param string $description Description of the operation
     */
    public static function startOperation($operationId, $description = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        // Store start time in static array
        static $operations = [];
        
        $operations[$operationId] = [
            'start' => microtime(true),
            'description' => $description,
            'memory_start' => memory_get_usage(true)
        ];
        
        $message = sprintf(
            "OPERATION STARTED: %s%s",
            $operationId,
            $description ? " - $description" : ''
        );
        
        self::$logger->debug(self::formatMessage($message));
        self::rotateLogIfNeeded();
    }
    
    /**
     * End timing an operation and log duration
     * 
     * @param string $operationId Identifier matching a previous startOperation call
     * @param array $result Optional result data
     */
    public static function endOperation($operationId, array $result = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        static $operations = [];
        
        if (!isset($operations[$operationId])) {
            self::$logger->warn(self::formatMessage("OPERATION END: No matching start found for '$operationId'"));
            return;
        }
        
        $end = microtime(true);
        $duration = $end - $operations[$operationId]['start'];
        $memoryDelta = memory_get_usage(true) - $operations[$operationId]['memory_start'];
        
        $message = sprintf(
            "OPERATION ENDED: %s - Duration: %.4fs - Memory delta: %s%s",
            $operationId,
            $duration,
            self::formatBytes($memoryDelta),
            $operations[$operationId]['description'] ? " - " . $operations[$operationId]['description'] : ''
        );
        
        if ($result !== null) {
            $message .= "\nResult: " . json_encode($result, JSON_PRETTY_PRINT);
        }
        
        self::$logger->debug(self::formatMessage($message));
        self::rotateLogIfNeeded();
        
        // Clean up
        unset($operations[$operationId]);
    }
    
    /**
     * Log admin user action for audit trail
     * 
     * @param string $action Description of user action
     * @param string $userId User ID if available
     * @param array $details Additional details about the action
     */
    public static function logAdminAction($action, $userId = null, array $details = [])
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        $message = sprintf(
            "ADMIN ACTION: %s%s",
            $action,
            $userId ? " (User: $userId)" : ''
        );
        
        if (!empty($details)) {
            $message .= "\nDetails: " . json_encode($details, JSON_PRETTY_PRINT);
        }
        
        self::$logger->info(self::formatMessage($message));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Log API interactions related to admin settings
     * 
     * @param string $endpoint API endpoint
     * @param string $method HTTP method (GET, POST, etc.)
     * @param array $requestData Request data (if applicable)
     * @param array $responseData Response data (if applicable)
     * @param int $statusCode HTTP status code (if applicable)
     */
    public static function logApiInteraction($endpoint, $method, array $requestData = null, array $responseData = null, $statusCode = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        $message = sprintf(
            "API INTERACTION: %s %s%s",
            $method,
            $endpoint,
            $statusCode ? " (Status: $statusCode)" : ''
        );
        
        if ($requestData !== null) {
            // Sanitize sensitive data if needed
            $message .= "\nRequest: " . json_encode($requestData, JSON_PRETTY_PRINT);
        }
        
        if ($responseData !== null) {
            // Possibly truncate very large responses
            $message .= "\nResponse: " . json_encode($responseData, JSON_PRETTY_PRINT);
        }
        
        self::$logger->info(self::formatMessage($message));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Log configuration form state for debugging
     * 
     * @param array $formData The form data being processed
     * @param string $context Contextual information about where the form is in the workflow
     * @param string $method Calling method identifier
     */
    public static function logConfigFormState(array $formData, $context, $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        // Remove any sensitive data before logging
        $safeFormData = $formData;
        if (isset($safeFormData['api_key'])) {
            $safeFormData['api_key'] = '[REDACTED]';
        }
        
        $message = sprintf(
            "CONFIG FORM STATE [%s]:\n%s", 
            $context,
            json_encode($safeFormData, JSON_PRETTY_PRINT)
        );
        
        self::$logger->info(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Log the complete environment for configuration troubleshooting
     * 
     * @param string $message Optional message to include
     */
    public static function logConfigEnvironment($message = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        try {
            // Basic server information
            $envInfo = [
                'timestamp' => date('Y-m-d H:i:s'),
                'server' => [
                    'php_version' => phpversion(),
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time') . 's',
                    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
                ]
            ];
            
            // Check config directory permissions
            $baseDir = dirname(dirname(dirname(__FILE__)));
            $configDir = $baseDir . '/config';
            $envInfo['directories'] = [
                'config_dir' => [
                    'path' => $configDir,
                    'exists' => file_exists($configDir) ? 'Yes' : 'No',
                    'writable' => is_writable($configDir) ? 'Yes' : 'No'
                ],
                'log_dir' => [
                    'path' => self::$config['log_dir'],
                    'exists' => file_exists(self::$config['log_dir']) ? 'Yes' : 'No',
                    'writable' => is_writable(self::$config['log_dir']) ? 'Yes' : 'No'
                ]
            ];
            
            // Check for module INI file
            $iniFile = $configDir . '/module.ini';
            $envInfo['module_ini'] = [
                'path' => $iniFile,
                'exists' => file_exists($iniFile) ? 'Yes' : 'No',
                'writable' => file_exists($iniFile) && is_writable($iniFile) ? 'Yes' : 'No'
            ];
            
            if (file_exists($iniFile)) {
                $envInfo['module_ini']['size'] = filesize($iniFile) . ' bytes';
                $envInfo['module_ini']['modified'] = date('Y-m-d H:i:s', filemtime($iniFile));
            }
            
            $logMessage = "CONFIGURATION ENVIRONMENT:\n";
            if ($message) {
                $logMessage .= "Context: $message\n";
            }
            $logMessage .= json_encode($envInfo, JSON_PRETTY_PRINT);
            
            self::$logger->info(self::formatMessage($logMessage, 'ConfigEnvironment'));
        } catch (\Exception $e) {
            self::$logger->err(self::formatMessage(
                'Failed to log configuration environment: ' . $e->getMessage(),
                'ConfigEnvironment'
            ));
        }
    }
    
    /**
     * Track a step in the configuration workflow
     * 
     * @param string $step The current step in the process
     * @param array $data Optional data related to this step
     * @param string $method Calling method identifier
     */
    public static function trackConfigWorkflow($step, array $data = [], $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        $message = sprintf(
            "CONFIG WORKFLOW [%s]%s",
            $step,
            !empty($data) ? "\nDetails: " . json_encode($data, JSON_PRETTY_PRINT) : ''
        );
        
        self::$logger->info(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }
    
    /**
     * Log database operations related to configuration
     * 
     * @param string $operation The operation being performed
     * @param string $table The table being operated on
     * @param array $data Related data for context
     * @param string $method Calling method identifier
     */
    public static function logConfigDbOperation($operation, $table, array $data = [], $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }
        
        // Create safe version of data that won't expose sensitive information
        $safeData = [];
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), ['password', 'api_key', 'secret', 'token'])) {
                $safeData[$key] = '[REDACTED]';
            } else {
                $safeData[$key] = $value;
            }
        }
        
        $message = sprintf(
            "CONFIG DB OPERATION: %s on %s\nData: %s",
            $operation,
            $table,
            !empty($safeData) ? json_encode($safeData, JSON_PRETTY_PRINT) : 'No data'
        );
        
        self::$logger->info(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }
}
