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

        // Keep track of all potential duration values for fallback comparison
        $durationValues = [];
        
        // Method 1: Try ffprobe with extended timeout (most accurate)
        $ffprobePath = dirname($this->ffmpegPath) . DIRECTORY_SEPARATOR . 'ffprobe';
        if (file_exists($ffprobePath) && is_executable($ffprobePath)) {
            Debug::log('Trying ffprobe method for duration', __METHOD__);
            $command = sprintf(
                '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
                escapeshellcmd($ffprobePath),
                escapeshellarg($videoPath)
            );

            $duration = $this->executeCommandWithTimeout($command, 15); // Increased timeout

            if (!empty($duration) && is_numeric($duration) && (float)$duration > 0) {
                Debug::log('Duration (ffprobe): ' . $duration . ' seconds', __METHOD__);
                $durationValues['ffprobe'] = (float)$duration;
                // Preferred method - return immediately if successful
                Debug::logExit(__METHOD__, $duration);
                return (float) $duration;
            }
        }

        // Method 2: Try more precise ffprobe command for short videos
        if (file_exists($ffprobePath) && is_executable($ffprobePath)) {
            Debug::log('Trying alternative ffprobe method for short video duration', __METHOD__);
            $command = sprintf(
                '%s -v error -select_streams v:0 -show_entries stream=duration -of default=noprint_wrappers=1:nokey=1 %s',
                escapeshellcmd($ffprobePath),
                escapeshellarg($videoPath)
            );

            $duration = $this->executeCommandWithTimeout($command, 15); // Increased timeout

            if (!empty($duration) && is_numeric($duration) && (float)$duration > 0) {
                Debug::log('Duration (ffprobe stream method): ' . $duration . ' seconds', __METHOD__);
                $durationValues['ffprobe_stream'] = (float)$duration;
                // Second best method - return immediately if successful
                Debug::logExit(__METHOD__, $duration);
                return (float) $duration;
            }
        }

        // Method 3: Try yet another ffprobe approach targeting video stream explicitly
        if (file_exists($ffprobePath) && is_executable($ffprobePath)) {
            Debug::log('Trying ffprobe with full stream info approach', __METHOD__);
            $command = sprintf(
                '%s -v error -select_streams v:0 -show_format -show_streams %s',
                escapeshellcmd($ffprobePath),
                escapeshellarg($videoPath)
            );

            $output = $this->executeCommandWithTimeout($command, 15);
            
            // Try to extract duration using more flexible parsing
            if (!empty($output)) {
                if (preg_match('/duration=([0-9\.]+)/i', $output, $matches)) {
                    $duration = (float)$matches[1];
                    if ($duration > 0) {
                        Debug::log('Duration (ffprobe full info): ' . $duration . ' seconds', __METHOD__);
                        $durationValues['ffprobe_full'] = $duration;
                    }
                }
            }
        }

        // Method 4: Fallback to ffmpeg if ffprobe fails, with special MOV file handling
        Debug::log('Trying fallback ffmpeg method for duration', __METHOD__);
        // Check file extension for QuickTime/MOV-specific settings
        $isQuickTime = (strtolower(pathinfo($videoPath, PATHINFO_EXTENSION)) === 'mov');
        if ($isQuickTime) {
            Debug::log('QuickTime/MOV file detected, using enhanced options', __METHOD__);
            $command = sprintf(
                '%s -f mov -i %s 2>&1',
                escapeshellcmd($this->ffmpegPath),
                escapeshellarg($videoPath)
            );
        } else {
            $command = sprintf(
                '%s -i %s 2>&1',
                escapeshellcmd($this->ffmpegPath),
                escapeshellarg($videoPath)
            );
        }

        $output = $this->executeCommandWithTimeout($command, 15); // Increased timeout
        
        // Parse duration from ffmpeg output
        if (preg_match('/Duration: ([0-9]{2}):([0-9]{2}):([0-9]{2}\.[0-9]+)/', $output, $matches)) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            $seconds = (float) $matches[3];
            
            $duration = $hours * 3600 + $minutes * 60 + $seconds;
            Debug::log('Duration (ffmpeg): ' . $duration . ' seconds', __METHOD__);
            $durationValues['ffmpeg'] = $duration;
        }
        
        // Method 5: Try to get last keyframe timestamp as approximation for duration
        if (file_exists($ffprobePath) && is_executable($ffprobePath)) {
            Debug::log('Trying to get last keyframe timestamp', __METHOD__);
            $command = sprintf(
                '%s -v error -select_streams v:0 -skip_frame nokey -show_entries frame=pts_time -of csv=p=0 %s | tail -n 1',
                escapeshellcmd($ffprobePath),
                escapeshellarg($videoPath)
            );
            
            $lastKeyframePts = $this->executeCommandWithTimeout($command, 20); // Allow more time for keyframe extraction
            
            if (!empty($lastKeyframePts) && is_numeric($lastKeyframePts) && (float)$lastKeyframePts > 0) {
                Debug::log('Duration approximation (last keyframe): ' . $lastKeyframePts . ' seconds', __METHOD__);
                $durationValues['keyframe'] = (float)$lastKeyframePts;
            }
        }

        // Method 6: Try to get frame count and framerate for more accurate duration
        if (file_exists($ffprobePath) && is_executable($ffprobePath)) {
            Debug::log('Trying frame count method for duration', __METHOD__);
            // Get total frames using a different approach
            $frameCommand = sprintf(
                '%s -v error -count_frames -select_streams v:0 -show_entries stream=nb_read_frames -of default=noprint_wrappers=1:nokey=1 %s',
                escapeshellcmd($ffprobePath),
                escapeshellarg($videoPath)
            );
            
            $frameCount = $this->executeCommandWithTimeout($frameCommand, 20); // Increased timeout
            
            // If that fails, try to estimate frame count from bit rate and file size
            if (empty($frameCount) || !is_numeric($frameCount) || (int)$frameCount <= 0) {
                $bitRateCommand = sprintf(
                    '%s -v error -select_streams v:0 -show_entries stream=bit_rate -of default=noprint_wrappers=1:nokey=1 %s',
                    escapeshellcmd($ffprobePath),
                    escapeshellarg($videoPath)
                );
                
                $bitRate = $this->executeCommandWithTimeout($bitRateCommand, 10);
                $fileSize = filesize($videoPath);
                
                if (!empty($bitRate) && is_numeric($bitRate) && (int)$bitRate > 0 && $fileSize > 0) {
                    // Rough frame count estimate based on file size and bit rate
                    // This is a very rough estimate, assuming video dominates the file size
                    $estimatedDuration = ($fileSize * 8) / (float)$bitRate;
                    if ($estimatedDuration > 0) {
                        Debug::log('Duration estimated from bit rate: ' . $estimatedDuration . ' seconds', __METHOD__);
                        $durationValues['bitrate_estimate'] = $estimatedDuration;
                    }
                }
            } else {
                $frameCount = (int)$frameCount;
                
                // Get frame rate
                $rateCommand = sprintf(
                    '%s -v error -select_streams v:0 -show_entries stream=r_frame_rate -of default=noprint_wrappers=1:nokey=1 %s',
                    escapeshellcmd($ffprobePath),
                    escapeshellarg($videoPath)
                );
                
                $frameRate = $this->executeCommandWithTimeout($rateCommand, 10);
                
                if (!empty($frameRate) && strpos($frameRate, '/') !== false) {
                    // Parse frame rate fraction (e.g., "30000/1001")
                    $rateParts = explode('/', $frameRate);
                    if (count($rateParts) == 2 && is_numeric($rateParts[0]) && is_numeric($rateParts[1]) && (float)$rateParts[1] > 0) {
                        $frameRateValue = (float)$rateParts[0] / (float)$rateParts[1];
                        if ($frameRateValue > 0) {
                            $duration = $frameCount / $frameRateValue;
                            Debug::log('Duration (frame count method): ' . $duration . ' seconds', __METHOD__);
                            $durationValues['frame_count'] = $duration;
                        }
                    }
                }
            }
        }
        
        // Method 7: Try system 'file' command as a fallback
        if (function_exists('exec')) {
            Debug::log('Trying file command for duration detection', __METHOD__);
            $command = sprintf('file %s', escapeshellarg($videoPath));
            @exec($command, $fileOutput, $returnVar);
            
            $fileOutputStr = implode(' ', $fileOutput);
            if ($returnVar === 0 && preg_match('/Duration: ([0-9:\.]+)/', $fileOutputStr, $matches)) {
                $durationStr = $matches[1];
                $durationParts = explode(':', $durationStr);
                if (count($durationParts) === 3) {
                    $hours = (int) $durationParts[0];
                    $minutes = (int) $durationParts[1];
                    $seconds = (float) $durationParts[2];
                    
                    $duration = $hours * 3600 + $minutes * 60 + $seconds;
                    Debug::log('Duration (file command): ' . $duration . ' seconds', __METHOD__);
                    $durationValues['file_command'] = $duration;
                }
            }
        }
        
        // Method 8: Try metadata extraction if available
        if (function_exists('exec')) {
            Debug::log('Trying exiftool for duration detection', __METHOD__);
            $command = sprintf('exiftool -n -Duration %s 2>/dev/null', escapeshellarg($videoPath));
            @exec($command, $exifOutput, $returnVar);
            
            $exifOutputStr = implode(' ', $exifOutput);
            if ($returnVar === 0 && preg_match('/Duration\s*:\s*([0-9\.]+)/', $exifOutputStr, $matches)) {
                $duration = (float)$matches[1];
                if ($duration > 0) {
                    Debug::log('Duration (exiftool): ' . $duration . ' seconds', __METHOD__);
                    $durationValues['exiftool'] = $duration;
                }
            }
        }
        
        // Check if we have any valid durations from the methods tried
        if (!empty($durationValues)) {
            Debug::log('Comparing collected duration values: ' . json_encode($durationValues), __METHOD__);
            
            // Sort by reliability (using the order we added them as a rough proxy for reliability)
            $preferredOrder = [
                'ffprobe', 'ffprobe_stream', 'ffprobe_full', 'ffmpeg', 
                'keyframe', 'frame_count', 'file_command', 'exiftool', 
                'bitrate_estimate'
            ];
            
            // First try to find a duration using the preferred order
            foreach ($preferredOrder as $method) {
                if (isset($durationValues[$method]) && $durationValues[$method] > 0) {
                    Debug::log('Selected duration from method ' . $method . ': ' . $durationValues[$method] . ' seconds', __METHOD__);
                    Debug::logExit(__METHOD__, $durationValues[$method]);
                    return (float) $durationValues[$method];
                }
            }
            
            // If that fails, use the median value as it's likely to be more reliable than extremes
            $values = array_values($durationValues);
            sort($values);
            $median = $values[intval(count($values) / 2)];
            
            Debug::log('Using median of collected durations: ' . $median . ' seconds', __METHOD__);
            Debug::logExit(__METHOD__, $median);
            return (float) $median;
        }
        
        // Method 9: Try to determine by file size as a last resort if no other methods worked
        $fileSize = filesize($videoPath);
        if ($fileSize > 0) {
            Debug::log('Attempting to estimate duration from file size using improved heuristics', __METHOD__);
            
            // Improved file size estimation with better heuristics based on common video bitrates
            // Average web video is roughly 2-5 Mbps (250-625 KB/s)
            $assumedBitrateBytesPerSecond = 500 * 1024 / 8; // Assume 500 Kbps as middle ground
            
            // Adjust assumed bitrate based on file size
            if ($fileSize < 1048576) { // Less than 1MB - likely very low bitrate
                $assumedBitrateBytesPerSecond = 100 * 1024 / 8; // 100 Kbps
                $minimumDuration = 1.0; // Minimum sensible duration
            } else if ($fileSize < 10485760) { // Less than 10MB - likely low bitrate
                $assumedBitrateBytesPerSecond = 250 * 1024 / 8; // 250 Kbps
                $minimumDuration = 3.0; // Minimum sensible duration
            } else if ($fileSize < 104857600) { // Less than 100MB - likely medium bitrate
                $assumedBitrateBytesPerSecond = 500 * 1024 / 8; // 500 Kbps
                $minimumDuration = 10.0; // Minimum sensible duration
            } else { // Larger file - likely higher bitrate
                $assumedBitrateBytesPerSecond = 1000 * 1024 / 8; // 1 Mbps
                $minimumDuration = 30.0; // Minimum sensible duration
            }
            
            // Estimate duration from file size and assumed bitrate
            $estimatedDuration = $fileSize / $assumedBitrateBytesPerSecond;
            $estimatedDuration = max($minimumDuration, $estimatedDuration);
            
            // Cap extremely long durations to prevent unreasonable estimates
            $estimatedDuration = min($estimatedDuration, 7200.0); // Cap at 2 hours
            
            Debug::log('Improved duration estimate from file size: ' . $estimatedDuration . ' seconds', __METHOD__);
            Debug::logExit(__METHOD__, $estimatedDuration);
            return (float) $estimatedDuration;
        }

        // As an absolute last resort, return a reasonable default duration
        // This should almost never be reached with all the methods above
        Debug::logError('Failed to determine video duration using all methods, using conservative default', __METHOD__);
        $defaultDuration = 20.0; // Reduced default to a more reasonable 20 seconds instead of 60
        Debug::logExit(__METHOD__, $defaultDuration);
        return $defaultDuration;
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
        
        // Get file extension and MIME type information
        $extension = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION));
        $isQuickTime = ($extension === 'mov');
        $isAVI = ($extension === 'avi');
        
        // For more accurate detection, try using file command if available
        if (function_exists('exec')) {
            $fileCommand = sprintf('file -b --mime-type %s', escapeshellarg($videoPath));
            @exec($fileCommand, $mimeOutput, $returnVar);
            if ($returnVar === 0 && !empty($mimeOutput)) {
                $detectedMime = trim($mimeOutput[0]);
                if (strpos($detectedMime, 'video/quicktime') !== false) {
                    $isQuickTime = true;
                    Debug::log('MIME type detection confirms QuickTime/MOV format: ' . $detectedMime, __METHOD__);
                }
            }
        }
        
        // Log the detected format for debugging
        Debug::log('Detected video format: ' . $extension . ($isQuickTime ? ' (QuickTime/MOV)' : '') . 
            ($isAVI ? ' (AVI)' : ''), __METHOD__);
        
        // Create a temporary file for the frame
        $outputPath = tempnam(sys_get_temp_dir(), 'vidthumb_') . '.jpg';
        
        // Determine if we need enhanced options based on file size (large files may need different approach)
        $fileSize = filesize($videoPath);
        $fileSizeMB = $fileSize / 1048576;
        $needsEnhancedOptions = ($fileSizeMB > 100); // For files larger than 100MB
        
        // Use a longer timeout for larger files
        if ($timeout === null) {
            // Scale timeout based on file size
            $timeout = min(30, max(10, intval($fileSizeMB / 10))); 
        }
        
        // First attempt - standard commands based on format
        $extractionSuccessful = false;
        $attempts = 0;
        $maxAttempts = 3;
        
        while (!$extractionSuccessful && $attempts < $maxAttempts) {
            $attempts++;
            Debug::log('Frame extraction attempt #' . $attempts, __METHOD__);
            
            // Clean up previous attempt output if it exists
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }
            
            // Build the FFmpeg command with specific format options for different video formats
            if ($isQuickTime) {
                // Enhanced handling for MOV/QuickTime files with multiple approaches
                if ($attempts === 1) {
                    // First attempt: Use more robust MOV-specific options
                    Debug::log('Using enhanced QuickTime-specific FFmpeg command (attempt 1)', __METHOD__);
                    $command = sprintf(
                        '%s -y -probesize 100M -analyzeduration 100M -i %s -ss %f -vframes 1 -q:v 2 -f image2 %s',
                        escapeshellcmd($this->ffmpegPath),
                        escapeshellarg($videoPath),
                        (float) $timeInSeconds,
                        escapeshellarg($outputPath)
                    );
                } else if ($attempts === 2) {
                    // Second attempt: Try a different approach with -ss before input (faster seeking)
                    Debug::log('Using alternative QuickTime approach with -ss before input (attempt 2)', __METHOD__);
                    $command = sprintf(
                        '%s -y -ss %f -i %s -vframes 1 -q:v 2 -pix_fmt yuvj420p -f image2 %s',
                        escapeshellcmd($this->ffmpegPath),
                        (float) $timeInSeconds,
                        escapeshellarg($videoPath),
                        escapeshellarg($outputPath)
                    );
                } else {
                    // Third attempt: Use most basic approach with shorter timestamp for reliable frame grab
                    Debug::log('Using basic approach with earlier timestamp (attempt 3)', __METHOD__);
                    // Use an earlier timestamp to ensure we get something
                    $safeTimeStamp = max(0.5, min($timeInSeconds, $timeInSeconds * 0.5));
                    $command = sprintf(
                        '%s -y -i %s -ss %f -vframes 1 -f image2 %s',
                        escapeshellcmd($this->ffmpegPath),
                        escapeshellarg($videoPath),
                        (float) $safeTimeStamp,
                        escapeshellarg($outputPath)
                    );
                }
            } else if ($isAVI) {
                // Special handling for AVI files
                Debug::log('Using AVI-specific FFmpeg command', __METHOD__);
                if ($attempts > 1) {
                    // Try alternative approach for subsequent attempts
                    $command = sprintf(
                        '%s -y -ss %f -i %s -vframes 1 -q:v 2 -f image2 %s',
                        escapeshellcmd($this->ffmpegPath),
                        (float) $timeInSeconds,
                        escapeshellarg($videoPath),
                        escapeshellarg($outputPath)
                    );
                } else {
                    $command = sprintf(
                        '%s -y -i %s -ss %f -vframes 1 -q:v 2 -f image2 %s',
                        escapeshellcmd($this->ffmpegPath),
                        escapeshellarg($videoPath),
                        (float) $timeInSeconds,
                        escapeshellarg($outputPath)
                    );
                }
            } else {
                // Default command for other formats (MP4, etc.)
                if ($attempts > 1) {
                    // Alternative approach for subsequent attempts
                    $command = sprintf(
                        '%s -y -i %s -ss %f -vframes 1 -q:v 2 -f image2 %s',
                        escapeshellcmd($this->ffmpegPath),
                        escapeshellarg($videoPath),
                        (float) $timeInSeconds,
                        escapeshellarg($outputPath)
                    );
                } else {
                    $command = sprintf(
                        '%s -y -ss %f -i %s -vframes 1 -q:v 2 -f image2 %s',
                        escapeshellcmd($this->ffmpegPath),
                        (float) $timeInSeconds,
                        escapeshellarg($videoPath),
                        escapeshellarg($outputPath)
                    );
                }
            }
            
            Debug::log('Extracting frame at ' . $timeInSeconds . 's to ' . $outputPath . ' (Attempt ' . $attempts . ')', __METHOD__);
            
            try {
                $this->executeCommandWithTimeout($command, $timeout);
                
                // Verify the output file exists and is not empty
                if (file_exists($outputPath) && filesize($outputPath) > 0) {
                    Debug::log('Frame extracted successfully to: ' . $outputPath . ' on attempt #' . $attempts, __METHOD__);
                    $extractionSuccessful = true;
                    break;
                }
                
                Debug::logError('Frame extraction attempt #' . $attempts . ' failed - output file empty or missing', __METHOD__);
                
                // Clean up empty file if it exists
                if (file_exists($outputPath)) {
                    @unlink($outputPath);
                }
                
                // If this was the last attempt, try a more radical approach as a final attempt
                if ($attempts === $maxAttempts - 1 && $isQuickTime) {
                    Debug::log('Trying one final approach for MOV file extraction', __METHOD__);
                    
                    // For QuickTime files, try extracting the first frame as a last resort
                    $finalCommand = sprintf(
                        '%s -y -i %s -vframes 1 -f image2 %s',
                        escapeshellcmd($this->ffmpegPath),
                        escapeshellarg($videoPath),
                        escapeshellarg($outputPath)
                    );
                    
                    $this->executeCommandWithTimeout($finalCommand, $timeout);
                    
                    if (file_exists($outputPath) && filesize($outputPath) > 0) {
                        Debug::log('Successfully extracted first frame as fallback for MOV file', __METHOD__);
                        $extractionSuccessful = true;
                        break;
                    }
                }
                
            } catch (\Exception $e) {
                Debug::logError('Exception during frame extraction attempt #' . $attempts . ': ' . $e->getMessage(), __METHOD__);
                
                // Clean up any partial output
                if (file_exists($outputPath)) {
                    @unlink($outputPath);
                }
            }
        }
        
        if ($extractionSuccessful) {
            Debug::logExit(__METHOD__, $outputPath);
            return $outputPath;
        }
        
        Debug::logError('All frame extraction attempts failed for: ' . $videoPath, __METHOD__);
        
        // Final cleanup
        if (file_exists($outputPath)) {
            @unlink($outputPath);
        }
        
        Debug::logExit(__METHOD__, null);
        return null;
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
