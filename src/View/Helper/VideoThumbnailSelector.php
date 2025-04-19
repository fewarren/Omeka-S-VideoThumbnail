<?php
namespace VideoThumbnail\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\MediaRepresentation;

class VideoThumbnailSelector extends AbstractHelper
{
    /**
     * Render the video thumbnail selector
     *
     * @param MediaRepresentation $media
     * @return string
     */
    public function __invoke(MediaRepresentation $media)
    {
        $view = $this->getView();
        $assetUrl = $view->plugin('assetUrl');
        
        // Include assets
        $view->headLink()->appendStylesheet($assetUrl('css/video-thumbnail.css', 'VideoThumbnail'));
        $view->headScript()->appendFile($assetUrl('js/video-thumbnail.js', 'VideoThumbnail'));
        
        $mediaType = $media->mediaType();
        
        // Only render for supported video types
        if (!in_array($mediaType, ['video/mp4', 'video/quicktime'])) {
            return '';
        }
        
        // Get data from Omeka S MediaRepresentation
        // In Omeka S, we need to get data using jsonSerialize 
        $mediaData = $media->jsonSerialize();
        $currentFrame = null;
        
        // First check 'o:data' which is standard in Omeka S
        if (isset($mediaData['o:data']) && is_array($mediaData['o:data'])) {
            if (isset($mediaData['o:data']['thumbnail_frame_percentage'])) {
                $currentFrame = $mediaData['o:data']['thumbnail_frame_percentage'];
            } elseif (isset($mediaData['o:data']['videothumbnail_frame_percentage'])) {
                $currentFrame = $mediaData['o:data']['videothumbnail_frame_percentage'];
            }
        }
        
        // Fallback if not found in 'o:data' - try direct properties
        if ($currentFrame === null) {
            if (method_exists($media, 'value') && $media->value('thumbnail_frame_percentage')) {
                $currentFrame = $media->value('thumbnail_frame_percentage');
            } elseif (method_exists($media, 'value') && $media->value('videothumbnail_frame_percentage')) {
                $currentFrame = $media->value('videothumbnail_frame_percentage');
            }
        }
        
        return $view->partial(
            'common/video-thumbnail-selector',
            [
                'media' => $media,
                'currentFrame' => $currentFrame,
            ]
        );
    }
}
