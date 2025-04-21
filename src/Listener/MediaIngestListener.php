<?php
namespace VideoThumbnail\Listener;

use Laminas\EventManager\Event;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Listener for media ingestion events.
 */
class MediaIngestListener
{
    /**
     * Handle a media ingest event.
     *
     * @param Event $event
     */
    public function handleIngest(Event $event)
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

        // The actual processing will be handled by the MediaUpdateListener
        // This is just a placeholder to satisfy the service manager
        error_log('MediaIngestListener triggered for media ID: ' . $media->id());
    }
}