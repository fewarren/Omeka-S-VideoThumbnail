<?php
namespace VideoThumbnail\Service\ViewHelper;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\View\Helper\VideoThumbnailSelector;

class VideoThumbnailSelectorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Get required dependencies for the view helper
        $settings = $services->get('Omeka\Settings');
        
        // Get video frame extractor from service container if available,
        // otherwise create it on-demand
        if ($services->has('VideoThumbnail\Stdlib\VideoFrameExtractor')) {
            $videoFrameExtractor = $services->get('VideoThumbnail\Stdlib\VideoFrameExtractor');
        } else {
            // Fallback to creating the extractor directly
            $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '');
            $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
        }

        return new VideoThumbnailSelector($videoFrameExtractor, $settings);
    }
}