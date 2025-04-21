<?php
namespace VideoThumbnail\Listener;

use Laminas\EventManager\Event;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\AbstractListenerAggregate;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Listener for media ingestion events.
 */
class MediaIngestListener extends AbstractListenerAggregate
{
    /**
     * {@inheritDoc}
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $this->listeners[] = $events->attach(
            'api.create.post',
            [$this, 'handleIngest'],
            $priority
        );
    }

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

        // Log that the listener was triggered
        error_log('MediaIngestListener triggered for media ID: ' . $media->id());
    }
}