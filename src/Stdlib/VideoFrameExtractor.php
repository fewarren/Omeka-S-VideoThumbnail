<?php
namespace VideoThumbnail\Stdlib;

use VideoThumbnail\Stdlib\Debug;
use Laminas\Log\LoggerInterface;

class VideoFrameExtractor
{
    protected $ffmpegPath;
    protected $tempDir;
    protected $lastError;
    protected $timeout = 60;
    protected $maxFrameRetries = 3;
    protected $logger;

    /**
     * Constructor with updated parameter signature to match factory
     * 
     * @param string $ffmpegPath Path to FFmpeg executable
     * @param string|null $tempDir Optional custom temp directory, defaults to Omeka temp
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct($ffmpegPath, $tempDir = null, LoggerInterface $logger = null)
    {
        $this->ffmpegPath = $ffmpegPath;
        $this->tempDir = $tempDir ?: (defined('OMEKA_PATH') ? OMEKA_PATH . '/files/temp/video-thumbnails' : sys_get_temp_dir() . '/video-thumbnails');
        $this->logger = $logger;
        
        $this->ensureTempDir();
        
        // Clean up old temp files on initialization to prevent accumulation
        $this->cleanupOldTempFiles();
    }

    /**
     * Log a message safely using the injected logger or fallback methods
     */
    protected function log($message, $level = 'debug')
    {
        // First try the injected logger if available
        if ($this->logger) {
            switch ($level) {
                case 'error':
                    $this->logger->err($message);
                    break;
                case 'warn':
                    $this->logger->warn($message);
                    break;
                case 'info':
                    $this->logger->info($message);
                    break;
                default:
                    $this->logger->debug($message);
            }
            return;
        }
        
        // Fallback to Debug class if available, but catch any errors
        try {
            if (class_exists('VideoThumbnail\Stdlib\Debug')) {
                switch ($level) {
                    case 'error':
                        Debug::logError($message, __METHOD__);
                        break;
                    case 'warn':
                        Debug::logWarning($message, __METHOD__);
                        break;
                    case 'info':
                        Debug::log($message, __METHOD__);
                        break;
                    default:
                        // No direct debug level in Debug class
                        Debug::log($message, __METHOD__);
                }
                return;
            }
        } catch (\Exception $e) {
            // If Debug class fails, fall through to error_log
        }
        
        // Last resort: PHP error_log
        error_log('VideoThumbnail: ' . $message);
    }

    public function extractFrame($filePath, $timeInSeconds)
    {
        $this->log("Attempting to extract frame at {$timeInSeconds}s from video: " . basename($filePath));
        
        try {
            $outputPath = $this->generateTempPath('jpg');
            $this->log("Using temporary output path: {$outputPath}");

            $command = sprintf(
                '%s -y -i %s -ss %f -vframes 1 -f image2 %s',
                escapeshellarg($this->ffmpegPath),
                escapeshellarg($filePath),
                $timeInSeconds,
                escapeshellarg($outputPath)
            );
            
            $this->log("Executing FFmpeg command: {$command}");
            
            // Set a timeout for the command execution
            $output = [];
            $returnVar = 0;
            $this->executeCommandWithTimeout($command, $output, $returnVar, $this->timeout);
            
            if ($returnVar !== 0) {
                $this->log("FFmpeg frame extraction failed with code {$returnVar}. Output: " . implode("\n", $output), 'error');
                return false;
            }

            if (!file_exists($outputPath) || filesize($outputPath) === 0) {
                $this->log("Frame extraction failed - output file is missing or empty", 'error');
                return false;
            }

            $this->log("Successfully extracted frame to: {$outputPath}");
            return $outputPath;

        } catch (\Exception $e) {
            $this->log("Frame extraction error: " . $e->getMessage(), 'error');
            return false;
        }
    }

    public function extractFrames($filePath, $count = 5)
    {
        $this->log("Attempting to extract {$count} frames from video: " . basename($filePath));
        
        try {
            $duration = $this->getVideoDuration($filePath);
            if ($duration <= 0) {
                $this->log("Could not determine video duration", 'error');
                return [];
            }

            $this->log("Video duration: {$duration} seconds");
            
            $frames = [];
            $interval = $duration / ($count + 1);
            
            // Limit number of frames to a reasonable amount to prevent excessive processing
            $count = min($count, 10);
            
            for ($i = 1; $i <= $count; $i++) {
                $timePosition = $interval * $i;
                $this->log("Extracting frame {$i}/{$count} at position {$timePosition}s");
                
                $framePath = $this->extractFrame($filePath, $timePosition);
                if ($framePath) {
                    $frames[] = $framePath;
                    $this->log("Successfully extracted frame {$i}");
                } else {
                    $this->log("Failed to extract frame {$i}", 'warn');
                }
            }

            $this->log("Completed frame extraction. Successfully extracted " . count($frames) . " frames");
            return $frames;

        } catch (\Exception $e) {
            $this->log("Frame extraction error: " . $e->getMessage(), 'error');
            return [];
        }
    }

