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
     * Attaches the media ingest event handler to the event manager for the 'api.create.post' event.
     *
     * @param EventManagerInterface $events The event manager to attach to.
     * @param int $priority The priority for the event listener.
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
     * Handles the media ingest event after media creation, processing only video media types.
     *
     * If the event does not contain a valid MediaRepresentation or the media is not a video, the method returns without further action.
     *
     * @param Event $event The event triggered after media creation.
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