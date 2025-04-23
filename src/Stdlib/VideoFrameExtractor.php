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

    public function __construct($ffmpegPath)
    {
        $this->ffmpegPath = $ffmpegPath;
        $this->tempDir = OMEKA_PATH . '/files/temp/video-thumbnails';
        $this->ensureTempDir();
    }

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

    protected function executeDurationStrategy($strategy, $videoPath)
    {
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

        return true;
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
            if (!mkdir($this->tempDir, 0777, true)) {
                throw new \RuntimeException('Failed to create temporary directory');
            }
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
        foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . 'frame_*') as $file) {
            if (is_file($file) && ($now - filemtime($file)) > $maxAge) {
                @unlink($file);
            }
        }
    }

    public function getLastError()
    {
        return $this->lastError;
    }

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
