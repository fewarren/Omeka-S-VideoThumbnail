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
            $settings->set('videothumbnail_ffmpeg_path', '');
            $ffmpegPath = '';
        } elseif (!empty($ffmpegPath) && (!file_exists($ffmpegPath) || !is_executable($ffmpegPath))) {
            Debug::log('Invalid FFmpeg path, will auto-detect new path', __METHOD__);
            $settings->set('videothumbnail_ffmpeg_path', '');
            $ffmpegPath = '';
        } elseif (file_exists($ffmpegPath) && is_executable($ffmpegPath)) {
            Debug::log('FFmpeg exists and is executable at configured path', __METHOD__);
            return $this->validateFfmpegAndCreate($ffmpegPath);
        }

        Debug::log('Configured FFmpeg path is invalid, trying to auto-detect...', __METHOD__);
        return $this->autoDetectFfmpeg($settings);
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
        $output = [];
        $returnVar = null;
        exec('which ffmpeg 2>/dev/null', $output, $returnVar);
        
        if ($returnVar === 0 && !empty($output)) {
            $ffmpegPath = trim($output[0]);
            Debug::log('Found FFmpeg at: ' . $ffmpegPath, __METHOD__);
            $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
            return $this->validateFfmpegAndCreate($ffmpegPath);
        }
        
        Debug::log('FFmpeg not found using \'which\'', __METHOD__);
        return null;
    }
    
    private function detectUsingType($settings)
    {
        Debug::log('Detecting FFmpeg using \'type\' command', __METHOD__);
        $output = [];
        $returnVar = null;
        exec('type -p ffmpeg 2>/dev/null', $output, $returnVar);
        
        if ($returnVar === 0 && !empty($output)) {
            $ffmpegPath = trim($output[0]);
            Debug::log('Found FFmpeg at: ' . $ffmpegPath, __METHOD__);
            $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
            return $this->validateFfmpegAndCreate($ffmpegPath);
        }
        
        Debug::log('FFmpeg not found using \'type\'', __METHOD__);
        return null;
    }
    
    private function detectUsingCommandExists($settings)
    {
        Debug::log('Detecting FFmpeg using \'command -v\'', __METHOD__);
        $output = [];
        $returnVar = null;
        exec('command -v ffmpeg 2>/dev/null', $output, $returnVar);
        
        if ($returnVar === 0 && !empty($output)) {
            $ffmpegPath = trim($output[0]);
            Debug::log('Found FFmpeg at: ' . $ffmpegPath, __METHOD__);
            $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
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
                $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
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
                $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
                return $this->validateFfmpegAndCreate($ffmpegPath);
            }
        }
        
        Debug::log('FFmpeg not found in common paths', __METHOD__);
        return null;
    }

    protected function validateFfmpegAndCreate($ffmpegPath)
    {
        Debug::log('Validating FFmpeg at: ' . $ffmpegPath, __METHOD__);
        
        try {
            $output = [];
            $returnVar = null;
            $command = escapeshellcmd($ffmpegPath) . ' -version 2>/dev/null';
            exec($command, $output, $returnVar);
            
            if ($returnVar === 0 && !empty($output)) {
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
}
