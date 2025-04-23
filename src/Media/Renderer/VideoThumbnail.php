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
     * @param MediaRepresentation $media
     * @param array $options
     * @return string
     */
    public function render(MediaRepresentation $media, array $options = [])
    {
        try {
            \VideoThumbnail\Stdlib\Debug::log(sprintf(
                'Rendering video thumbnail for media %d with options: %s',
                $media->id(),
                json_encode($options)
            ), __METHOD__);

            $view = $this->getView();
            
            // Get the HTML
            $html = parent::render($media, $options);
            
            // Add our custom selector if we're in admin view
            if ($view->status()->isAdminRequest()) {
                $html .= $view->videoThumbnailSelector($media);
            }
            
            return $html;
            
        } catch (\Exception $e) {
            \VideoThumbnail\Stdlib\Debug::logError('Error rendering video thumbnail: ' . $e->getMessage(), __METHOD__, $e);
            return ''; // Return empty string on error
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
