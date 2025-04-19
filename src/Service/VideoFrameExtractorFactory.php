<?php
namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Stdlib\VideoFrameExtractor;
use VideoThumbnail\Stdlib\Debug;
use RuntimeException;

class VideoFrameExtractorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Initialize debug logging
        $settings = $services->get('Omeka\Settings');
        Debug::init($settings);
        Debug::logEntry(__METHOD__);
        
        // Get configured path
        $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
        Debug::log('Configured FFmpeg path: ' . $ffmpegPath, __METHOD__);
        
        // Validate or auto-detect FFmpeg path
        $videoFrameExtractor = $this->detectAndValidateFfmpeg($settings, $ffmpegPath);
        if (!$videoFrameExtractor) {
            throw new RuntimeException('FFmpeg validation failed. Unable to create VideoFrameExtractor.');
        }

        Debug::logExit(__METHOD__);
        return $videoFrameExtractor;
    }

    private function detectAndValidateFfmpeg($settings, $ffmpegPath)
    {
        if (!empty($ffmpegPath) && (strpos($ffmpegPath, '/nix/store') !== false)) {
            Debug::log('Detected potential stale Nix store path, clearing and forcing auto-detection', __METHOD__);
            $this->persistPathIfChanged($settings, '');
            $ffmpegPath = '';
        } elseif (!empty($ffmpegPath) && (!file_exists($ffmpegPath) || !is_executable($ffmpegPath))) {
            Debug::log('Invalid FFmpeg path, will auto-detect new path', __METHOD__);
            $this->persistPathIfChanged($settings, '');
            $ffmpegPath = '';
        } elseif (file_exists($ffmpegPath) && is_executable($ffmpegPath)) {
            Debug::log('FFmpeg exists and is executable at configured path', __METHOD__);
            return $this->validateFfmpegAndCreate($ffmpegPath);
        }

        Debug::log('Configured FFmpeg path is invalid, trying to auto-detect...', __METHOD__);
        return $this->autoDetectFfmpeg($settings);
    }
    
    /**
     * Helper to persist the FFmpeg path only when it changes
     * 
     * @param \Omeka\Settings\Settings $settings
     * @param string $newPath
     * @return void
     */
    private function persistPathIfChanged($settings, $newPath)
    {
        $currentPath = $settings->get('videothumbnail_ffmpeg_path', '');
        
        if ($currentPath !== $newPath) {
            Debug::log('FFmpeg path changed from "' . $currentPath . '" to "' . $newPath . '", persisting change', __METHOD__);
            $settings->set('videothumbnail_ffmpeg_path', $newPath);
        } else {
            Debug::log('FFmpeg path unchanged, skipping persistence', __METHOD__);
        }
    }

    private function autoDetectFfmpeg($settings)
    {
        $detectionMethods = [
            [$this, 'detectUsingWhich'],
            [$this, 'detectUsingType'],
            [$this, 'detectUsingCommandExists'],
            [$this, 'detectUsingEnvPath'],
            [$this, 'detectUsingCommonPaths'],
        ];
        
        foreach ($detectionMethods as $method) {
            $result = $method($settings);
            if ($result) {
                return $result;
            }
        }

        Debug::logError('FFmpeg not found using any detection method.', __METHOD__);
        return null; // No valid FFmpeg path detected
    }
    
    private function detectUsingWhich($settings)
    {
        Debug::log('Detecting FFmpeg using \'which\' command', __METHOD__);
        $output = $this->executeWithTimeout('which ffmpeg 2>/dev/null', 5);
        
        if ($output !== null && !empty($output)) {
            $ffmpegPath = trim($output[0]);
            Debug::log('Found FFmpeg at: ' . $ffmpegPath, __METHOD__);
            $this->persistPathIfChanged($settings, $ffmpegPath);
            return $this->validateFfmpegAndCreate($ffmpegPath);
        }
        
        Debug::log('FFmpeg not found using \'which\'', __METHOD__);
        return null;
    }
    
    private function detectUsingType($settings)
    {
        Debug::log('Detecting FFmpeg using \'type\' command', __METHOD__);
        $output = $this->executeWithTimeout('type -p ffmpeg 2>/dev/null', 5);
        
        if ($output !== null && !empty($output)) {
            $ffmpegPath = trim($output[0]);
            Debug::log('Found FFmpeg at: ' . $ffmpegPath, __METHOD__);
            $this->persistPathIfChanged($settings, $ffmpegPath);
            return $this->validateFfmpegAndCreate($ffmpegPath);
        }
        
        Debug::log('FFmpeg not found using \'type\'', __METHOD__);
        return null;
    }
    
    private function detectUsingCommandExists($settings)
    {
        Debug::log('Detecting FFmpeg using \'command -v\'', __METHOD__);
        $output = $this->executeWithTimeout('command -v ffmpeg 2>/dev/null', 5);
        
        if ($output !== null && !empty($output)) {
            $ffmpegPath = trim($output[0]);
            Debug::log('Found FFmpeg at: ' . $ffmpegPath, __METHOD__);
            $this->persistPathIfChanged($settings, $ffmpegPath);
            return $this->validateFfmpegAndCreate($ffmpegPath);
        }
        
        Debug::log('FFmpeg not found using \'command -v\'', __METHOD__);
        return null;
    }
    
    private function detectUsingEnvPath($settings)
    {
        Debug::log('Detecting FFmpeg in PATH environment variable', __METHOD__);
        $paths = explode(PATH_SEPARATOR, getenv('PATH'));
        
        foreach ($paths as $path) {
            $ffmpegPath = rtrim($path, '/') . '/ffmpeg';
            if (file_exists($ffmpegPath) && is_executable($ffmpegPath)) {
                Debug::log('Found FFmpeg at: ' . $ffmpegPath, __METHOD__);
                $this->persistPathIfChanged($settings, $ffmpegPath);
                return $this->validateFfmpegAndCreate($ffmpegPath);
            }
        }
        
        Debug::log('FFmpeg not found in PATH', __METHOD__);
        return null;
    }
    
    private function detectUsingCommonPaths($settings)
    {
        Debug::log('Checking common paths for FFmpeg', __METHOD__);
        $commonPaths = [
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/local/bin/ffmpeg',
            '/opt/bin/ffmpeg',
            '/usr/sbin/ffmpeg',
            '/usr/local/sbin/ffmpeg',
        ];
        
        foreach ($commonPaths as $ffmpegPath) {
            if (file_exists($ffmpegPath) && is_executable($ffmpegPath)) {
                Debug::log('Found FFmpeg at common path: ' . $ffmpegPath, __METHOD__);
                $this->persistPathIfChanged($settings, $ffmpegPath);
                return $this->validateFfmpegAndCreate($ffmpegPath);
            }
        }
        
        Debug::log('FFmpeg not found in common paths', __METHOD__);
        return null;
    }

    /**
     * Validates FFmpeg with timeout and creates a VideoFrameExtractor instance
     *
     * @param string $ffmpegPath Path to FFmpeg executable
     * @param int $timeout Timeout in seconds
     * @return VideoFrameExtractor|null
     */
    protected function validateFfmpegAndCreate($ffmpegPath, $timeout = 10)
    {
        Debug::log('Validating FFmpeg at: ' . $ffmpegPath . ' with ' . $timeout . 's timeout', __METHOD__);
        
        try {
            $command = escapeshellcmd($ffmpegPath) . ' -version 2>/dev/null';
            $output = $this->executeWithTimeout($command, $timeout);
            
            if ($output !== null && !empty($output)) {
                Debug::log('FFmpeg validation successful: ' . $output[0], __METHOD__);
                Debug::logExit(__METHOD__);
                return new VideoFrameExtractor($ffmpegPath);
            }

            Debug::logError('FFmpeg validation failed for: ' . $ffmpegPath, __METHOD__);
        } catch (\Exception $e) {
            Debug::logError('Exception during validation: ' . $e->getMessage(), __METHOD__);
        }

        return null; // Return null if validation fails
    }
    
    /**
     * Execute a command with timeout using proc_open
     *
     * @param string $command The command to execute
     * @param int $timeout Timeout in seconds
     * @return array|null Array of output lines or null if timeout or error
     */
    private function executeWithTimeout($command, $timeout)
    {
        Debug::log('Executing command with timeout: ' . $command, __METHOD__);
        
        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w']   // stderr
        ];
        
        // Start the process
        $process = proc_open($command, $descriptorspec, $pipes);
        
        if (!is_resource($process)) {
            Debug::logError('Failed to execute command: ' . $command, __METHOD__);
            return null;
        }
        
        // Set pipes to non-blocking mode
        stream_set_blocking($pipes[1], 0);
        stream_set_blocking($pipes[2], 0);
        
        // Close stdin
        fclose($pipes[0]);
        
        $output = [];
        $startTime = time();
        
        do {
            $status = proc_get_status($process);
            
            // Read from stdout
            $stdout = stream_get_contents($pipes[1]);
            if ($stdout) {
                $output = array_merge($output, explode("\n", trim($stdout)));
            }
            
            // Check for timeout
            if ((time() - $startTime) > $timeout) {
                Debug::logError('Command timed out after ' . $timeout . 's: ' . $command, __METHOD__);
                proc_terminate($process, 9); // SIGKILL
                proc_close($process);
                return null;
            }
            
            // Short sleep to prevent CPU hogging
            usleep(10000); // 10ms
            
        } while ($status['running']);
        
        // Get remaining output
        $stdout = stream_get_contents($pipes[1]);
        if ($stdout) {
            $output = array_merge($output, explode("\n", trim($stdout)));
        }
        
        // Clean up
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        
        if ($exitCode !== 0) {
            Debug::logError('Command failed with exit code ' . $exitCode . ': ' . $command, __METHOD__);
            return null;
        }
        
        return array_filter($output); // Remove empty lines
    }
}
