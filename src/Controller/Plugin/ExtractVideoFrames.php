<?php
namespace VideoThumbnail\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use VideoThumbnail\Stdlib\VideoFrameExtractor;

class ExtractVideoFrames extends AbstractPlugin
{
    /**
     * @var VideoFrameExtractor
     */
    protected $videoFrameExtractor;
    
    /**
     * Constructor
     *
     * @param VideoFrameExtractor $videoFrameExtractor The video frame extractor instance used for frame operations.
     */
    public function __construct(VideoFrameExtractor $videoFrameExtractor)
    {
        $this->videoFrameExtractor = $videoFrameExtractor;
    }
    
    /**
     * Extract a single frame from a video.
     *
     * @param string $videoPath The path to the video file.
     * @param float $position The position in the video to extract the frame, measured in seconds.
     * @return string|null The path to the extracted frame image, or null if extraction fails.
     */
    public function extractFrame($videoPath, $position)
    {
        try {
            $result = $this->videoFrameExtractor->extractFrame($videoPath, $position);
            return $result;
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Exception in extractFrame: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Extract multiple frames from a video.
     *
     * @param string $videoPath The path to the video file.
     * @param int $count The number of frames to extract, distributed evenly across the video's duration.
     * @return array An array of paths to the extracted frame images.
     */
    public function extractFrames($videoPath, $count = 5)
    {
        try {
            $frames = $this->videoFrameExtractor->extractFrames($videoPath, $count);
            return $frames;
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Exception in extractFrames: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get the duration of a video.
     *
     * @param string $videoPath The path to the video file.
     * @return float The duration of the video in seconds.
     */
    public function getVideoDuration($videoPath)
    {
        try {
            $duration = $this->videoFrameExtractor->getVideoDuration($videoPath);
            return $duration;
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Exception in getVideoDuration: ' . $e->getMessage());
            return 0;
        }
    }
}
