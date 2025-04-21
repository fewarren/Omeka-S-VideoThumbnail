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

        self::ensureLogDirectory();
        self::initLogger();
    }

    private static function ensureLogDirectory()
    {
        $logDir = self::$config['log_dir'];
        if (!file_exists($logDir)) {
            mkdir($logDir, 0777, true);
        }
    }

    private static function initLogger()
    {
        if (self::$logger !== null) {
            return;
        }

        $logFile = self::$config['log_dir'] . '/' . self::$config['log_file'];
        $writer = new Stream($logFile);
        
        self::$logger = new Logger();
        self::$logger->addWriter($writer);

        // Set filters based on configured log levels
        if (!self::$config['levels']['debug']) {
            $writer->addFilter(new Priority(Logger::DEBUG, '!='));
        }
        if (!self::$config['levels']['info']) {
            $writer->addFilter(new Priority(Logger::INFO, '!='));
        }
        if (!self::$config['levels']['warning']) {
            $writer->addFilter(new Priority(Logger::WARN, '!='));
        }
    }

    private static function rotateLogIfNeeded()
    {
        if (!self::$config['enabled']) {
            return;
        }

        $logFile = self::$config['log_dir'] . '/' . self::$config['log_file'];
        if (file_exists($logFile) && filesize($logFile) > self::$config['max_size']) {
            self::rotateLog();
        }
    }

    private static function rotateLog()
    {
        $logFile = self::$config['log_dir'] . '/' . self::$config['log_file'];
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

        self::$logger->debug($message);
        self::rotateLogIfNeeded();
    }

    public static function log($message, $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }

        if ($method) {
            $message = "[$method] $message";
        }

        self::$logger->info($message);
        self::rotateLogIfNeeded();
    }

    public static function logWarning($message, $method = null)
    {
        if (!self::$config['enabled'] || !self::$logger) {
            return;
        }

        if ($method) {
            $message = "[$method] $message";
        }

        self::$logger->warn($message);
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

        self::$logger->err($message);
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

        self::$logger->info($message);
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

        self::$logger->err($message);
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
