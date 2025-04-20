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

    public function extractFrame($videoPath, $timeInSeconds)
    {
        $this->cleanupOldTempFiles(); // Clean up old temp files before extracting
        try {
            Debug::logEntry(__METHOD__, ['video' => $videoPath, 'time' => $timeInSeconds]);

            $this->validateVideo($videoPath);
            $this->validateFFmpeg();

            $outputPath = $this->generateTempPath('jpg');
            $timeStr = $this->formatTimeString($timeInSeconds);

            // Try multiple strategies for frame extraction
            foreach ($this->getExtractionStrategies($timeStr, $outputPath) as $attempt => $strategy) {
                try {
                    $result = $this->executeFFmpeg($strategy['command'], $strategy['message']);
                    
                    if ($result && file_exists($outputPath) && filesize($outputPath) > 0) {
                        Debug::log(
                            sprintf('Successfully extracted frame using strategy %d', $attempt),
                            __METHOD__
                        );
                        return $outputPath;
                    }
                } catch (\Exception $e) {
                    Debug::logWarning(
                        sprintf('Strategy %d failed: %s', $attempt, $e->getMessage()),
                        __METHOD__
                    );
                    continue;
                }
            }

            throw new \RuntimeException('All frame extraction strategies failed');

        } catch (\Exception $e) {
            Debug::logError(
                sprintf('Frame extraction failed: %s', $e->getMessage()),
                __METHOD__
            );
            throw $e;
        }
    }

    public function extractFrames($videoPath, $count = 5)
    {
        try {
            Debug::logEntry(__METHOD__, ['video' => $videoPath, 'count' => $count]);

            $this->validateVideo($videoPath);
            $duration = $this->getVideoDuration($videoPath);
            
            if ($duration <= 0) {
                throw new \RuntimeException('Invalid video duration');
            }

            $frames = [];
            $interval = $duration / ($count + 1);
            
            for ($i = 1; $i <= $count; $i++) {
                $time = $interval * $i;
                
                try {
                    $framePath = $this->extractFrame($videoPath, $time);
                    if ($framePath) {
                        $frames[] = $framePath;
                    }
                } catch (\Exception $e) {
                    Debug::logWarning(
                        sprintf('Failed to extract frame at position %d: %s', $i, $e->getMessage()),
                        __METHOD__
                    );
                    continue;
                }
            }

            if (empty($frames)) {
                throw new \RuntimeException('Failed to extract any frames');
            }

            return $frames;

        } catch (\Exception $e) {
            Debug::logError(
                sprintf('Multiple frame extraction failed: %s', $e->getMessage()),
                __METHOD__
            );
            throw $e;
        }
    }

    public function getVideoDuration($videoPath)
    {
        try {
            Debug::logEntry(__METHOD__, ['video' => $videoPath]);

            $this->validateVideo($videoPath);
            
            // Try multiple duration detection methods
            foreach ($this->getDurationDetectionStrategies() as $strategy) {
                try {
                    $duration = $this->executeDurationStrategy($strategy, $videoPath);
                    if ($duration > 0) {
                        return $duration;
                    }
                } catch (\Exception $e) {
                    Debug::logWarning(
                        sprintf('Duration strategy failed: %s', $e->getMessage()),
                        __METHOD__
                    );
                    continue;
                }
            }

            throw new \RuntimeException('Failed to detect video duration');

        } catch (\Exception $e) {
            Debug::logError(
                sprintf('Duration detection failed: %s', $e->getMessage()),
                __METHOD__
            );
            throw $e;
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
}
