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
     * Initializes the debug logger and log file if not already set up.
     *
     * Ensures the log directory exists, creates a new log file for the current date, and configures the logger instance. If initialization fails, logs the error to the PHP error log and disables further logging.
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

    /**
     * Initializes the debug logging system with the provided configuration.
     *
     * Sets up logging directories, initializes the logger, and logs system information if debugging is enabled. Disables debugging and logs errors if initialization fails at any step.
     *
     * @param array $config Configuration settings for the debug system.
     */
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

    /**
     * Ensures the configured log directory exists and is writable.
     *
     * Attempts to create the directory if it does not exist. Logs errors to the PHP error log if the directory is missing, cannot be created, or is not writable.
     *
     * @return bool True if the log directory exists and is writable, false otherwise.
     */
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

    /**
     * Initializes the Laminas logger for debug output if enabled and properly configured.
     *
     * Disables debugging and logs an error if the log file is not configured, not writable, or logger initialization fails.
     */
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

    /**
     * Checks if the log file exceeds the configured maximum size and triggers log rotation if necessary.
     */
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

    /**
     * Rotates the log files by deleting the oldest log and renaming existing logs to maintain the configured maximum number of log files.
     *
     * Removes the oldest log file if the maximum file count is reached, then shifts existing log files by incrementing their suffixes.
     */
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

    /**
     * Formats a log message with a standard prefix and optional method name.
     *
     * @param string $message The message to format.
     * @param string|null $method Optional method name to include in the message.
     * @return string The formatted log message.
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
     * Logs the entry into a method with process ID, memory usage, elapsed time, and optional parameters.
     *
     * @param string $method Name of the method being entered.
     * @param array $params Optional parameters to include in the log entry.
     */
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

    /**
     * Logs an informational message to the debug log.
     *
     * @param string $message The message to log.
     * @param string|null $method Optional method name to include in the log entry.
     */
    public static function log($message, $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }

        self::$logger->info(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }

    /**
     * Logs a warning message to the debug log if debugging is enabled.
     *
     * @param string $message The warning message to log.
     * @param string|null $method Optional method name to include in the log entry.
     */
    public static function logWarning($message, $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }

        self::$logger->warn(self::formatMessage($message, $method));
        self::rotateLogIfNeeded();
    }

    /**
     * Logs an error message with optional method context and exception details.
     *
     * If an exception is provided, its message and stack trace are included. The error is logged both to the configured logger and the PHP error log. Log rotation is triggered if needed.
     *
     * @param string $message The error message to log.
     * @param string|null $method Optional method name for context.
     * @param \Exception|null $exception Optional exception to include in the log.
     */
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

    /**
     * Logs the progress of a job with its ID, completion percentage, and optional status.
     *
     * @param int $jobId The unique identifier of the job.
     * @param int $progress The job's progress as a percentage.
     * @param string|null $status Optional status message describing the job's current state.
     */
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

    /**
     * Logs a job error message with optional retry count.
     *
     * Records job-related errors to the debug log and PHP error log, including the job ID, error details, and retry number if provided.
     *
     * @param int $jobId The identifier of the job where the error occurred.
     * @param string $error Description of the error encountered.
     * @param int|null $retry Optional retry attempt number.
     */
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

    /**
     * Converts a byte value to a human-readable string with appropriate units.
     *
     * @param int|float $bytes The number of bytes to format.
     * @return string The formatted string with units (B, KB, MB, or GB).
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

    /**
     * Returns the peak memory usage recorded during the debug session.
     *
     * @return int|null Peak memory usage in bytes, or null if not recorded.
     */
    public static function getMemoryPeak()
    {
        return self::$memoryPeak;
    }

    /**
     * Returns the elapsed time in seconds since the debug system was initialized.
     *
     * @return float Elapsed time in seconds.
     */
    public static function getElapsedTime()
    {
        return microtime(true) - self::$timeStart;
    }

    /**
     * Logs detailed system environment information for troubleshooting purposes.
     *
     * Collects and logs PHP version, operating system, server software, memory and upload limits, FFmpeg availability, and the status of key directories to assist with debugging and support.
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
     * Logs an administrative configuration form action with associated data.
     *
     * @param string $action The configuration action performed (e.g., load, validate, save).
     * @param array $data Optional data related to the action.
     * @param string|null $method Optional identifier for the calling method.
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
     * Logs form validation errors as warnings.
     *
     * Each validation error is recorded with its associated field and error messages for debugging purposes.
     *
     * @param array $messages Associative array of form fields and their validation error messages.
     * @param string|null $method Optional identifier for the calling method.
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
     * Logs a debug-level dump of form data at a specified processing stage.
     *
     * @param array $formData The form data to be logged.
     * @param string $stage Optional description of the processing stage. Defaults to 'unknown'.
     * @param string|null $method Optional identifier for the calling method.
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
     * Determines whether debug mode is currently enabled.
     *
     * Checks and caches the debug mode setting from the Omeka service locator. Returns false if the setting cannot be retrieved.
     *
     * @return bool True if debug mode is enabled; otherwise, false.
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
     * Logs the exit point of a method, including memory usage, elapsed time, and optional result data.
     *
     * @param string $method The name of the method exiting.
     * @param mixed $result Optional result data to include in the log.
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
     * Logs a change to a configuration setting, including the key, previous value, and new value.
     *
     * @param string $key The setting key that was changed.
     * @param mixed $oldValue The previous value of the setting.
     * @param mixed $newValue The new value of the setting.
     * @param string|null $method Optional identifier for the calling method.
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
     * Logs a workflow state transition in the admin settings, including previous and new states and optional context.
     *
     * @param string $from The previous workflow state.
     * @param string $to The new workflow state.
     * @param array $context Optional additional context information about the transition.
     * @param string|null $method Optional method identifier for traceability.
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
     * Logs a detailed step in the admin form processing workflow.
     *
     * Records the specified processing step and any associated data for debugging and traceability.
     *
     * @param string $step Description of the current processing step.
     * @param array $data Optional data relevant to this step.
     * @param string|null $method Optional method identifier for context.
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
     * Begins tracking the start time and memory usage for a performance-monitored operation.
     *
     * @param string $operationId Unique identifier for the operation.
     * @param string|null $description Optional description of the operation.
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
     * Ends a timed operation, logging its duration, memory usage delta, and optional result data.
     *
     * Logs a warning if no matching startOperation was found for the given identifier.
     *
     * @param string $operationId Identifier corresponding to a previous startOperation call.
     * @param array|null $result Optional result data to include in the log.
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
     * Logs an administrative user action for audit and tracking purposes.
     *
     * Records the specified action, optionally including the user ID and additional details, to the debug log for administrative auditing.
     *
     * @param string $action Description of the admin action performed.
     * @param string|null $userId Optional user identifier associated with the action.
     * @param array $details Optional associative array of additional action details.
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
     * Logs details of an API interaction related to admin settings, including endpoint, HTTP method, status code, and request/response data.
     *
     * @param string $endpoint The API endpoint accessed.
     * @param string $method The HTTP method used (e.g., GET, POST).
     * @param array|null $requestData Optional request data sent to the API.
     * @param array|null $responseData Optional response data received from the API.
     * @param int|null $statusCode Optional HTTP status code returned by the API.
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
     * Logs a change to a configuration setting, including the key, previous value, and new value.
     *
     * @param string $key The setting key that was changed.
     * @param mixed $oldValue The previous value of the setting.
     * @param mixed $newValue The new value of the setting.
     * @param string|null $method Optional identifier for the calling method.
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
    
    /****
     * Logs a workflow state transition in the admin settings, including optional context data.
     *
     * @param string $from The previous workflow state.
     * @param string $to The new workflow state.
     * @param array $context Optional additional context information about the transition.
     * @param string|null $method Optional identifier for the calling method.
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
     * Logs a detailed step in the admin form processing workflow.
     *
     * Records the specified processing step and any associated data for debugging and traceability.
     *
     * @param string $step Description of the current processing step.
     * @param array $data Optional data relevant to this step.
     * @param string|null $method Optional method identifier for context.
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
     * Begins tracking the start time and memory usage of a named operation for performance monitoring.
     *
     * @param string $operationId Unique identifier for the operation.
     * @param string|null $description Optional description of the operation.
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
     * Ends a timed operation, logging its duration, memory usage delta, and optional result data.
     *
     * Logs a warning if no matching startOperation was found for the given operation ID.
     *
     * @param string $operationId Identifier for the operation to end.
     * @param array|null $result Optional result data to include in the log.
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
     * Logs an administrative user action for audit and tracking purposes.
     *
     * Records the specified action, optionally including the user ID and additional details, to the debug log for administrative auditing.
     *
     * @param string $action Description of the admin action performed.
     * @param string|null $userId User identifier, if available.
     * @param array $details Optional associative array with additional context about the action.
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
     * Logs details of an API interaction related to admin settings, including endpoint, HTTP method, status code, and request/response data.
     *
     * @param string $endpoint The API endpoint accessed.
     * @param string $method The HTTP method used (e.g., GET, POST).
     * @param array|null $requestData Optional request data sent to the API.
     * @param array|null $responseData Optional response data received from the API.
     * @param int|null $statusCode Optional HTTP status code returned by the API.
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
     * Logs the state of a configuration form for debugging, redacting sensitive fields.
     *
     * Records the provided form data and workflow context, with sensitive information such as API keys redacted, to assist in debugging configuration processes.
     *
     * @param array $formData The form data to log, with sensitive fields redacted.
     * @param string $context Description of the form's position or purpose in the workflow.
     * @param string|null $method Optional identifier for the calling method.
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
     * Logs detailed environment and configuration directory information for troubleshooting.
     *
     * Includes server details, configuration and log directory status, and module INI file metadata. An optional message can provide additional context.
     *
     * @param string $message Optional context message to include in the log.
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
     * Logs a step in the configuration workflow with optional contextual data.
     *
     * @param string $step The current step in the configuration process.
     * @param array $data Optional additional data relevant to the step.
     * @param string|null $method Optional identifier for the calling method.
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
     * Logs a configuration-related database operation with sensitive data redacted.
     *
     * Records the type of operation, target table, and contextual data, redacting sensitive fields such as passwords, API keys, secrets, and tokens.
     *
     * @param string $operation The database operation performed (e.g., insert, update, delete).
     * @param string $table The name of the table affected.
     * @param array $data Contextual data for the operation; sensitive fields are redacted.
     * @param string|null $method Optional identifier for the calling method.
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
