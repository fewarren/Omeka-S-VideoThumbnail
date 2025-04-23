<?php
namespace VideoThumbnail\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use VideoThumbnail\Stdlib\VideoFrameExtractor;
use VideoThumbnail\Stdlib\Debug;

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
        Debug::log('ExtractVideoFrames controller plugin initialized', __METHOD__);
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
        Debug::log("Controller plugin extracting frame at position {$position}s from: " . basename($videoPath), __METHOD__);
        
        try {
            $result = $this->videoFrameExtractor->extractFrame($videoPath, $position);
            
            if ($result) {
                Debug::log("Frame extraction successful via controller plugin", __METHOD__);
            } else {
                Debug::logWarning("Frame extraction failed via controller plugin", __METHOD__);
            }
            
            return $result;
        } catch (\Exception $e) {
            Debug::logError("Exception in extractFrame: " . $e->getMessage(), __METHOD__, $e);
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
        Debug::log("Controller plugin extracting {$count} frames from: " . basename($videoPath), __METHOD__);
        
        try {
            $frames = $this->videoFrameExtractor->extractFrames($videoPath, $count);
            
            Debug::log("Extracted " . count($frames) . " frames via controller plugin", __METHOD__);
            return $frames;
        } catch (\Exception $e) {
            Debug::logError("Exception in extractFrames: " . $e->getMessage(), __METHOD__, $e);
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
        Debug::log("Controller plugin getting duration for: " . basename($videoPath), __METHOD__);
        
        try {
            $duration = $this->videoFrameExtractor->getVideoDuration($videoPath);
            
            if ($duration > 0) {
                Debug::log("Video duration detected: {$duration}s", __METHOD__);
            } else {
                Debug::logWarning("Failed to detect video duration", __METHOD__);
            }
            
            return $duration;
        } catch (\Exception $e) {
            Debug::logError("Exception in getVideoDuration: " . $e->getMessage(), __METHOD__, $e);
            return 0;
        }
    }
}
