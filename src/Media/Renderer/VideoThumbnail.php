<?php
namespace VideoThumbnail\Media\Renderer;

use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Media\Renderer\RendererInterface;
use Laminas\View\Renderer\PhpRenderer;
use VideoThumbnail\Stdlib\Debug;

class VideoThumbnail implements RendererInterface
{
    protected $defaultOptions = [
        'width' => 640,
        'height' => 360,
        'controls' => true,
        'autoplay' => false,
        'loop' => false,
        'muted' => false,
        'preload' => 'metadata'
    ];

    protected $supportedFormats = [
        'video/mp4' => ['mp4'],
        'video/webm' => ['webm'],
        'video/ogg' => ['ogv'],
        'video/quicktime' => ['mov'],
        'video/x-msvideo' => ['avi'],
        'video/x-ms-wmv' => ['wmv'],
        'video/x-matroska' => ['mkv'],
        'video/3gpp' => ['3gp'],
        'video/3gpp2' => ['3g2'],
        'video/x-flv' => ['flv']
    ];

    /**
     * @var PhpRenderer
     */
    protected $view;

    /**
     * Sets the view renderer instance used for rendering video thumbnails.
     *
     * @param PhpRenderer $view The view renderer to use.
     */
    public function setView(PhpRenderer $view)
    {
        $this->view = $view;
    }

    /**
     * Returns the current view renderer instance.
     *
     * @return PhpRenderer The view renderer used for rendering views.
     */
    protected function getView()
    {
        return $this->view;
    }

    /**
     * Renders a video element with thumbnail support for the given media.
     *
     * Merges provided options with defaults, validates the media type, and constructs an HTML5 video tag with appropriate attributes and a source element. If the request is from an admin, appends a custom thumbnail selector. Returns an empty string if rendering fails or the media is unsupported.
     *
     * @param MediaRepresentation $media The media object to render as a video.
     * @param array $options Optional rendering options to override defaults.
     * @return string The rendered HTML for the video element, or an empty string on failure.
     */
    public function render(MediaRepresentation $media, array $options = [])
    {
        Debug::logEntry(__METHOD__, [
            'media_id' => $media->id(),
            'options' => $options
        ]);

        try {
            // Merge default options with provided options
            $options = array_merge($this->defaultOptions, $options);
            
            $view = $this->getView();
            if (!$view) {
                Debug::logError('View renderer not set', __METHOD__);
                return '';
            }
            
            $mediaType = $media->mediaType();
            
            if (!$this->isVideoMedia($mediaType)) {
                Debug::log("Unsupported media type: {$mediaType}", __METHOD__);
                return '';
            }
            
            // Get video URL
            $videoUrl = $this->getVideoUrl($media);
            if (!$videoUrl) {
                Debug::logError('Could not retrieve video URL', __METHOD__);
                return '';
            }
            
            // Build video element
            $attributes = $this->buildVideoAttributes($options);
            $attributeString = $this->formatAttributes($attributes);
            
            Debug::log('Rendering video with URL: ' . $videoUrl, __METHOD__);
            
            // Build the HTML
            $html = sprintf(
                '<video %s><source src="%s" type="%s">%s</video>',
                $attributeString,
                $videoUrl,
                $mediaType,
                $view->translate('Your browser does not support HTML5 video')
            );
            
            // Add our custom selector if we're in admin view
            if ($view->status() && $view->status()->isAdminRequest()) {
                Debug::log('Adding thumbnail selector for admin view', __METHOD__);
                $html .= $view->videoThumbnailSelector($media);
            }
            
            Debug::logExit(__METHOD__, ['html_length' => strlen($html)]);
            return $html;
            
        } catch (\Exception $e) {
            Debug::logError('Error rendering video thumbnail: ' . $e->getMessage(), __METHOD__, $e);
            return ''; // Return empty string on error
        }
    }

    /**
     * Determines whether the given media type is a supported video format.
     *
     * @param string $mediaType The MIME type of the media.
     * @return bool True if the media type is supported; otherwise, false.
     */
    protected function isVideoMedia($mediaType)
    {
        return isset($this->supportedFormats[$mediaType]);
    }

    /**
     * Retrieves the URL of the video file for the given media.
     *
     * Attempts to obtain the asset URL; if unavailable, falls back to the original URL.
     *
     * @param MediaRepresentation $media The media object to retrieve the video URL from.
     * @return string|null The video file URL, or null if not available.
     */
    protected function getVideoUrl(MediaRepresentation $media)
    {
        Debug::logEntry(__METHOD__, ['media_id' => $media->id()]);
        
        // First try the standard asset URL
        $assetUrl = $media->assetUrl();
        if ($assetUrl) {
            Debug::log('Using asset URL: ' . $assetUrl, __METHOD__);
            Debug::logExit(__METHOD__);
            return $assetUrl;
        }

        // Fallback to original URL if asset URL not available
        $originalUrl = $media->originalUrl();
        Debug::log('Using original URL: ' . $originalUrl, __METHOD__);
        Debug::logExit(__METHOD__);
        return $originalUrl;
    }

    /**
     * Constructs an array of HTML attributes for a video element based on the provided options.
     *
     * Includes width, height, preload, and CSS class attributes, as well as boolean attributes such as controls, autoplay, loop, and muted if specified.
     *
     * @param array $options Options for configuring the video element.
     * @return array Associative array of HTML attributes for the video tag.
     */
    protected function buildVideoAttributes(array $options)
    {
        $attributes = [
            'width' => $options['width'],
            'height' => $options['height'],
            'preload' => $options['preload'],
            'class' => 'video-js vjs-default-skin'
        ];

        // Add boolean attributes
        foreach (['controls', 'autoplay', 'loop', 'muted'] as $attr) {
            if (!empty($options[$attr])) {
                $attributes[$attr] = $attr;
            }
        }

        return $attributes;
    }

    /**
     * Converts an array of HTML attributes into a properly escaped attribute string.
     *
     * Boolean attributes are rendered without a value; all others are escaped and formatted as key="value".
     *
     * @param array $attributes Associative array of attribute names and values.
     * @return string Escaped HTML attribute string suitable for inclusion in a tag.
     */
    protected function formatAttributes(array $attributes)
    {
        $formatted = [];
        foreach ($attributes as $key => $value) {
            if ($value === $key) {
                // Boolean attribute
                $formatted[] = $key;
            } else {
                $formatted[] = sprintf('%s="%s"', $key, htmlspecialchars($value));
            }
        }
        return implode(' ', $formatted);
    }
}
