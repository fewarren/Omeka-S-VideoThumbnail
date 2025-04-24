<?php
namespace VideoThumbnail\Stdlib;

/**
 * Utility class for extracting frames from videos using FFmpeg
 */
class VideoFrameExtractor
{
    /**
     * @var string Path to the FFmpeg executable
     */
    protected $ffmpegPath;
    
    /**
     * @var string Base directory to store temporary files
     */
    protected $tempDir;
    
    /**
     * Constructor
     *
     * @param string $ffmpegPath Path to FFmpeg executable
     * @param string $tempDir Temporary directory for extracted frames
     */
    public function __construct($ffmpegPath = '/usr/bin/ffmpeg', $tempDir = null)
    {
        $this->ffmpegPath = $ffmpegPath;
        
        if ($tempDir === null) {
            // Set default temporary directory
            if (defined('OMEKA_PATH')) {
                $this->tempDir = OMEKA_PATH . '/files/temp/video-thumbnails';
            } else {
                $this->tempDir = sys_get_temp_dir() . '/video-thumbnails';
            }
        } else {
            $this->tempDir = $tempDir;
        }
        
        // Ensure temporary directory exists
        $this->ensureTempDirExists();
    }
    
    /**
     * Ensure temporary directory exists
     *
     * @return bool True if directory exists or was created
     */
    protected function ensureTempDirExists()
    {
        if (!file_exists($this->tempDir)) {
            return @mkdir($this->tempDir, 0755, true);
        }
        
        return is_dir($this->tempDir) && is_writable($this->tempDir);
    }
    
    /**
     * Extract a single frame from a video file at the specified time
     *
     * @param string $videoFile Path to video file
     * @param float $timeInSeconds Time position in seconds
     * @return string|false Path to extracted frame or false on failure
     */
    public function extractFrame($videoFile, $timeInSeconds)
    {
        $this->log("Extracting frame at {$timeInSeconds}s from {$videoFile}");
        
        if (!file_exists($videoFile) || !is_readable($videoFile)) {
            $this->log("Video file not found or not readable: {$videoFile}");
            return false;
        }
        
        // Create temporary filename
        $outputFile = $this->tempDir . '/frame_' . md5($videoFile . $timeInSeconds) . '.jpg';
        
        // Build FFmpeg command
        $ffmpegCmd = escapeshellcmd($this->ffmpegPath) . 
                  ' -y' . // Overwrite output files
                  ' -ss ' . escapeshellarg($timeInSeconds) . // Seek to position
                  ' -i ' . escapeshellarg($videoFile) . // Input file
                  ' -vframes 1' . // Extract just one frame
                  ' -q:v 2' . // High quality
                  ' ' . escapeshellarg($outputFile) . // Output file
                  ' 2>&1'; // Redirect stderr to stdout
        
        // Execute command
        $output = [];
        $returnCode = 0;
        exec($ffmpegCmd, $output, $returnCode);
        
        // Check if extraction was successful
        if ($returnCode !== 0 || !file_exists($outputFile)) {
            $this->log("FFmpeg execution failed with code {$returnCode}: " . implode("\n", $output));
            return false;
        }
        
        return $outputFile;
    }
    
    /**
     * Extract multiple frames evenly distributed throughout the video
     *
     * @param string $videoFile Path to video file
     * @param int $frameCount Number of frames to extract
     * @return array Array of paths to extracted frames
     */
    public function extractFrames($videoFile, $frameCount = 5)
    {
        $this->log("Extracting {$frameCount} frames from {$videoFile}");
        
        // Get video duration
        $duration = $this->getVideoDuration($videoFile);
        if ($duration <= 0) {
            $this->log("Could not determine video duration, using default of 60 seconds");
            $duration = 60.0;
        }
        
        $frames = [];
        
        // Calculate time points for extracting frames
        for ($i = 0; $i < $frameCount; $i++) {
            // Calculate position (avoid the very beginning and end)
            $percentage = ($i + 1) / ($frameCount + 1);
            $timeInSeconds = $duration * $percentage;
            
            // Extract frame at this position
            $framePath = $this->extractFrame($videoFile, $timeInSeconds);
            if ($framePath !== false) {
                $frames[] = $framePath;
            }
        }
        
        return $frames;
    }
    
    /**
     * Get video duration in seconds
     *
     * @param string $videoFile Path to video file
     * @return float Duration in seconds or 0 on failure
     */
    public function getVideoDuration($videoFile)
    {
        $this->log("Getting duration of {$videoFile}");
        
        if (!file_exists($videoFile) || !is_readable($videoFile)) {
            $this->log("Video file not found or not readable: {$videoFile}");
            return 0;
        }
        
        // Build FFmpeg command to get duration
        $ffmpegCmd = escapeshellcmd($this->ffmpegPath) . 
                   ' -i ' . escapeshellarg($videoFile) . 
                   ' 2>&1'; // FFmpeg outputs to stderr
        
        $output = [];
        exec($ffmpegCmd, $output);
        
        // Parse output to get duration
        $durationStr = $this->parseDurationFromOutput(implode("\n", $output));
        if ($durationStr === false) {
            $this->log("Could not parse duration from FFmpeg output");
            return 0;
        }
        
        $duration = $this->convertTimeToSeconds($durationStr);
        $this->log("Video duration: {$duration} seconds");
        
        return $duration;
    }
    
    /**
     * Parse video duration from FFmpeg output
     *
     * @param string $output FFmpeg command output
     * @return string|false Duration string or false if not found
     */
    protected function parseDurationFromOutput($output)
    {
        if (preg_match('/Duration: ([0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]+)/', $output, $matches)) {
            return $matches[1];
        }
        
        return false;
    }
    
    /**
     * Convert time string (hh:mm:ss.ms) to seconds
     *
     * @param string $timeString Time string in format hh:mm:ss.ms
     * @return float Time in seconds
     */
    protected function convertTimeToSeconds($timeString)
    {
        $parts = explode(':', $timeString);
        
        if (count($parts) === 3) {
            $hours = (float)$parts[0];
            $minutes = (float)$parts[1];
            $seconds = (float)$parts[2];
            
            return $hours * 3600 + $minutes * 60 + $seconds;
        }
        
        return 0;
    }
    
    /**
     * Simple logging function
     *
     * @param string $message Message to log
     */
    protected function log($message)
    {
        error_log('[VideoThumbnail] ' . $message);
    }
    
    /**
     * Clean up temporary files
     *
     * @param array $files Optional array of specific files to delete
     * @return int Number of files deleted
     */
    public function cleanup($files = [])
    {
        $count = 0;
        
        if (empty($files)) {
            // Clean all temporary files older than 1 hour
            $files = glob($this->tempDir . '/frame_*.jpg');
            foreach ($files as $file) {
                if (filemtime($file) < time() - 3600) {
                    if (@unlink($file)) {
                        $count++;
                    }
                }
            }
        } else {
            // Clean only specified files
            foreach ($files as $file) {
                if (file_exists($file) && @unlink($file)) {
                    $count++;
                }
            }
        }
        
        return $count;
    }
}