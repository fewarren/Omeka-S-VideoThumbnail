<?php
namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Stdlib\VideoFrameExtractor;
use RuntimeException;
use Omeka\Settings\SettingsInterface; // Import SettingsInterface
use Laminas\Log\LoggerInterface; // Import LoggerInterface

class VideoFrameExtractorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        /** @var SettingsInterface $settings */
        $settings = $container->get('Omeka\Settings');
        /** @var array $config */
        $config = $container->get('Config');
        /** @var LoggerInterface $logger */
        $logger = $container->get('Omeka\Logger');

        // Get module specific settings or defaults from main config
        $moduleSettings = $config['videothumbnail'] ?? [];
        $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', $moduleSettings['settings']['ffmpeg_path'] ?? '');
        $tempDir = $moduleSettings['job_dispatch']['temp_dir'] ?? OMEKA_PATH . '/files/temp/video-thumbnails'; // Use a dedicated temp dir

        $logger->debug('VideoFrameExtractorFactory: Using FFmpeg path: ' . ($ffmpegPath ?: '(empty)'));
        $logger->debug('VideoFrameExtractorFactory: Using temp directory: ' . $tempDir);

        // --- Remove direct FFmpeg validation from factory ---
        // Validation will be handled within VideoFrameExtractor itself.

        // Ensure the temp directory exists and is writable
        try {
            if (!is_dir($tempDir)) {
                if (!@mkdir($tempDir, 0755, true)) { // Use 0755 permission
                    $error = error_get_last();
                    $message = sprintf('VideoFrameExtractorFactory: Failed to create temp directory "%s". Error: %s', $tempDir, $error['message'] ?? 'Unknown error');
                    $logger->err($message);
                    throw new RuntimeException($message);
                }
                $logger->info(sprintf('VideoFrameExtractorFactory: Created temp directory: %s', $tempDir));
            }
            if (!is_writable($tempDir)) {
                $message = sprintf('VideoFrameExtractorFactory: Temp directory is not writable: %s', $tempDir);
                $logger->err($message);
                throw new RuntimeException($message);
            }
        } catch (\Exception $e) {
            // Log any other exception during directory handling
            $logger->err('VideoFrameExtractorFactory: Error ensuring temp directory: ' . $e->getMessage());
            throw $e; // Re-throw the exception
        }

        // Pass the potentially unverified path; the extractor itself should validate before execution.
        $logger->debug('VideoFrameExtractorFactory: Instantiating VideoFrameExtractor.');
        return new VideoFrameExtractor($ffmpegPath, $tempDir, $logger);
    }
}
