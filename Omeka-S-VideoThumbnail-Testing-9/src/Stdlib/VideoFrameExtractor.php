<?php
namespace VideoThumbnail\Stdlib;

use Laminas\Log\LoggerInterface;
use RuntimeException;

class VideoFrameExtractor
{
    protected $ffmpegPath;
    protected $logger;
    protected $tempPath;

    public function __construct($ffmpegPath, LoggerInterface $logger = null)
    {
        $this->ffmpegPath = $ffmpegPath;
        $this->logger = $logger;
        $this->tempPath = defined('OMEKA_PATH') ? 
            OMEKA_PATH . '/files/temp/video-thumbnails' : 
            sys_get_temp_dir() . '/video-thumbnails';

        // Clean up old temp files on initialization to prevent accumulation
        $this->cleanupOldTempFiles();
    }

    /**
     * Log a message safely without using the Debug class
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
        
        // Fallback to error_log for critical messages
        if ($level === 'error') {
            error_log('VideoThumbnail: ' . $message);
        }
    }

    /**
     * Get video duration in seconds
     */
    public function getVideoDuration($file)
    {
        if (!file_exists($file)) {
            $this->log("Video file not found: $file", 'error');
            throw new RuntimeException('Video file not found');
        }

        $command = sprintf(
            '%s -i %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($file)
        );

        $output = [];
        $returnVar = -1;
        exec($command, $output, $returnVar);

        $duration = $this->parseDurationFromOutput(implode("\n", $output));
        if ($duration === false) {
            $this->log("Could not determine video duration for: $file", 'error');
            throw new RuntimeException('Could not determine video duration');
        }

        return $duration;
    }

    /**
     * Extract a frame at the specified time
     */
    public function extractFrame($file, $timeInSeconds)
    {
        if (!file_exists($file)) {
            $this->log("Video file not found: $file", 'error');
            throw new RuntimeException('Video file not found');
        }

        // Ensure temp directory exists
        if (!is_dir($this->tempPath)) {
            if (!@mkdir($this->tempPath, 0777, true)) {
                $this->log("Failed to create temp directory: {$this->tempPath}", 'error');
                throw new RuntimeException('Failed to create temp directory');
            }
        }

        $outputFile = $this->tempPath . '/' . uniqid('frame_', true) . '.jpg';
        
        $command = sprintf(
            '%s -ss %d -i %s -vframes 1 -f image2 -y %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            (int)$timeInSeconds,
            escapeshellarg($file),
            escapeshellarg($outputFile)
        );

        $output = [];
        $returnVar = -1;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0 || !file_exists($outputFile)) {
            $this->log(
                sprintf(
                    "Frame extraction failed for %s at %d seconds. Error: %s",
                    $file,
                    $timeInSeconds,
                    implode("\n", $output)
                ),
                'error'
            );
            throw new RuntimeException('Frame extraction failed');
        }

        return $outputFile;
    }

    /**
     * Parse duration from FFmpeg output
     */
    protected function parseDurationFromOutput($output)
    {
        // Try Duration format HH:MM:SS.ms
        if (preg_match('/Duration: (\d{2}):(\d{2}):(\d{2}\.\d+)/', $output, $matches)) {
            return $matches[1] * 3600 + $matches[2] * 60 + $matches[3];
        }
        
        return false;
    }

    /**
     * Clean up old temporary files
     */
    protected function cleanupOldTempFiles()
    {
        if (!is_dir($this->tempPath)) {
            return;
        }

        $maxAge = 3600; // 1 hour
        $now = time();

        foreach (glob($this->tempPath . '/frame_*') as $file) {
            if ($now - filemtime($file) > $maxAge) {
                @unlink($file);
            }
        }
    }

    /**
     * Clean up a specific temporary file
     */
    public function cleanupTempFile($file)
    {
        if (file_exists($file) && strpos($file, $this->tempPath) === 0) {
            @unlink($file);
        }
    }
}