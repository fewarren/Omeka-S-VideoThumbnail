<?php
namespace VideoThumbnail\Media\Renderer;

use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Media\Renderer\RendererInterface;
use Laminas\View\Renderer\PhpRenderer;

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
     * Render a video with thumbnail support
     *
     * @param PhpRenderer $view
     * @param MediaRepresentation $media
     * @param array $options
     * @return string
     */
    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = [])
    {
        try {
            // Merge options with defaults
            $options = array_merge($this->defaultOptions, $options);
            
            // Validate media type
            $mediaType = $media->mediaType();
            if (!$this->isVideoMedia($mediaType)) {
                throw new \RuntimeException(sprintf(
                    'Unsupported media type: %s. Expected video format.',
                    $mediaType
                ));
            }

            // Get the source URL
            $sourceUrl = $this->getVideoUrl($media);
            if (!$sourceUrl) {
                throw new \RuntimeException('Could not determine video source URL');
            }

            // Build video attributes
            $attributes = $this->buildVideoAttributes($options);

            // Generate fallback message
            $fallback = $view->translate('Your browser does not support HTML5 video');

            // Build video element with source and fallback
            $html = sprintf(
                '<video %s><source src="%s" type="%s">%s</video>',
                $this->formatAttributes($attributes),
                $view->escapeHtml($sourceUrl),
                $view->escapeHtml($mediaType),
                $view->escapeHtml($fallback)
            );

            // Add thumbnail if available
            if ($media->hasThumbnails()) {
                $thumbnailUrl = $media->thumbnailUrl('large');
                if ($thumbnailUrl) {
                    $html = str_replace('<video', sprintf(
                        '<video poster="%s"',
                        $view->escapeHtml($thumbnailUrl)
                    ), $html);
                }
            }

            return $html;

        } catch (\Exception $e) {
            // Log error and return fallback message
            error_log(sprintf(
                'VideoThumbnail render error for media %d: %s',
                $media->id(),
                $e->getMessage()
            ));

            return sprintf(
                '<div class="video-error">%s</div>',
                $view->escapeHtml($view->translate('Error loading video'))
            );
        }
    }

    protected function isVideoMedia($mediaType)
    {
        return isset($this->supportedFormats[$mediaType]);
    }

    protected function getVideoUrl(MediaRepresentation $media)
    {
        // First try the standard asset URL
        $assetUrl = $media->assetUrl();
        if ($assetUrl) {
            return $assetUrl;
        }

        // Fallback to original URL if asset URL not available
        return $media->originalUrl();
    }

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

    protected function formatAttributes(array $attributes)
    {
        $formatted = [];
        foreach ($attributes as $key => $value) {
            if ($value === $key) {
                // Boolean attribute
                $formatted[] = $key;
            } else {
                $formatted[] = sprintf('%s="%s"', $key, $value);
            }
        }
        return implode(' ', $formatted);
    }
}
