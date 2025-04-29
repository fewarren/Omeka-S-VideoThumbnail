<?php
namespace VideoThumbnail\Stdlib;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

class VideoFrameExtractor
{
    use LoggerAwareTrait;
    
    protected $ffmpegPath;
    protected $tempPath;

    public function __construct($ffmpegPath, LoggerInterface $logger = null)
    {
        $this->ffmpegPath = $ffmpegPath;
        if ($logger) {
            $this->setLogger($logger);
        }
        $this->tempPath = defined('OMEKA_PATH') ? 
            OMEKA_PATH . '/files/temp/video-thumbnails' : 
            sys_get_temp_dir() . '/video-thumbnails';

        // Clean up old temp files on initialization to prevent accumulation
        $this->cleanupOldTempFiles();
    }

    /**
     * Log a message with backward compatibility support
     *
     * @param string $message The message to log
     * @param string $level The log level (debug, info, warn, error)
     * @param array $context Additional context
     */
    protected function logMessage($message, $level = 'debug', array $context = [])
    {
        // Map old level format to PSR-3 levels
        $psrLevel = LogLevel::DEBUG;
        switch ($level) {
            case 'error':
                $psrLevel = LogLevel::ERROR;
                break;
            case 'warn':
                $psrLevel = LogLevel::WARNING;
                break;
            case 'info':
                $psrLevel = LogLevel::INFO;
                break;
        }
        
        // Use the trait's log method
        $this->log($psrLevel, $message, $context);
    }

    /**
     * Get video duration in seconds
     * 
     * @param string $file Path to the video file
     * @return float Duration in seconds
     * @throws RuntimeException If file doesn't exist or duration cannot be determined
     */
    public function getVideoDuration($file)
    {
        // Validate file path
        if (empty($file) || !is_string($file)) {
            $this->log('Invalid file path provided', 'error');
            throw new RuntimeException('Invalid file path provided');
        }

        // Check if file exists and is readable
        if (!file_exists($file)) {
            $this->log("Video file not found: $file", 'error');
            throw new RuntimeException('Video file not found');
        }

        if (!is_readable($file)) {
            $this->log("Video file not readable: $file", 'error');
            throw new RuntimeException('Video file not readable');
        }

        // Validate file is a video file
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file);
        if (strpos($mimeType, 'video/') !== 0) {
            $this->log("Not a video file: $file ($mimeType)", 'error');
            throw new RuntimeException('Not a video file');
        }

        // Build and execute command - ensuring all components are properly quoted
        // This handles Windows paths with spaces in "Program Files" etc.
        $command = sprintf(
            '%s -i %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($file)
        );

        $output = [];
        $returnVar = -1;
        exec($command, $output, $returnVar);

        // Parse duration from output
        $duration = $this->parseDurationFromOutput(implode("\n", $output));
        if ($duration === false) {
            $this->log("Could not determine video duration for: $file", 'error');
            throw new RuntimeException('Could not determine video duration');
        }

        // Validate duration is reasonable
        if ($duration <= 0 || $duration > 86400) { // 24 hours max
            $this->log("Invalid duration ($duration) for: $file", 'warn');
            throw new RuntimeException('Invalid video duration');
        }

        return $duration;
    }

    /**
     * Extract a frame at the specified time
     * 
     * @param string $file Path to the video file
     * @param float $timeInSeconds Time position in seconds to extract the frame
     * @return string Path to the extracted frame image
     * @throws RuntimeException If extraction fails
     */
    public function extractFrame($file, $timeInSeconds)
    {
        // Validate file path
        if (empty($file) || !is_string($file)) {
            $this->log('Invalid file path provided', 'error');
            throw new RuntimeException('Invalid file path provided');
        }

        // Check if file exists and is readable
        if (!file_exists($file)) {
            $this->log("Video file not found: $file", 'error');
            throw new RuntimeException('Video file not found');
        }

        if (!is_readable($file)) {
            $this->log("Video file not readable: $file", 'error');
            throw new RuntimeException('Video file not readable');
        }

        // Validate time parameter
        if (!is_numeric($timeInSeconds)) {
            $this->log("Invalid time value: $timeInSeconds", 'error');
            throw new RuntimeException('Invalid time value');
        }

        // Ensure time is positive and within reasonable range
        $timeInSeconds = max(0, min((float)$timeInSeconds, 86400)); // 24 hours max

        // Ensure temp directory exists
        if (!is_dir($this->tempPath)) {
            if (!@mkdir($this->tempPath, 0777, true)) {
                $this->log("Failed to create temp directory: {$this->tempPath}", 'error');
                throw new RuntimeException('Failed to create temp directory');
            }
        }

        $outputFile = $this->tempPath . '/' . uniqid('frame_', true) . '.jpg';
        
        $command = sprintf(
            '%s -ss %f -i %s -vframes 1 -f image2 -y %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            $timeInSeconds, // Use float format to preserve decimal precision
            escapeshellarg($file),
            escapeshellarg($outputFile)
        );

        $output = [];
        $returnVar = -1;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0 || !file_exists($outputFile)) {
            $this->log(
                sprintf(
                    "Frame extraction failed for %s at %.2f seconds. Error: %s",
                    $file,
                    $timeInSeconds,
                    implode("\n", $output)
                ),
                'error'
            );
            throw new RuntimeException('Frame extraction failed');
        }

        // Verify the output file is a valid image
        if (filesize($outputFile) <= 0) {
            @unlink($outputFile);
            $this->log("Extracted frame has zero size", 'error');
            throw new RuntimeException('Extracted frame has zero size');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($outputFile);
        if (strpos($mimeType, 'image/') !== 0) {
            @unlink($outputFile);
            $this->log("Extracted frame is not a valid image: $mimeType", 'error');
            throw new RuntimeException('Extracted frame is not a valid image');
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