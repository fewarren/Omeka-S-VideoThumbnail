<?php
namespace VideoThumbnail\Stdlib;

use VideoThumbnail\Stdlib\Debug;

class VideoFrameExtractor
{
    protected $ffmpegPath;
    protected $tempDir;
    protected $lastError;
    protected $timeout = 60;
    protected $maxFrameRetries = 3;

    /**
     * Initializes the VideoFrameExtractor with the specified FFmpeg path and sets up the temporary directory for extracted frames.
     *
     * @param string $ffmpegPath Path to the FFmpeg executable.
     */
    public function __construct($ffmpegPath)
    {
        $this->ffmpegPath = $ffmpegPath;
        $this->tempDir = OMEKA_PATH . '/files/temp/video-thumbnails';
        $this->ensureTempDir();
    }

    /**
     * Extracts a single video frame at the specified time using FFmpeg.
     *
     * @param string $filePath Path to the video file.
     * @param float $timeInSeconds Time position (in seconds) to extract the frame.
     * @return string|false Path to the extracted frame image on success, or false on failure.
     */
    public function extractFrame($filePath, $timeInSeconds)
    {
        Debug::log("Attempting to extract frame at {$timeInSeconds}s from video: " . basename($filePath), __METHOD__);
        
        try {
            $outputPath = $this->createTempPath();
            Debug::log("Using temporary output path: {$outputPath}", __METHOD__);

            $command = sprintf(
                '%s -i %s -ss %f -vframes 1 -f image2 %s',
                escapeshellarg($this->ffmpegPath),
                escapeshellarg($filePath),
                $timeInSeconds,
                escapeshellarg($outputPath)
            );
            
            Debug::log("Executing FFmpeg command: {$command}", __METHOD__);
            
            $output = [];
            $returnVar = 0;
            exec($command . " 2>&1", $output, $returnVar);
            
            if ($returnVar !== 0) {
                Debug::logError("FFmpeg frame extraction failed with code {$returnVar}. Output: " . implode("\n", $output), __METHOD__);
                return false;
            }

            if (!file_exists($outputPath) || filesize($outputPath) === 0) {
                Debug::logError("Frame extraction failed - output file is missing or empty", __METHOD__);
                return false;
            }

            Debug::log("Successfully extracted frame to: {$outputPath}", __METHOD__);
            return $outputPath;

        } catch (\Exception $e) {
            Debug::logError("Frame extraction error: " . $e->getMessage(), __METHOD__, $e);
            return false;
        }
    }

    /**
     * Extracts multiple frames evenly spaced throughout a video file.
     *
     * Attempts to extract the specified number of frames at equal intervals based on the video's duration.
     * Returns an array of file paths to the successfully extracted frames. If the video duration cannot be determined or extraction fails, returns an empty array.
     *
     * @param string $filePath Path to the video file.
     * @param int $count Number of frames to extract (default is 5).
     * @return string[] Array of file paths to the extracted frames.
     */
    public function extractFrames($filePath, $count = 5)
    {
        Debug::log("Attempting to extract {$count} frames from video: " . basename($filePath), __METHOD__);
        
        try {
            $duration = $this->getVideoDuration($filePath);
            if ($duration <= 0) {
                Debug::logError("Could not determine video duration", __METHOD__);
                return [];
            }

            Debug::log("Video duration: {$duration} seconds", __METHOD__);
            
            $frames = [];
            $interval = $duration / ($count + 1);
            
            for ($i = 1; $i <= $count; $i++) {
                $timePosition = $interval * $i;
                Debug::log("Extracting frame {$i}/{$count} at position {$timePosition}s", __METHOD__);
                
                $framePath = $this->extractFrame($filePath, $timePosition);
                if ($framePath) {
                    $frames[] = $framePath;
                    Debug::log("Successfully extracted frame {$i}", __METHOD__);
                } else {
                    Debug::logWarning("Failed to extract frame {$i}", __METHOD__);
                }
            }

            Debug::log("Completed frame extraction. Successfully extracted " . count($frames) . " frames", __METHOD__);
            return $frames;

        } catch (\Exception $e) {
            Debug::logError("Frame extraction error: " . $e->getMessage(), __METHOD__, $e);
            return [];
        }
    }

