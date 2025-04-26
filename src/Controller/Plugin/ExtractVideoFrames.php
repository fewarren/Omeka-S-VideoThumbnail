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
     * Initializes the ExtractVideoFrames plugin with a VideoFrameExtractor instance.
     *
     * @param VideoFrameExtractor $videoFrameExtractor Instance used for extracting video frames and retrieving video duration.
     */
    public function __construct(VideoFrameExtractor $videoFrameExtractor)
    {
        $this->videoFrameExtractor = $videoFrameExtractor;
        Debug::log('ExtractVideoFrames controller plugin initialized', __METHOD__);
    }
    
    /**
     * Extracts a single frame from the specified video at the given position in seconds.
     *
     * @param string $videoPath Path to the video file.
     * @param float $position Position in seconds from which to extract the frame.
     * @return string|null Path to the extracted frame image, or null if extraction fails.
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
     * Extracts multiple frames evenly distributed across a video's duration.
     *
     * @param string $videoPath Path to the video file.
     * @param int $count Number of frames to extract.
     * @return array Array of file paths to the extracted frame images. Returns an empty array if extraction fails.
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
     * Returns the duration of the specified video in seconds.
     *
     * @param string $videoPath Path to the video file.
     * @return float Duration of the video in seconds, or 0 if retrieval fails.
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
