<?php
namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Stdlib\VideoFrameExtractor;
use RuntimeException;

class VideoFrameExtractorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        // Simplified factory with minimal error handling to prevent bootstrap hang
        try {
            // Get settings, but fallback to defaults if not found
            $settings = null;
            $logger = null;
            $ffmpegPath = '';
            
            // These service lookups are wrapped in try/catch to prevent blocking bootstrap
            try {
                if ($container->has('Omeka\Settings')) {
                    $settings = $container->get('Omeka\Settings');
                    $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '');
                }
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Could not get settings: ' . $e->getMessage());
            }
            
            try {
                if ($container->has('Omeka\Logger')) {
                    $logger = $container->get('Omeka\Logger');
                }
            } catch (\Exception $e) {
                // Fall through to default without logging
            }
            
            // Use a dedicated temp dir but with fallback
            $tempDir = defined('OMEKA_PATH') ? OMEKA_PATH . '/files/temp/video-thumbnails' : sys_get_temp_dir() . '/video-thumbnails';
            
            // Create extractor with minimal initialization
            return new VideoFrameExtractor($ffmpegPath, $tempDir, $logger);
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error creating VideoFrameExtractor: ' . $e->getMessage());
            
            // Create with fallback values to avoid breaking system
            return new VideoFrameExtractor('', null, null);
        }
    }
}
