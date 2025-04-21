<?php
namespace VideoThumbnail\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\MediaRepresentation;

class VideoThumbnailSelector extends AbstractHelper
{
    protected $frameCache = [];
    protected $tempFiles = [];
    protected $videoFrameExtractor;
    protected $settings;

    public function __construct($videoFrameExtractor, $settings)
    {
        $this->videoFrameExtractor = $videoFrameExtractor;
        $this->settings = $settings;
        // Remove or comment out register_shutdown_function to prevent possible hangs
        // register_shutdown_function([$this, 'cleanup']);
    }

    public function __invoke($media, $options = [])
    {
        try {
            if (!$this->validateMedia($media)) {
                return '';
            }

            $options = $this->normalizeOptions($options);
            
            // Generate frame previews
            $frames = $this->generateFramePreviews($media, $options);
            
            if (empty($frames)) {
                throw new \RuntimeException('Failed to generate frame previews');
            }

            // Render the selector
            return $this->renderSelector($media, $frames, $options);

        } catch (\Exception $e) {
            error_log(sprintf(
                'VideoThumbnailSelector error for media %d: %s',
                $media->id(),
                $e->getMessage()
            ));

            return $this->renderError($e->getMessage());
        }
    }

    protected function validateMedia($media)
    {
        if (!$media) {
            return false;
        }

        $mediaType = $media->mediaType();
        if (!$mediaType || strpos($mediaType, 'video/') !== 0) {
            return false;
        }

        return true;
    }

    protected function normalizeOptions(array $options)
    {
        return array_merge([
            'frameCount' => $this->settings->get('videothumbnail_frames_count', 5),
            'width' => 160,
            'height' => 90,
            'showTimestamps' => true,
            'showSelectionButton' => true
        ], $options);
    }

    protected function generateFramePreviews($media, array $options)
    {
        $mediaId = $media->id();
        
        // Check cache first
        if (isset($this->frameCache[$mediaId])) {
            return $this->frameCache[$mediaId];
        }

        // Get local file path
        $filePath = $this->getLocalFilePath($media);
        if (!$filePath) {
            throw new \RuntimeException('Could not get local video file path');
        }

        // Extract frames
        $frames = $this->extractFrames($filePath, $count);
        
        // Process and cache frames
        $processed = $this->processFrames($frames, $media);
        $this->frameCache[$mediaId] = $processed;
        
        return $processed;
    }

    protected function getLocalFilePath($media)
    {
        $filePath = sprintf('original/%s', $media->filename());
        return OMEKA_PATH . '/files/' . $filePath;
    }

    protected function extractFrames($filePath, $count)
    {
        $frames = $this->videoFrameExtractor->extractFrames($filePath, $count);
        
        if (empty($frames)) {
            throw new \RuntimeException('Failed to extract video frames');
        }

        // Track temp files for cleanup
        foreach ($frames as $frame) {
            $this->tempFiles[] = $frame;
        }

        return $frames;
    }

    protected function processFrames(array $frames, $media)
    {
        $processed = [];
        $duration = $this->videoFrameExtractor->getVideoDuration($this->getLocalFilePath($media));

        foreach ($frames as $index => $framePath) {
            // Calculate timestamp
            $position = ($index + 1) / (count($frames) + 1);
            $timestamp = $duration * $position;

            $processed[] = [
                'path' => $framePath,
                'url' => $this->getFrameUrl($framePath),
                'timestamp' => $timestamp,
                'position' => $position * 100 // as percentage
            ];
        }

        return $processed;
    }

    protected function getFrameUrl($framePath)
    {
        // Create a URL-accessible path for the frame
        $filename = basename($framePath);
        $publicPath = sprintf('video-thumbnails/%s', $filename);
        $targetPath = OMEKA_PATH . '/files/temp/' . $publicPath;

        // Ensure directory exists
        $dir = dirname($targetPath);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        // Copy frame to public location
        copy($framePath, $targetPath);
        $this->tempFiles[] = $targetPath;

        return $this->getView()->assetUrl('temp/' . $publicPath);
    }

    protected function renderSelector($media, array $frames, array $options)
    {
        $view = $this->getView();
        
        return $view->partial('common/video-thumbnail-selector', [
            'media' => $media,
            'frames' => $frames,
            'options' => $options
        ]);
    }

    protected function renderError($message)
    {
        return sprintf(
            '<div class="video-thumbnail-error">%s</div>',
            $this->getView()->escapeHtml($message)
        );
    }

    public function cleanup()
    {
        // Clean up temporary files
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        
        // Clear frame cache
        $this->frameCache = [];
        $this->tempFiles = [];
    }
}