    public function getVideoDuration($filePath)
    {
        $this->log("Getting duration for video: " . basename($filePath));
        
        try {
            $command = sprintf(
                '%s -i %s 2>&1',
                escapeshellarg($this->ffmpegPath),
                escapeshellarg($filePath)
            );
            
            $output = [];
            $returnVar = 0;
            $this->executeCommandWithTimeout($command, $output, $returnVar, $this->timeout);
            
            $output = implode("\n", $output);
            
            // Try to find duration in FFmpeg output
            if (preg_match('/Duration: (\d{2}):(\d{2}):(\d{2})\.(\d{2})/', $output, $matches)) {
                $hours = intval($matches[1]);
                $minutes = intval($matches[2]);
                $seconds = intval($matches[3]);
                $milliseconds = intval($matches[4]);
                
                $duration = $hours * 3600 + $minutes * 60 + $seconds + $milliseconds / 100;
                $this->log("Detected video duration: {$duration} seconds");
                return $duration;
            }

            $this->log("Could not detect video duration from FFmpeg output", 'warn');
            return 0;

        } catch (\Exception $e) {
            $this->log("Error getting video duration: " . $e->getMessage(), 'error');
            return 0;
        }
    }

    protected function validateVideo($videoPath)
    {
        if (!file_exists($videoPath)) {
            throw new \RuntimeException('Video file does not exist');
        }

        if (!is_readable($videoPath)) {
            throw new \RuntimeException('Video file is not readable');
        }

        $mimeType = mime_content_type($videoPath);
        if (strpos($mimeType, 'video/') !== 0) {
            throw new \RuntimeException('File is not a video');
        }
    }

    protected function validateFFmpeg()
    {
        if (!file_exists($this->ffmpegPath) || !is_executable($this->ffmpegPath)) {
            throw new \RuntimeException('FFmpeg not found or not executable');
        }
    }

    /**
     * Execute a command with a timeout
     * 
     * @param string $command The command to execute
     * @param array &$output Output from the command
     * @param int &$returnVar Return code
     * @param int $timeout Timeout in seconds
     * @return bool True if command executed successfully
     */
    protected function executeCommandWithTimeout($command, &$output, &$returnVar, $timeout)
    {
        // Set a reasonable default timeout
        $timeout = $timeout ?: 60;
        
        // For Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows implementation without timeout
            exec($command . " 2>&1", $output, $returnVar);
            return $returnVar === 0;
        }

        // For Unix-like systems, we can use timeout
        $timeoutCommand = sprintf('timeout %d %s', $timeout, $command);
        exec($timeoutCommand . " 2>&1", $output, $returnVar);
        
        // Check if the timeout was reached
        if ($returnVar === 124) {
            $this->log("Command timed out after {$timeout} seconds: {$command}", 'error');
            $this->lastError = "Command timed out after {$timeout} seconds";
            return false;
        }
        
        return $returnVar === 0;
    }

    protected function generateTempPath($extension)
    {
        return sprintf(
            '%s/%s.%s',
            $this->tempDir,
            uniqid('frame_', true),
            $extension
        );
    }

    protected function ensureTempDir()
    {
        if (!file_exists($this->tempDir)) {
            $this->log("Creating temporary directory: {$this->tempDir}");
            if (!mkdir($this->tempDir, 0777, true)) {
                $this->log("Failed to create temporary directory: {$this->tempDir}", 'error');
                throw new \RuntimeException('Failed to create temporary directory');
            }
            $this->log("Successfully created temporary directory: {$this->tempDir}");
        }
    }

    protected function formatTimeString($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        return sprintf('%02d:%02d:%06.3f', $hours, $minutes, $seconds);
    }

    /**
     * Remove temp files older than 24 hours from the temp directory.
     */
    public function cleanupOldTempFiles($maxAgeHours = 24)
    {
        $now = time();
        $maxAge = $maxAgeHours * 3600;
        if (!is_dir($this->tempDir)) return;
        
        $count = 0;
        foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . 'frame_*') as $file) {
            if (is_file($file) && ($now - filemtime($file)) > $maxAge) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
        
        if ($count > 0) {
            $this->log("Cleaned up {$count} old temporary files");
        }
    }

    public function getLastError()
    {
        return $this->lastError;
    }
}