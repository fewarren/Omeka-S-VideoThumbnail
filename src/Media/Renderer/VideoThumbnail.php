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
     * Set the view renderer
     * 
     * @param PhpRenderer $view
     */
    public function setView(PhpRenderer $view)
    {
        $this->view = $view;
    }

    /**
     * Get the view renderer
     *
     * @return PhpRenderer
     */
    protected function getView()
    {
        return $this->view;
    }

    /**
     * Render a video with thumbnail support
     *
     * @param MediaRepresentation $media
     * @param array $options
     * @return string
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
     * Check if media type is a supported video format
     *
     * @param string $mediaType
     * @return bool
     */
    protected function isVideoMedia($mediaType)
    {
        return isset($this->supportedFormats[$mediaType]);
    }

    /**
     * Get the URL for the video file
     *
     * @param MediaRepresentation $media
     * @return string|null
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
     * Build HTML attributes for the video element
     *
     * @param array $options
     * @return array
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
     * Format attributes into HTML attribute string
     *
     * @param array $attributes
     * @return string
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
