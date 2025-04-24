<?php
namespace VideoThumbnail\Listener;

use Laminas\EventManager\Event;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\AbstractListenerAggregate;
use Omeka\Api\Representation\MediaRepresentation;
use VideoThumbnail\Stdlib\Debug;

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
        Debug::log('Attaching MediaIngestListener to event manager', __METHOD__);
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
        Debug::log('MediaIngestListener handleIngest triggered', __METHOD__);
        
        $response = $event->getParam('response');
        if (!$response) {
            Debug::logWarning('No response object in event', __METHOD__);
            return;
        }

        $media = $response->getContent();
        if (!$media instanceof MediaRepresentation) {
            Debug::logWarning('Response content is not a MediaRepresentation', __METHOD__);
            return;
        }

        $mediaId = $media->id();
        $mediaType = $media->mediaType();
        
        Debug::log("Processing media ID: {$mediaId}, type: {$mediaType}", __METHOD__);

        // Only process video media types
        if (!$mediaType || strpos($mediaType, 'video/') !== 0) {
            Debug::log("Skipping media ID: {$mediaId} - not a video type", __METHOD__);
            return;
        }

        // Log that we're processing a video
        Debug::log("Processing video media ID: {$mediaId}, type: {$mediaType}", __METHOD__);
        
        // Additional processing can be added here
    }
}