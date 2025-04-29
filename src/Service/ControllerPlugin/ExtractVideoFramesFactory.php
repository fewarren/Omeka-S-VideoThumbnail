<?php
namespace VideoThumbnail\Service\ControllerPlugin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Controller\Plugin\ExtractVideoFrames;

class ExtractVideoFramesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Get the PSR logger if available
        $logger = null;
        if ($services->has('VideoThumbnail\Logger')) {
            try {
                $logger = $services->get('VideoThumbnail\Logger');
            } catch (\Exception $e) {
                // Fall back to null logger
            }
        }
        
        // Attempt to retrieve VideoFrameExtractor from the service container
        try {
            if ($services->has('VideoThumbnail\Stdlib\VideoFrameExtractor')) {
                $videoFrameExtractor = $services->get('VideoThumbnail\Stdlib\VideoFrameExtractor');
            } else {
                // Fallback to direct instantiation if not registered
                $settings = $services->get('Omeka\Settings');
                $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
                $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath, $logger);
            }
        } catch (\Exception $e) {
            // Last-resort fallback with null dependency
            if ($logger) {
                $logger->error('VideoThumbnail: Error creating VideoFrameExtractor: ' . $e->getMessage());
            }
            $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor('');
        }
        
        // Create plugin with logger dependency
        return new ExtractVideoFrames($videoFrameExtractor, $logger);
    }
}
