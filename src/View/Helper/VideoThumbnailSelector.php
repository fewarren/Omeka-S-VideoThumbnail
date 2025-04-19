<?php
namespace VideoThumbnail\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\MediaRepresentation;

class VideoThumbnailSelector extends AbstractHelper
{
    /****
     * Renders a video thumbnail selector interface for supported video media types.
     *
     * Returns a partial view that allows users to select a thumbnail frame for the given media, if it is an MP4 or QuickTime video. The current thumbnail frame percentage is determined from the media's data or property values.
     *
     * @param MediaRepresentation $media The media object to render the thumbnail selector for.
     * @return string The rendered HTML for the thumbnail selector, or an empty string if the media type is not supported.
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
