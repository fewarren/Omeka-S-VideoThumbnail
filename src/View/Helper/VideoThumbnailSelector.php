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

    /**
     * Initializes the VideoThumbnailSelector with a video frame extractor and configuration settings.
     *
     * @param mixed $videoFrameExtractor Service used to extract frames from video files.
     * @param mixed $settings Configuration settings for thumbnail selection.
     */
    public function __construct($videoFrameExtractor, $settings)
    {
        $this->videoFrameExtractor = $videoFrameExtractor;
        $this->settings = $settings;
        // Remove or comment out register_shutdown_function to prevent possible hangs
        // register_shutdown_function([$this, 'cleanup']);
    }

    /**
     * Renders the video thumbnail selector partial view for the given media.
     *
     * Returns an empty string if no media is provided or if rendering fails.
     *
     * @param mixed $media The media object to render the thumbnail selector for.
     * @return string The rendered HTML for the thumbnail selector, or an empty string on error.
     */
    public function __invoke($media)
    {
        if (!$media) {
            \VideoThumbnail\Stdlib\Debug::logError('No media provided to VideoThumbnailSelector', __METHOD__);
            return '';
        }

        try {
            \VideoThumbnail\Stdlib\Debug::log(sprintf(
                'Rendering thumbnail selector for media %d',
                $media->id()
            ), __METHOD__);

            $view = $this->getView();
            return $view->partial('video-thumbnail/common/video-thumbnail-selector', [
                'media' => $media
            ]);
        } catch (\Exception $e) {
            \VideoThumbnail\Stdlib\Debug::logError(
                'Error rendering thumbnail selector: ' . $e->getMessage(),
                __METHOD__
            );
            return '';
        }
    }

    /**
     * Determines if the provided media object is a valid video.
     *
     * Returns true if the media exists and its MIME type starts with 'video/', false otherwise.
     *
     * @param mixed $media The media object to validate.
     * @return bool True if the media is a video, false otherwise.
     */
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

    /**
     * Merges provided options with default settings for video thumbnail selection.
     *
     * @param array $options User-supplied options to override defaults.
     * @return array Combined options array with defaults applied where not specified.
     */
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

    /**
     * Generates and caches preview frames for a given video media item.
     *
     * Extracts a specified number of frames from the local video file, processes them with metadata, and stores the result in an internal cache keyed by media ID. Throws a RuntimeException if the local file path cannot be determined.
     *
     * @param mixed $media The video media object to extract frames from.
     * @param array $options Options array, must include 'frameCount' specifying the number of frames to extract.
     * @return array Array of processed frame data including file paths, URLs, timestamps, and positions.
     * @throws \RuntimeException If the local video file path cannot be determined.
     */
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

        // Extract frames - use frameCount from options
        $frames = $this->extractFrames($filePath, $options['frameCount']);
        
        // Process and cache frames
        $processed = $this->processFrames($frames, $media);
        $this->frameCache[$mediaId] = $processed;
        
        return $processed;
    }

    /**
     * Resolves the local file system path for the given media object.
     *
     * Attempts to retrieve the file path using the view's API adapter if available; falls back to constructing the path directly from the Omeka files directory if necessary.
     *
     * @param mixed $media Media object with a filename method.
     * @return string|false The resolved file path, or false if the filename is missing.
     */
    protected function getLocalFilePath($media)
    {
        // Get filename from the media
        $filename = $media->filename();
        if (empty($filename)) {
            return false;
        }

        // More robust path handling
        $storagePath = sprintf('original/%s', $filename);
        
        // Try to get the view helpers for proper file path resolution
        $view = $this->getView();
        
        try {
            // First try the modern approach
            if (method_exists($view, 'api') && $view->api()->hasAdapter('files')) {
                return $view->api()->read('files', $storagePath)->getContent();
            }
        } catch (\Exception $e) {
            // Silently continue to fallback method
        }
        
        // Fallback to direct file path
        return OMEKA_PATH . '/files/' . $storagePath;
    }

    /**
     * Extracts a specified number of frames from a video file.
     *
     * @param string $filePath Path to the video file.
     * @param int $count Number of frames to extract.
     * @return array Array of file paths to the extracted frames.
     * @throws \RuntimeException If no frames could be extracted from the video.
     */
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

    /**
     * Processes extracted video frame file paths into an array with URLs, timestamps, and positions.
     *
     * Calculates the timestamp and position for each frame based on the video's duration and the frame's order.
     *
     * @param array $frames Array of frame file paths.
     * @param mixed $media Media object representing the video.
     * @return array Array of processed frames, each containing 'path', 'url', 'timestamp', and 'position'.
     */
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

    /**
     * Copies a video frame to a public temporary directory and returns its accessible URL.
     *
     * @param string $framePath Path to the extracted video frame file.
     * @return string Public URL for accessing the frame image.
     */
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

    /**
     * Renders the video thumbnail selector partial view with the provided media, frames, and options.
     *
     * @param mixed $media The media object for which thumbnails are being selected.
     * @param array $frames Array of processed frame data to display as thumbnail options.
     * @param array $options Configuration options for rendering the selector.
     * @return string Rendered HTML for the video thumbnail selector.
     */
    protected function renderSelector($media, array $frames, array $options)
    {
        $view = $this->getView();
        
        return $view->partial('common/video-thumbnail-selector', [
            'media' => $media,
            'frames' => $frames,
            'options' => $options
        ]);
    }

    /**
     * Returns an HTML div displaying an escaped error message for video thumbnail operations.
     *
     * @param string $message The error message to display.
     * @return string HTML markup containing the escaped error message.
     */
    protected function renderError($message)
    {
        return sprintf(
            '<div class="video-thumbnail-error">%s</div>',
            $this->getView()->escapeHtml($message)
        );
    }

    /**
     * Removes all temporary files created during frame extraction and clears internal caches.
     */
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
