<?php
namespace VideoThumbnail\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Psr\Log\LoggerInterface;
use VideoThumbnail\Stdlib\LoggerAwareTrait;
use VideoThumbnail\Stdlib\VideoFrameExtractor;

class ExtractVideoFrames extends AbstractPlugin
{
    use LoggerAwareTrait;
    
    /**
     * @var VideoFrameExtractor
     */
    protected $videoFrameExtractor;
    
    /**
     * Constructor
     *
     * @param VideoFrameExtractor $videoFrameExtractor The video frame extractor instance used for frame operations.
     * @param LoggerInterface|null $logger PSR-3 logger
     */
    public function __construct(VideoFrameExtractor $videoFrameExtractor, LoggerInterface $logger = null)
    {
        $this->videoFrameExtractor = $videoFrameExtractor;
        if ($logger) {
            $this->setLogger($logger);
        }
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
            // Validate videoPath
            if (empty($videoPath) || !is_string($videoPath)) {
                $this->log('error', 'Invalid video path provided');
                return null;
            }
            
            // Check if file exists and is readable
            if (!file_exists($videoPath)) {
                $this->log('error', 'Video file not found: ' . $videoPath);
                return null;
            }
            
            if (!is_readable($videoPath)) {
                $this->log('error', 'Video file not readable: ' . $videoPath);
                return null;
            }
            
            // Validate position
            if (!is_numeric($position)) {
                $this->log('error', 'Position must be a numeric value');
                return null;
            }
            
            // Ensure position is positive
            $position = max(0, (float)$position);
            
            // Call extractor with validated parameters
            $result = $this->videoFrameExtractor->extractFrame($videoPath, $position);
            return $result;
        } catch (\Exception $e) {
            $this->log('error', 'Exception in extractFrame: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
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
            // Validate videoPath
            if (empty($videoPath) || !is_string($videoPath)) {
                $this->log('error', 'Invalid video path provided');
                return [];
            }
            
            // Check if file exists and is readable
            if (!file_exists($videoPath)) {
                $this->log('error', 'Video file not found: ' . $videoPath);
                return [];
            }
            
            if (!is_readable($videoPath)) {
                $this->log('error', 'Video file not readable: ' . $videoPath);
                return [];
            }
            
            // Validate count
            if (!is_numeric($count) || (int)$count <= 0) {
                $this->log('warning', 'Count must be a positive integer, defaulting to 5', [
                    'provided_value' => $count
                ]);
                $count = 5; // Default to 5 if invalid
            }
            
            // Limit count to reasonable range to prevent overload
            $count = min(20, max(1, (int)$count));
            
            // Call extractor with validated parameters
            $frames = $this->videoFrameExtractor->extractFrames($videoPath, $count);
            return $frames;
        } catch (\Exception $e) {
            $this->log('error', 'Exception in extractFrames: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
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
            // Validate videoPath
            if (empty($videoPath) || !is_string($videoPath)) {
                $this->log('error', 'Invalid video path provided');
                return 0;
            }
            
            // Check if file exists and is readable
            if (!file_exists($videoPath)) {
                $this->log('error', 'Video file not found: ' . $videoPath);
                return 0;
            }
            
            if (!is_readable($videoPath)) {
                $this->log('error', 'Video file not readable: ' . $videoPath);
                return 0;
            }
            
            // Call extractor with validated parameters
            $duration = $this->videoFrameExtractor->getVideoDuration($videoPath);
            
            // Ensure we return a positive number
            return max(0, (float)$duration);
        } catch (\Exception $e) {
            $this->log('error', 'Exception in getVideoDuration: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return 0;
        }
    }
}
