<?php
namespace VideoThumbnail\Stdlib;

class Debug
{
    /**
     * @var bool Whether debug mode is enabled
     */
    protected static $enabled = false;
    
    /**
     * @var bool Whether configuration has been loaded
     */
    protected static $initialized = false;
    
    /**
     * @var string The path to the Apache error log
     */
    protected static $errorLogPath = null;
    
    /**
     * Initialize the debug system with settings
     *
     * @param \Omeka\Settings\Settings|null $settings Omeka settings
     * @return void
     */
    public static function init($settings = null): void
    {
        if (self::$initialized) {
            return;
        }
        
        // Use settings to determine debug mode instead of forcing it on
        if ($settings !== null) {
            self::$enabled = (bool) $settings->get('videothumbnail_debug_mode', false);
        } else {
            self::$enabled = false; // Default to disabled if no settings provided
        }
        
        // Try to determine Apache error log path
        self::detectErrorLogPath();
        
        // Log a message to confirm initialization
        if (self::$enabled) {
            self::log('Debug mode enabled', __METHOD__, 'info');
        }
        
        self::$initialized = true;
    }
    
    /**
     * Attempt to detect the Apache error log path
     * 
     * @return void
     */
    protected static function detectErrorLogPath(): void
    {
        // First try PHP's error_log setting
        $phpErrorLog = ini_get('error_log');
        if (!empty($phpErrorLog) && file_exists($phpErrorLog) && is_writable($phpErrorLog)) {
            self::$errorLogPath = $phpErrorLog;
            return;
        }
        
        // Try common Apache error log locations
        $possiblePaths = [
            '/var/log/apache2/error.log',
            '/var/log/apache2/omeka-s_error.log',
            '/var/log/httpd/error_log',
            '/var/log/apache2/error_log',
        ];
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path) && is_writable($path)) {
                self::$errorLogPath = $path;
                return;
            }
        }
    }
    
    /**
     * Enable or disable debug mode
     *
     * @param bool $enabled
     * @return void
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
        self::$initialized = true;
    }
    
    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
    
    /**
     * Log a debug message with function context
     *
     * @param string $message The message to log
     * @param string $function The calling function name
     * @param string $type The type of log entry (entry, exit, info, error)
     * @return void
     */
    public static function log(string $message, string $function = '', string $type = 'info'): void
    {
        if (!self::$enabled) {
            return;
        }
        
        $prefix = 'VideoThumbnail Debug';
        
        if (!empty($function)) {
            $prefix .= " | {$function}";
        }
        
        switch ($type) {
            case 'entry':
                $message = "ENTER: {$message}";
                break;
            case 'exit':
                $message = "EXIT: {$message}";
                break;
            case 'error':
                $prefix .= " | ERROR";
                break;
            default:
                // Default info formatting
                break;
        }

        // Sanitize the message
        $sanitizedMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Construct full log message
        $logMessage = "{$prefix} | {$sanitizedMessage}";
        $datePrefix = '[' . date('Y-m-d H:i:s') . '] ';
        
        // Try multiple logging methods to ensure message is captured
        
        // 1. Direct to Apache error log using detected path
        if (self::$errorLogPath && is_writable(self::$errorLogPath)) {
            file_put_contents(self::$errorLogPath, $datePrefix . $logMessage . PHP_EOL, FILE_APPEND);
        }
        
        // 2. Direct to Apache error log - works if running under Apache
        if (function_exists('apache_note')) {
            error_log($logMessage, 0);
        }
        
        // 3. Use syslog as another alternative
        openlog('VideoThumbnail', LOG_PID | LOG_PERROR, LOG_LOCAL0);
        syslog(LOG_INFO, $logMessage);
        closelog();
        
        // 4. Standard PHP error_log as fallback
        error_log($logMessage);
    }
    
    /**
     * Log a message when entering a function
     *
     * @param string $method The method/function name (use __METHOD__)
     * @param array $params Optional parameters to log
     * @return void
     */
    public static function logEntry(string $method, array $params = []): void
    {
        if (!self::$enabled) {
            return;
        }
        
        $message = "ENTER";
        if (!empty($params)) {
            $message .= " with params: " . json_encode($params, JSON_UNESCAPED_SLASHES);
        }
        
        self::log($message, $method, 'entry');
    }
    
    /**
     * Log a message when exiting a function
     *
     * @param string $method The method/function name (use __METHOD__)
     * @param mixed $result Optional result to log
     * @return void
     */
    public static function logExit(string $method, $result = null): void
    {
        if (!self::$enabled) {
            return;
        }
        
        $message = "EXIT";
        if ($result !== null) {
            if (is_array($result) || is_object($result)) {
                $message .= " with result: " . json_encode($result, JSON_UNESCAPED_SLASHES);
            } else {
                $message .= " with result: " . $result;
            }
        }
        
        self::log($message, $method, 'exit');
    }
    
    /**
     * Log an error message
     *
     * @param string $message The error message
     * @param string $method The method/function name (use __METHOD__)
     * @return void
     */
    public static function logError(string $message, string $method = ''): void
    {
        if (!self::$enabled) {
            return;
        }
        
        self::log($message, $method, 'error');
    }
}
