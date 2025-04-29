<?php
namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Stdlib\VideoFrameExtractor;
use Psr\Log\LoggerInterface;
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

            // Get PSR logger service if available
            if ($container->has('VideoThumbnail\Logger')) {
                try {
                    $logger = $container->get('VideoThumbnail\Logger');
                } catch (\Exception $e) {
                    // Fallback to Omeka logger if module logger is unavailable
                    if ($container->has('Omeka\Logger')) {
                        try {
                            $logger = $container->get('Omeka\Logger');
                        } catch (\Exception $e) {
                            // Will use NullLogger from trait
                        }
                    }
                }
            }

            // Get settings
            if ($container->has('Omeka\Settings')) {
                try {
                    $settings = $container->get('Omeka\Settings');
                    $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '');
                } catch (\Exception $e) {
                    if ($logger instanceof LoggerInterface) {
                        $logger->error('VideoThumbnail: Error getting settings: ' . $e->getMessage());
                    }
                }
            }

            // Create extractor with minimal dependencies
            $extractor = new VideoFrameExtractor($ffmpegPath, $logger);
            
            return $extractor;

        } catch (\Exception $e) {
            // Critical error - log and return minimal instance
            if (isset($logger) && $logger instanceof LoggerInterface) {
                $logger->critical('VideoThumbnail: Critical error in VideoFrameExtractorFactory: ' . $e->getMessage());
            }
            
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
