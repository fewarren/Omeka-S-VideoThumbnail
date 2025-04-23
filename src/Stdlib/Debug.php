<?php
namespace VideoThumbnail\Stdlib;

use Laminas\Log\Logger;
use Laminas\Log\Writer\Stream;
use Laminas\Log\Filter\Priority;

class Debug
{
    private static $logger = null;
    private static $config = null;
    private static $memoryPeak = 0;
    private static $timeStart = null;

    public static function init($config)
    {
        self::$config = $config;
        self::$timeStart = microtime(true);
        
        if (!self::$config['enabled']) {
            return;
        }

        if (!self::ensureLogDirectory()) {
            self::$config['enabled'] = false;
            return;
        }
        self::initLogger();
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
}