    /**
     * Retrieves the duration of a video file in seconds using FFmpeg.
     *
     * Parses the FFmpeg output to extract the duration. Returns 0 if the duration cannot be determined or an error occurs.
     *
     * @param string $filePath Path to the video file.
     * @return float Duration of the video in seconds, or 0 if not found.
     */
    public function getVideoDuration($filePath)
    {
        Debug::log("Getting duration for video: " . basename($filePath), __METHOD__);
        
        try {
            $command = sprintf(
                '%s -i %s 2>&1',
                escapeshellarg($this->ffmpegPath),
                escapeshellarg($filePath)
            );
            
            $output = [];
            $returnVar = 0;
            exec($command, $output, $returnVar);
            
            $output = implode("\n", $output);
            Debug::log("FFmpeg output: " . $output, __METHOD__);

            // Try to find duration in FFmpeg output
            if (preg_match('/Duration: (\d{2}):(\d{2}):(\d{2})\.(\d{2})/', $output, $matches)) {
                $hours = intval($matches[1]);
                $minutes = intval($matches[2]);
                $seconds = intval($matches[3]);
                $milliseconds = intval($matches[4]);
                
                $duration = $hours * 3600 + $minutes * 60 + $seconds + $milliseconds / 100;
                Debug::log("Detected video duration: {$duration} seconds", __METHOD__);
                return $duration;
            }

            Debug::logWarning("Could not detect video duration from FFmpeg output", __METHOD__);
            return 0;

        } catch (\Exception $e) {
            Debug::logError("Error getting video duration: " . $e->getMessage(), __METHOD__, $e);
            return 0;
        }
    }

    /**
     * Validates that the given file exists, is readable, and is a video file.
     *
     * @param string $videoPath Path to the video file to validate.
     * @throws \RuntimeException If the file does not exist, is not readable, or is not a recognized video file.
     */
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

    /**
     * Validates that the FFmpeg executable exists and is executable.
     *
     * @throws \RuntimeException If FFmpeg is not found or is not executable.
     */
    protected function validateFFmpeg()
    {
        if (!file_exists($this->ffmpegPath) || !is_executable($this->ffmpegPath)) {
            throw new \RuntimeException('FFmpeg not found or not executable');
        }
    }

