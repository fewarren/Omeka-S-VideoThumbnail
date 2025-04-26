<?php
namespace VideoThumbnail\Listener;

use Omeka\Api\Representation\MediaRepresentation;
use VideoThumbnail\Stdlib\Debug;
use Laminas\EventManager\EventInterface;
use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;

class MediaUpdateListener extends AbstractListenerAggregate
{
    protected $listeners = [];
    protected $serviceLocator;
    protected $settings;

    /**
     * Initializes the MediaUpdateListener with the provided service locator and settings.
     *
     * Stores dependencies for later use in handling media update events.
     */
    public function __construct($serviceLocator, $settings)
    {
        $this->serviceLocator = $serviceLocator;
        $this->settings = $settings;
        Debug::log('MediaUpdateListener initialized', __METHOD__);
    }

    /**
     * Attaches event listeners for media update and creation events to trigger video thumbnail processing.
     *
     * @param EventManagerInterface $events The event manager to which listeners are attached.
     * @param int $priority Optional priority for the event listeners.
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        Debug::log('Attaching MediaUpdateListener events', __METHOD__);
        
        $this->listeners[] = $events->attach(
            'api.update.post',
            [$this, 'handleMediaUpdate']
        );

        $this->listeners[] = $events->attach(
            'api.create.post',
            [$this, 'handleMediaUpdate'],
            $priority
        );
    }

    /**
     * Handles media update or creation events to generate and update video thumbnails.
     *
     * Processes API events for media entities, and if the media is a video and a thumbnail frame position is specified,
     * extracts a video frame at the given position and updates the media's thumbnails accordingly.
     *
     * @param EventInterface $event The event containing request and response data for the media update or creation.
     */
    public function handleMediaUpdate(EventInterface $event)
    {
        Debug::logEntry(__METHOD__, ['event' => 'api.update.post']);
        
        try {
            $request = $event->getParam('request');
            $response = $event->getParam('response');
            $media = $response->getContent();

            if (!$media instanceof MediaRepresentation) {
                Debug::log('Not a media representation, skipping', __METHOD__);
                return;
            }

            Debug::log(sprintf(
                'Processing media update for ID %d, type %s',
                $media->id(),
                $media->mediaType()
            ), __METHOD__);

            // Check if this is a video media type
            if (strpos($media->mediaType(), 'video/') !== 0) {
                Debug::log('Not a video media type, skipping', __METHOD__);
                return;
            }

            // Check if we need to process thumbnails
            $data = $request->getContent();
            if (!isset($data['videothumbnail_frame'])) {
                Debug::log('No frame position specified, skipping', __METHOD__);
                return;
            }

            $position = $data['videothumbnail_frame'];
            Debug::log(sprintf(
                'Frame position specified: %d%%',
                $position
            ), __METHOD__);

            // Get file path
            $fileStore = $this->serviceLocator->get('Omeka\File\Store');
            $storagePath = sprintf('original/%s', $media->filename());
            $filePath = $fileStore->getLocalPath($storagePath);

            if (!file_exists($filePath)) {
                Debug::logError(sprintf(
                    'Video file not found: %s',
                    $filePath
                ), __METHOD__);
                return;
            }

            // Extract frame
            $extractor = $this->serviceLocator->get('VideoThumbnail\VideoFrameExtractor');
            $duration = $extractor->getVideoDuration($filePath);
            
            if ($duration <= 0) {
                Debug::logWarning('Could not determine video duration, using fallback', __METHOD__);
                $duration = 5.0;
            }

            $timeInSeconds = ($duration * $position) / 100;
            Debug::log(sprintf(
                'Extracting frame at %.2f seconds (position: %d%%)',
                $timeInSeconds,
                $position
            ), __METHOD__);

            $framePath = $extractor->extractFrame($filePath, $timeInSeconds);
            if (!$framePath) {
                Debug::logError('Frame extraction failed', __METHOD__);
                return;
            }

            Debug::log('Frame extracted successfully, updating thumbnails', __METHOD__);

            // Update thumbnails
            $this->updateThumbnails($media, $framePath, $position);

            Debug::logExit(__METHOD__, ['success' => true]);

        } catch (\Exception $e) {
            Debug::logError('Error in media update handler: ' . $e->getMessage(), __METHOD__, $e);
        }
    }

    /**
     * Updates the thumbnails for a video media entity using an extracted video frame.
     *
     * Copies the provided frame image to a temporary file, generates and stores thumbnails, updates the media entity's thumbnail status and frame position metadata, and performs cleanup.
     *
     * @param object $media The media representation to update.
     * @param string $framePath Path to the extracted video frame image.
     * @param float $position The frame position as a percentage of the video duration.
     */
    protected function updateThumbnails($media, $framePath, $position)
    {
        Debug::logEntry(__METHOD__, [
            'media_id' => $media->id(),
            'position' => $position
        ]);

        try {
            $tempFileFactory = $this->serviceLocator->get('Omeka\File\TempFileFactory');
            $tempFile = $tempFileFactory->build();
            
            if (!copy($framePath, $tempFile->getTempPath())) {
                Debug::logError('Failed to copy frame to temp location', __METHOD__);
                return;
            }

            // Get media entity
            $entityManager = $this->serviceLocator->get('Omeka\EntityManager');
            $mediaEntity = $entityManager->find('Omeka\Entity\Media', $media->id());
            
            if (!$mediaEntity) {
                Debug::logError('Media entity not found', __METHOD__);
                return;
            }

            // Store thumbnails
            $tempFile->setStorageId($mediaEntity->getStorageId());
            $hasThumbnails = $tempFile->storeThumbnails();
            
            Debug::log(sprintf(
                'Thumbnails stored: %s',
                $hasThumbnails ? 'true' : 'false'
            ), __METHOD__);

            if ($hasThumbnails) {
                $mediaEntity->setHasThumbnails(true);
                $mediaData = $mediaEntity->getData() ?: [];
                $mediaData['videothumbnail_frame_percentage'] = $position;
                $mediaEntity->setData($mediaData);
                
                $entityManager->persist($mediaEntity);
                $entityManager->flush();
                
                Debug::log('Media entity updated with new thumbnail data', __METHOD__);
            }

            // Cleanup
            $tempFile->delete();
            @unlink($framePath);
            
            Debug::logExit(__METHOD__, ['success' => true]);

        } catch (\Exception $e) {
            Debug::logError('Error updating thumbnails: ' . $e->getMessage(), __METHOD__, $e);
        }
    }
}