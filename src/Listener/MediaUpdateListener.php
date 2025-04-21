<?php
namespace VideoThumbnail\Listener;

use Laminas\EventManager\Event;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Listener for media update events.
 */
class MediaUpdateListener
{
    /**
     * Handle a media update event.
     *
     * @param Event $event
     */
    public function handleUpdate(Event $event)
    {
        $response = $event->getParam('response');
        if (!$response) {
            return;
        }

        $media = $response->getContent();
        if (!$media instanceof MediaRepresentation) {
            return;
        }

        // Only process video media types
        $mediaType = $media->mediaType();
        if (!$mediaType || strpos($mediaType, 'video/') !== 0) {
            return;
        }

        // Log that the listener was triggered
        error_log('MediaUpdateListener triggered for media ID: ' . $media->id());
        
        // In a full implementation, we would generate thumbnails here
        // But since this functionality is already handled in other parts
        // of the module, this is just a placeholder to satisfy the service manager
    }
}