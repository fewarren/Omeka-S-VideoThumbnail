<?php
namespace VideoThumbnail\Stdlib;

use VideoThumbnail\Stdlib\Debug;

class VideoFrameExtractor
{
    /**
     * @var string
     */
    protected $ffmpegPath;

    /**
     * @var int Default timeout in seconds
     */
    protected $defaultTimeout = 15;

    /**
     * @param string $ffmpegPath
     */
    public function __construct($ffmpegPath)
    {
        Debug::logEntry(__METHOD__, ['ffmpegPath' => $ffmpegPath]);
        $this->ffmpegPath = $ffmpegPath;

        if (!is_executable($this->ffmpegPath)) {
            $this->ffmpegPath = $this->autoDetectPaths();
            if (!$this->ffmpegPath) {
                throw new \RuntimeException('FFmpeg binary not found or not executable');
            }
        }

        Debug::logExit(__METHOD__);
    }

    /**
     * Attempt to auto-detect FFmpeg/FFprobe paths.
     *
     * @return string|null
     */
    protected function autoDetectPaths()
    {
        $possiblePaths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'];
        foreach ($possiblePaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Execute a command with timeout and debug logging
     *
     * @param string $command Command to execute
     * @param int $timeout Timeout in seconds
     * @return string Command output
     * @throws \RuntimeException if command fails critically
     */
    protected function executeCommandWithTimeout($command, $timeout = null)
    {
        Debug::logEntry(__METHOD__, ['command' => $command, 'timeout' => $timeout]);

        if ($timeout === null) {
            $timeout = $this->defaultTimeout;
        }
        
        // Ensure reasonable timeout limits
        $timeout = max(1, min(60, $timeout)); // Between 1 and 60 seconds

        Debug::log('Executing command with timeout ' . $timeout . 's: ' . $command, __METHOD__);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];
        
        // Set environment variables for FFmpeg to prevent hangs
        $env = array_merge(
            $_ENV ?? [],
            [
                'FFMPEG_FORCE_TERMINATE' => '1',
                'TMPDIR' => sys_get_temp_dir(),
            ]
        );

        $process = proc_open($command, $descriptors, $pipes, null, $env);

        if (!is_resource($process)) {
            Debug::logError('Failed to open process for command: ' . $command, __METHOD__);
            Debug::logExit(__METHOD__, '');
            return '';
        }

        // Set non-blocking streams
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Close stdin immediately
        fclose($pipes[0]);

        $output = '';
        $stderr_output = '';
        $startTime = microtime(true); // More precise timing
        $maxLoopCount = $timeout * 20; // 20 iterations per second
        $loopCount = 0;
        $processRunning = true;

        Debug::log('Process started at ' . date('Y-m-d H:i:s'), __METHOD__);

        while ($loopCount < $maxLoopCount && $processRunning) {
            $loopCount++;
            
            // Less frequent logging to reduce log spam
            if ($loopCount % 20 === 0) {
                Debug::log('Command execution in progress, iteration ' . $loopCount, __METHOD__);
            }

            // Check process status
            $status = proc_get_status($process);
            $processRunning = $status['running'] ?? false;

            if (!$processRunning) {
                Debug::log('Process completed with exit code: ' . $status['exitcode'], __METHOD__);
                break;
            }

            // Check for timeout - use microtime for more precision
            if (microtime(true) - $startTime > $timeout) {
                Debug::logError('Command timed out after ' . $timeout . ' seconds', __METHOD__);
                
                // Force kill process
                proc_terminate($process, 9); // SIGKILL
                
                // Wait a moment for process to terminate
                usleep(100000); // 100ms
                
                // Double-check it's gone and kill again if needed
                $status = proc_get_status($process);
                if ($status['running'] ?? false) {
                    Debug::logError('Process still running after SIGKILL, attempting second termination', __METHOD__);
                    proc_terminate($process, 9);
                }
                
                $processRunning = false;
                break;
            }

            // Read any available output
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            if ($stdout) {
                $output .= $stdout;
            }
            
            if ($stderr) {
                $stderr_output .= $stderr;
                
                // Only log significant stderr output to reduce log spam
                if (strlen(trim($stderr)) > 0 && strpos($stderr, 'frame=') === false) {
                    Debug::logError('Command stderr: ' . trim($stderr), __METHOD__);
                }
            }

            // Smaller sleep interval for faster response
            usleep(50000); // 50ms
        }

        // Handle infinite loop protection
        if ($loopCount >= $maxLoopCount && $processRunning) {
            Debug::logError('Maximum loop count reached, forcing termination', __METHOD__);
            proc_terminate($process, 9);
            usleep(100000); // 100ms pause
            
            // Double-check termination
            $status = proc_get_status($process);
            if ($status['running'] ?? false) {
                proc_terminate($process, 9); // Try again with SIGKILL
            }
        }

        // Close pipes
        fclose($pipes[1]);
        fclose($pipes[2]);

        // Close process and get exit code
        $exitCode = proc_close($process);
        Debug::log('Process closed with exit code: ' . $exitCode, __METHOD__);
        
        if ($exitCode !== 0 && !$processRunning) {
            Debug::logError('Command failed with non-zero exit code: ' . $exitCode, __METHOD__);
        }
        
        Debug::logExit(__METHOD__, 'Output length: ' . strlen($output) . ' bytes');
        return trim($output);
    }

    /**
     * Get video duration in seconds with enhanced error handling and debug logging
     *
     * @param string $videoPath
     * @return float
     */
    public function getVideoDuration($videoPath)
    {
        Debug::logEntry(__METHOD__, ['videoPath' => $videoPath]);

        if (!file_exists($videoPath) || !is_readable($videoPath)) {
            Debug::logError('Video file does not exist or is not readable: ' . $videoPath, __METHOD__);
            Debug::logExit(__METHOD__, 0);
            return 0;
        }

        if (!is_executable($this->ffmpegPath)) {
            Debug::logError('FFmpeg is not executable: ' . $this->ffmpegPath, __METHOD__);
            Debug::logExit(__METHOD__, 0);
            return 0;
        }

        $ffprobePath = dirname($this->ffmpegPath) . DIRECTORY_SEPARATOR . 'ffprobe';

        if (file_exists($ffprobePath) && is_executable($ffprobePath)) {
            Debug::log('Trying ffprobe method for duration', __METHOD__);
            $command = sprintf(
                '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
                escapeshellcmd($ffprobePath),
                escapeshellarg($videoPath)
            );

            $duration = $this->executeCommandWithTimeout($command, 10);

            if (!empty($duration) && is_numeric($duration)) {
                Debug::log('Duration (ffprobe): ' . $duration . ' seconds', __METHOD__);
                Debug::logExit(__METHOD__, $duration);
                return (float) $duration;
            }
        }

        Debug::logError('Failed to determine video duration using ffprobe', __METHOD__);
        Debug::logExit(__METHOD__, 0);
        return 0;
    }

    /**
     * Extract a single frame from a video file at the specified time
     * 
     * @param string $videoPath Path to the video file
     * @param float $timeInSeconds Time in seconds to extract the frame from
     * @param int $timeout Maximum execution time in seconds
     * @return string|null Path to the extracted frame (jpg) or null on failure
     */
    public function extractFrame($videoPath, $timeInSeconds, $timeout = null)
    {
        Debug::logEntry(__METHOD__, ['videoPath' => $videoPath, 'timeInSeconds' => $timeInSeconds]);
        
        if (!file_exists($videoPath) || !is_readable($videoPath)) {
            Debug::logError('Video file does not exist or is not readable: ' . $videoPath, __METHOD__);
            Debug::logExit(__METHOD__, null);
            return null;
        }
        
        if (!is_executable($this->ffmpegPath)) {
            Debug::logError('FFmpeg is not executable: ' . $this->ffmpegPath, __METHOD__);
            Debug::logExit(__METHOD__, null);
            return null;
        }
        
        // Ensure timeInSeconds is a valid number
        if (!is_numeric($timeInSeconds) || $timeInSeconds < 0) {
            Debug::logError('Invalid time value: ' . $timeInSeconds, __METHOD__);
            Debug::logExit(__METHOD__, null);
            return null;
        }
        
        // Create a temporary file for the frame
        $outputPath = tempnam(sys_get_temp_dir(), 'vidthumb_') . '.jpg';
        
        // Build the FFmpeg command
        $command = sprintf(
            '%s -y -ss %f -i %s -vframes 1 -q:v 2 -f image2 %s',
            escapeshellcmd($this->ffmpegPath),
            (float) $timeInSeconds,
            escapeshellarg($videoPath),
            escapeshellarg($outputPath)
        );
        
        Debug::log('Extracting frame at ' . $timeInSeconds . 's to ' . $outputPath, __METHOD__);
        
        // Use a shorter timeout for frame extraction to prevent hanging
        if ($timeout === null) {
            $timeout = 10; // Use an even shorter timeout for frame extraction
        }
        
        try {
            $this->executeCommandWithTimeout($command, $timeout);
            
            // Verify the output file exists and is not empty
            if (file_exists($outputPath) && filesize($outputPath) > 0) {
                Debug::log('Frame extracted successfully to: ' . $outputPath, __METHOD__);
                Debug::logExit(__METHOD__, $outputPath);
                return $outputPath;
            }
            
            Debug::logError('Frame extraction failed - output file empty or missing', __METHOD__);
            
            // Clean up empty file if it exists
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }
            
            Debug::logExit(__METHOD__, null);
            return null;
        } catch (\Exception $e) {
            Debug::logError('Exception during frame extraction: ' . $e->getMessage(), __METHOD__);
            
            // Clean up any partial output
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }
            
            Debug::logExit(__METHOD__, null);
            return null;
        }
    }
    
    /**
     * Extract multiple frames evenly distributed throughout the video
     * 
     * @param string $videoPath Path to the video file
     * @param int $count Number of frames to extract
     * @return array Array of paths to extracted frames or empty array on failure
     */
    public function extractFrames($videoPath, $count = 5)
    {
        Debug::logEntry(__METHOD__, ['videoPath' => $videoPath, 'count' => $count]);
        
        $count = max(1, min(20, (int) $count)); // Limit between 1 and 20 frames
        $duration = $this->getVideoDuration($videoPath);
        
        if ($duration <= 0) {
            Debug::logError('Could not determine video duration', __METHOD__);
            Debug::logExit(__METHOD__, []);
            return [];
        }
        
        $frames = [];
        $timeInterval = $duration / ($count + 1);
        
        Debug::log('Extracting ' . $count . ' frames at interval of ' . $timeInterval . 's', __METHOD__);
        
        // Extract frames at evenly spaced intervals
        for ($i = 1; $i <= $count; $i++) {
            $timePosition = $timeInterval * $i;
            $frame = $this->extractFrame($videoPath, $timePosition, 10); // Use shorter timeout per frame
            
            if ($frame !== null) {
                $frames[] = $frame;
            }
            
            // Add a small pause between extractions to prevent resource exhaustion
            usleep(100000); // 100ms pause
        }
        
        Debug::log('Extracted ' . count($frames) . ' frames successfully', __METHOD__);
        Debug::logExit(__METHOD__, $frames);
        return $frames;
    }
}