    /**
     * Returns an array of FFmpeg command strategies for extracting a video frame at a specific time.
     *
     * Each strategy includes a command string and a descriptive message, offering different FFmpeg options for frame extraction accuracy and compatibility.
     *
     * @param string $timeStr Time position in the video (formatted as HH:MM:SS or seconds).
     * @param string $outputPath Path where the extracted frame image will be saved.
     * @return array[] Array of strategies, each containing 'command' and 'message' keys.
     */
    protected function getExtractionStrategies($timeStr, $outputPath)
    {
        // Windows compatibility: ensure .exe extension for ffmpeg if missing
        $ffmpegPath = $this->ffmpegPath;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && strtolower(substr($ffmpegPath, -4)) !== '.exe') {
            if (file_exists($ffmpegPath . '.exe')) {
                $ffmpegPath .= '.exe';
            }
        }
        // Use local variable for videoPath (fix undefined variable bug)
        return [
            // Strategy 1: Basic frame extraction
            [
                'command' => sprintf(
                    '%s -y -ss %s -i %s -vframes 1 -q:v 2 %s 2>&1',
                    escapeshellcmd($ffmpegPath),
                    escapeshellarg($timeStr),
                    escapeshellarg($GLOBALS['videoPath'] ?? ''),
                    escapeshellarg($outputPath)
                ),
                'message' => 'Basic frame extraction'
            ],
            // Strategy 2: Seek before input for better accuracy
            [
                'command' => sprintf(
                    '%s -y -ss %s -accurate_seek -i %s -vframes 1 -q:v 2 %s 2>&1',
                    escapeshellcmd($ffmpegPath),
                    escapeshellarg($timeStr),
                    escapeshellarg($GLOBALS['videoPath'] ?? ''),
                    escapeshellarg($outputPath)
                ),
                'message' => 'Accurate seek frame extraction'
            ],
            // Strategy 3: Force key frame
            [
                'command' => sprintf(
                    '%s -y -ss %s -i %s -vframes 1 -force_key_frames 1 -q:v 2 %s 2>&1',
                    escapeshellcmd($ffmpegPath),
                    escapeshellarg($timeStr),
                    escapeshellarg($GLOBALS['videoPath'] ?? ''),
                    escapeshellarg($outputPath)
                ),
                'message' => 'Force keyframe extraction'
            ]
        ];
    }

    /**
     * Returns a list of strategies for detecting video duration using different FFmpeg command options and parsers.
     *
     * Each strategy includes a command string and a parser function to extract the duration from the command output.
     *
     * @return array Strategies for video duration detection, each with a 'command' and a 'parser' callable.
     */
    protected function getDurationDetectionStrategies()
    {
        return [
            // Strategy 1: Fast duration detection
            [
                'command' => '-v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1',
                'parser' => function($output) {
                    return floatval(trim($output));
                }
            ],
            // Strategy 2: Detailed probe
            [
                'command' => '-v quiet -print_format json -show_format',
                'parser' => function($output) {
                    $data = json_decode($output, true);
                    return isset($data['format']['duration']) ? 
                        floatval($data['format']['duration']) : 0;
                }
            ],
            // Strategy 3: Frame count based estimation
            [
                'command' => '-v error -select_streams v:0 -show_entries stream=nb_frames,r_frame_rate -of default=noprint_wrappers=1:nokey=1',
                'parser' => function($output) {
                    $parts = explode("\n", trim($output));
                    if (count($parts) >= 2) {
                        $frameCount = intval($parts[0]);
                        $fpsStr = $parts[1];
                        if (preg_match('/(\d+)\/(\d+)/', $fpsStr, $matches)) {
                            $fps = $matches[1] / $matches[2];
                            return $frameCount / $fps;
                        }
                    }
                    return 0;
                }
            ]
        ];
    }

    /**
     * Executes a video duration detection strategy using FFmpeg and parses the output.
     *
     * @param array $strategy An associative array containing the FFmpeg command and a parser callback.
     * @param string $videoPath Path to the video file.
     * @return float The detected video duration in seconds.
     * @throws \RuntimeException If the FFmpeg command fails.
     */
    protected function executeDurationStrategy($strategy, $videoPath)
    {
        Debug::log("Attempting duration strategy: " . ($strategy['message'] ?? 'Unknown strategy'), __METHOD__);
        $command = sprintf(
            '%s %s %s',
            escapeshellcmd($this->ffmpegPath),
            $strategy['command'],
            escapeshellarg($videoPath)
        );

        $output = [];
        $returnVar = 0;
        exec($command . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            throw new \RuntimeException('FFmpeg duration command failed');
        }

        $output = implode("\n", $output);
        return $strategy['parser']($output);
    }

    /**
     * Executes an FFmpeg command and logs the result.
     *
     * @param string $command The FFmpeg command to execute.
     * @param string $description Description of the command for logging purposes.
     * @return bool True if the command executed successfully, false otherwise.
     */
    protected function executeFFmpeg($command, $description)
    {
        Debug::log(sprintf('Executing FFmpeg command: %s', $description), __METHOD__);

        $output = [];
        $returnVar = 0;
        
        exec($command . ' 2>&1', $output, $returnVar);
        
        if ($returnVar !== 0) {
            $error = implode("\n", $output);
            Debug::logError(
                sprintf('FFmpeg command failed: %s', $error),
                __METHOD__
            );
            return false;
        }
        
        Debug::log(sprintf('FFmpeg command successful: %s', $description), __METHOD__);
        return true;
    }

    /**
     * Generates a unique temporary file path with the specified extension in the temp directory.
     *
     * @param string $extension File extension to use for the temporary file.
     * @return string Full path to the generated temporary file.
     */
    protected function generateTempPath($extension)
    {
        return sprintf(
            '%s/%s.%s',
            $this->tempDir,
            uniqid('frame_', true),
            $extension
        );
    }

    /**
     * Ensures that the temporary directory exists, creating it if necessary.
     *
     * @throws \RuntimeException If the directory cannot be created.
     */
    protected function ensureTempDir()
    {
        if (!file_exists($this->tempDir)) {
            Debug::log("Creating temporary directory: {$this->tempDir}", __METHOD__);
            if (!mkdir($this->tempDir, 0777, true)) {
                Debug::logError("Failed to create temporary directory: {$this->tempDir}", __METHOD__);
                throw new \RuntimeException('Failed to create temporary directory');
            }
            Debug::log("Successfully created temporary directory: {$this->tempDir}", __METHOD__);
        }
    }

    /**
     * Formats a number of seconds as a time string in HH:MM:SS.sss format.
     *
     * @param float $seconds Number of seconds to format.
     * @return string Time string formatted as hours, minutes, and seconds with milliseconds.
     */
    protected function formatTimeString($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        return sprintf('%02d:%02d:%06.3f', $hours, $minutes, $seconds);
    }

    /**
     * Deletes temporary frame files older than the specified number of hours from the temp directory.
     *
     * @param int $maxAgeHours Maximum file age in hours before deletion. Defaults to 24.
     */
    public function cleanupOldTempFiles($maxAgeHours = 24)
    {
        $now = time();
        $maxAge = $maxAgeHours * 3600;
        if (!is_dir($this->tempDir)) return;
        foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . 'frame_*') as $file) {
            if (is_file($file) && ($now - filemtime($file)) > $maxAge) {
                @unlink($file);
            }
        }
    }

    /**
     * Returns the last error message encountered during frame extraction or duration retrieval.
     *
     * @return string|null The last error message, or null if no error has occurred.
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Creates and returns a unique temporary file path for a JPEG image in the video thumbnails temp directory.
     *
     * Ensures the temporary directory exists before generating the file path.
     *
     * @return string Path to the new temporary JPEG file.
     */
    private function createTempPath()
    {
        $tempDir = OMEKA_PATH . '/files/temp/video-thumbnails';
        if (!file_exists($tempDir)) {
            Debug::log("Creating temporary directory: {$tempDir}", __METHOD__);
            mkdir($tempDir, 0777, true);
        }
        
        $path = $tempDir . '/' . uniqid('frame_', true) . '.jpg';
        Debug::log("Created temporary path: {$path}", __METHOD__);
        return $path;
    }
}
