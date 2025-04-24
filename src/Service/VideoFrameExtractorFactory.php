<?php
namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Stdlib\VideoFrameExtractor;

/**
 * Factory for VideoFrameExtractor
 */
class VideoFrameExtractorFactory implements FactoryInterface
{
    /**
     * Create an instance of the VideoFrameExtractor service
     *
     * @param ContainerInterface $container
     * @param string $requestedName
     * @param array|null $options
     * @return VideoFrameExtractor
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        // Get settings
        $settings = $container->get('Omeka\Settings');
        
        // Get FFmpeg path from settings or use default
        $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
        
        // Create temp directory
        $tempDir = null;
        if (defined('OMEKA_PATH')) {
            $tempDir = OMEKA_PATH . '/files/temp/video-thumbnails';
            
            // Ensure directory exists
            if (!file_exists($tempDir)) {
                @mkdir($tempDir, 0755, true);
            }
        }
        
        return new VideoFrameExtractor($ffmpegPath, $tempDir);
    }
}