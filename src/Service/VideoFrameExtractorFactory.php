<?php
namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Stdlib\VideoFrameExtractor;
use VideoThumbnail\Stdlib\Debug;
use RuntimeException;

class VideoFrameExtractorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $settings = $container->get('Omeka\Settings');
        $config = $container->get('Config');
        $moduleSettings = $settings->get('videothumbnail', []); // Get module specific settings

        $ffmpegPath = $moduleSettings['ffmpeg_path'] ?? ''; // Get path from settings
        $tempDir = $config['videothumbnail']['temp_dir'] ?? OMEKA_PATH . '/files/tmp'; // Get temp dir from config or default

        // --- Start Enhanced Validation ---
        Debug::logEntry('VideoFrameExtractorFactory: Validating FFmpeg path.', ['configured_path' => $ffmpegPath]);

        if (empty($ffmpegPath)) {
            Debug::logWarning('VideoFrameExtractorFactory: FFmpeg path is not configured in module settings.');
            // Decide: Throw exception or allow creation and let extractor fail later?
            // For now, allow creation but log warning. Extractor's validateFFmpeg will handle it.
        } else {
            // Basic check if it looks like a path before hitting filesystem
            if (strlen($ffmpegPath) > 0) {
                // Check for Windows .exe specifically if on Windows
                $effectivePath = $ffmpegPath;
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && strtolower(substr($effectivePath, -4)) !== '.exe') {
                    if (file_exists($effectivePath . '.exe')) {
                         $effectivePath .= '.exe';
                         Debug::logEntry('VideoFrameExtractorFactory: Appended .exe for Windows.', ['path' => $effectivePath]);
                    }
                }

                Debug::logEntry('VideoFrameExtractorFactory: Checking filesystem for FFmpeg.', ['path_to_check' => $effectivePath]);
                if (!file_exists($effectivePath)) {
                    Debug::logWarning('VideoFrameExtractorFactory: FFmpeg path does not exist.', ['path' => $effectivePath]);
                    // Allow creation, let extractor handle final validation
                } elseif (!is_executable($effectivePath)) {
                    Debug::logWarning('VideoFrameExtractorFactory: FFmpeg path exists but is not executable.', ['path' => $effectivePath]);
                    // Allow creation, let extractor handle final validation
                } else {
                     Debug::logEntry('VideoFrameExtractorFactory: FFmpeg path appears valid and executable.', ['path' => $effectivePath]);
                }
            } else {
                 Debug::logWarning('VideoFrameExtractorFactory: Configured FFmpeg path is empty string after trim.');
            }
        }
        // --- End Enhanced Validation ---


        // Ensure the temp directory exists and is writable
        if (!is_dir($tempDir)) {
            if (!@mkdir($tempDir, 0777, true)) {
                 Debug::logError('VideoFrameExtractorFactory: Failed to create temp directory.', ['path' => $tempDir]);
                 throw new \RuntimeException("Failed to create temporary directory: $tempDir");
            }
        } elseif (!is_writable($tempDir)) {
             Debug::logError('VideoFrameExtractorFactory: Temp directory is not writable.', ['path' => $tempDir]);
             throw new \RuntimeException("Temporary directory is not writable: $tempDir");
        }


        // Pass the potentially unverified path; the extractor itself does a final check before execution.
        return new VideoFrameExtractor($ffmpegPath, $tempDir);
    }
}
