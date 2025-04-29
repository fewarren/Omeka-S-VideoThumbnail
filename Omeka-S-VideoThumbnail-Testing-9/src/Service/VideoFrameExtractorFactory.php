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
        // Use a try-finally to ensure memory is cleaned up
        try {
            // Get required services with fallbacks but no circular dependencies
            $ffmpegPath = '';
            $logger = null;

            // Get settings first since it's required
            if (!$container->has('Omeka\Settings')) {
                error_log('VideoThumbnail: Settings service not found, using defaults');
            } else {
                try {
                    $settings = $container->get('Omeka\Settings');
                    $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '');
                } catch (\Exception $e) {
                    error_log('VideoThumbnail: Error getting settings: ' . $e->getMessage());
                }
            }

            // Get logger if available but don't fail if not
            if ($container->has('Omeka\Logger')) {
                try {
                    $logger = $container->get('Omeka\Logger');
                } catch (\Exception $e) {
                    error_log('VideoThumbnail: Error getting logger: ' . $e->getMessage());
                }
            }

            // Create extractor with minimal dependencies
            return new VideoFrameExtractor($ffmpegPath, $logger);

        } catch (\Exception $e) {
            error_log('VideoThumbnail: Critical error in VideoFrameExtractorFactory: ' . $e->getMessage());
            // Return bare minimum instance to prevent complete failure
            return new VideoFrameExtractor('', null);
        } finally {
            // Force garbage collection
            if (gc_enabled()) {
                gc_collect_cycles();
            }
        }
    }
}
