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

    /**
     * Validates the configured FFmpeg path or attempts auto-detection if invalid.
     *
     * If the provided FFmpeg path is missing, stale, or not executable, clears the stored path and tries to auto-detect a valid FFmpeg binary. Returns a validated VideoFrameExtractor instance if successful, or null if detection and validation fail.
     *
     * @return ?VideoFrameExtractor Validated VideoFrameExtractor instance or null if FFmpeg cannot be found or validated.
     */
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
     * Persists the FFmpeg path in settings only if it differs from the current value.
     *
     * Updates the stored FFmpeg path to the new value if it has changed; otherwise, no action is taken.
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

    /**
     * Attempts to locate and validate the FFmpeg executable using multiple detection strategies.
     *
     * Tries several methods in order to find a usable FFmpeg binary, returning a validated VideoFrameExtractor instance if successful, or null if detection fails.
     *
     * @return VideoFrameExtractor|null Validated extractor instance if FFmpeg is found, or null if not detected.
     */
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
    
    /**
     * Attempts to locate the FFmpeg executable using the 'which' command.
     *
     * If found, persists the detected path and returns a validated VideoFrameExtractor instance; otherwise, returns null.
     *
     * @return VideoFrameExtractor|null Validated VideoFrameExtractor instance if FFmpeg is found and valid, or null if not found.
     */
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
    
    /**
     * Attempts to detect the FFmpeg executable using the 'type -p ffmpeg' shell command.
     *
     * If found, persists the detected path in settings and returns a validated VideoFrameExtractor instance.
     * Returns null if FFmpeg is not found or validation fails.
     *
     * @return VideoFrameExtractor|null Validated extractor instance if detection and validation succeed, or null otherwise.
     */
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
    
    /**
     * Attempts to detect the FFmpeg executable using the 'command -v' shell command.
     *
     * If FFmpeg is found, persists the detected path in settings and returns a validated VideoFrameExtractor instance. Returns null if FFmpeg is not found or validation fails.
     *
     * @return VideoFrameExtractor|null Validated VideoFrameExtractor instance if detection and validation succeed, or null otherwise.
     */
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
    
    /**
     * Attempts to locate the FFmpeg executable by searching directories listed in the PATH environment variable.
     *
     * Returns a validated VideoFrameExtractor instance if FFmpeg is found and usable, or null if not found.
     *
     * @return VideoFrameExtractor|null Instance if FFmpeg is detected and validated, or null on failure.
     */
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
    
    /**
     * Attempts to locate the FFmpeg executable by checking a list of common installation paths.
     *
     * If a valid and executable FFmpeg binary is found in any of the predefined locations, the path is persisted to settings and validated. Returns a `VideoFrameExtractor` instance if successful, or `null` if FFmpeg is not found in these paths.
     *
     * @param \Omeka\Settings\Settings $settings Application settings used to persist the detected FFmpeg path.
     * @return ?VideoFrameExtractor Instance if FFmpeg is found and validated, or null otherwise.
     */
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
     * Validates the specified FFmpeg executable and returns a VideoFrameExtractor instance if successful.
     *
     * Runs `ffmpeg -version` with a timeout to ensure the executable is valid and responsive.
     *
     * @param string $ffmpegPath Path to the FFmpeg executable.
     * @param int $timeout Maximum time in seconds to wait for validation.
     * @return VideoFrameExtractor|null Instance if validation succeeds, or null if validation fails.
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
    
    /****
     * Executes a shell command with a specified timeout and returns its output.
     *
     * Runs the given command using `proc_open`, enforcing the timeout by terminating the process if exceeded.
     * Returns an array of output lines on success, or null if the command fails, times out, or returns a non-zero exit code.
     *
     * @param string $command The shell command to execute.
     * @param int $timeout Timeout in seconds before forcibly terminating the process.
     * @return array|null Output lines from the command, or null on failure or timeout.
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
