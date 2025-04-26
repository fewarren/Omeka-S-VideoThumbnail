<?php

namespace VideoThumbnail\Job;

use Omeka\Job\AbstractJob;
use VideoThumbnail\Stdlib\Debug;
use Omeka\Entity\Media;

class ExtractFrames extends AbstractJob
{
    protected $mediaId;
    protected $videoFrameExtractor;
    protected $entityManager;
    protected $totalFrames;
    protected $processedFrames = 0;

    /**
     * Executes the job to extract video frames from a single media entity.
     *
     * Retrieves the target media, verifies its type and file availability, and uses the video frame extractor service to extract frames. Saves extracted frame metadata to the media entity and reports progress throughout the process. Throws a runtime exception if the media is not found or the file is unavailable.
     */
    public function perform()
    {
        Debug::logEntry(__METHOD__, ['job_id' => $this->job->getId()]);

        try {
            // Get services
            $services = $this->getServiceLocator();
            $entityManager = $services->get('Omeka\EntityManager');
            $api = $services->get('Omeka\ApiManager');
            $settings = $services->get('Omeka\Settings');
            Debug::log("Services retrieved.", __METHOD__);

            // Get video frame extractor
            $extractor = $services->get('VideoThumbnail\VideoFrameExtractor');
            $fileStore = $services->get('Omeka\File\Store');
            Debug::log("VideoFrameExtractor and FileStore retrieved.", __METHOD__);

            // Get supported formats
            $supportedFormats = $settings->get('videothumbnail_supported_formats', [
                'video/mp4', 'video/quicktime', 'video/x-msvideo',
                'video/x-ms-wmv', 'video/x-matroska', 'video/webm',
                'video/3gpp', 'video/3gpp2', 'video/x-flv'
            ]);

            Debug::log("Starting frame extraction job with supported formats: " . implode(', ', $supportedFormats), __METHOD__);

            // Initialize job properties
            $this->initializeJob();

            // Load media entity
            $media = $this->loadMedia();
            if (!$media) {
                Debug::log("Media entity not found for ID: " . $this->mediaId, __METHOD__, Debug::LEVEL_ERROR);
                throw new \Omeka\Job\Exception\RuntimeException("Media entity not found for ID: " . $this->mediaId);
            }
            Debug::log("Media entity loaded: " . $media->getId(), __METHOD__);

            // Check if media type is supported
            $mediaType = $media->getMediaType();
            Debug::log("Media type: " . $mediaType, __METHOD__);
            if (!in_array($mediaType, $supportedFormats)) {
                Debug::log("Media type '{$mediaType}' is not supported. Skipping frame extraction.", __METHOD__, Debug::LEVEL_WARN);
                return; // Skip unsupported media types
            }
            Debug::log("Media type is supported.", __METHOD__);

            // Get media file path
            $mediaPath = $fileStore->getLocalPath($media->getStorageId(), $media->getExtension());
            if (!$mediaPath || !file_exists($mediaPath)) {
                Debug::log("Media file path not found or invalid: " . ($mediaPath ?: 'null'), __METHOD__, Debug::LEVEL_ERROR);
                throw new \Omeka\Job\Exception\RuntimeException("Media file path not found or invalid for media ID: " . $media->getId());
            }
            Debug::log("Media file path: " . $mediaPath, __METHOD__);

            // Set callback for progress reporting
            $extractor->setFrameExtractedCallback([$this, 'onFrameExtracted']);

            // Extract frames
            Debug::log("Starting frame extraction process...", __METHOD__);
            $frames = $extractor->extractFrames($mediaPath);
            $this->totalFrames = $extractor->getTotalFrames(); // Get total frames after extraction
            Debug::log("Frame extraction process completed. Total frames found: " . $this->totalFrames, __METHOD__);

            // Save frame data
            if (!empty($frames)) {
                Debug::log("Saving extracted frame data...", __METHOD__);
                $this->saveFrameData($media, $frames);
                Debug::log("Frame data saved successfully.", __METHOD__);
            } else {
                Debug::log("No frames were extracted or saved.", __METHOD__, Debug::LEVEL_WARN);
            }

        } catch (\Exception $e) {
            Debug::log("Exception caught in ExtractFrames job: " . $e->getMessage(), __METHOD__, Debug::LEVEL_ERROR);
            Debug::log("Trace: \n" . $e->getTraceAsString(), __METHOD__, Debug::LEVEL_ERROR);
            // Re-throw the exception to ensure Omeka S handles the job failure correctly
            throw new \Omeka\Job\Exception\RuntimeException("Error during frame extraction: " . $e->getMessage(), 0, $e);
        }

        Debug::logExit(__METHOD__);
    }

    /**
     * Copies a video frame image to a temporary file and stores it as a thumbnail for the given media entity.
     *
     * Attempts to copy the specified frame image to a temporary location, generate and store thumbnails, and update the media entity's thumbnail status and metadata. Returns the temporary file object on success, or null if any step fails.
     *
     * @param Media $media The media entity to associate with the thumbnail.
     * @param string $framePath The file path to the extracted video frame image.
     * @return object|null The temporary file object if successful, or null on failure.
     */
    protected function storeThumbnail($media, $framePath)
    {
        Debug::logEntry(__METHOD__, ['media_id' => $media->getId()]);
        
        try {
            $services = $this->getServiceLocator();
            $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
            $tempFile = $tempFileFactory->build();
            
            if (!copy($framePath, $tempFile->getTempPath())) {
                Debug::logError("Failed to copy frame to temp location", __METHOD__);
                return null;
            }

            $tempFile->setStorageId($media->getStorageId());
            if (!$tempFile->storeThumbnails()) {
                Debug::logError("Failed to store thumbnails", __METHOD__);
                return null;
            }

            // Update media thumbnails flag and data
            $media->setHasThumbnails(true);
            $mediaData = $media->getData() ?: [];
            $mediaData['videothumbnail_frame_percentage'] = $this->args['frame_position'] ?? 50;
            $media->setData($mediaData);

            Debug::logExit(__METHOD__, ['success' => true]);
            return $tempFile;

        } catch (\Exception $e) {
            Debug::logError("Error storing thumbnail: " . $e->getMessage(), __METHOD__, $e);
            return null;
        }
    }

    /**
     * Initializes job properties and required services for frame extraction.
     *
     * Retrieves the media ID argument and obtains the video frame extractor and entity manager services.
     */
    protected function initializeJob()
    {
        Debug::logEntry(__METHOD__); // Add entry log
        $services = $this->getServiceLocator();
        
        $this->mediaId = $this->getArg('media_id');
        $this->videoFrameExtractor = $services->get('VideoThumbnail\VideoFrameExtractor');
        $this->entityManager = $services->get('Omeka\EntityManager');
        Debug::logExit(__METHOD__); // Add exit log
    }

    /**
     * Retrieves the media entity corresponding to the stored media ID.
     *
     * @return Media|null The media entity if found, or null if not found.
     */
    protected function loadMedia()
    {
        Debug::logEntry(__METHOD__, ['media_id' => $this->mediaId]); // Add entry log
        $media = $this->entityManager->find(Media::class, $this->mediaId);
        Debug::logExit(__METHOD__, ['media_found' => ($media !== null)]); // Add exit log
        return $media;
    }

    /**
     * Saves extracted video frame metadata to the media entity.
     *
     * Updates the media's data field with an array of frame information, including path, timestamp, and index, and persists the changes.
     *
     * @param Media $media The media entity to update.
     * @param array $frames Array of extracted frame metadata.
     */
    protected function saveFrameData(Media $media, array $frames)
    {
        Debug::logEntry(__METHOD__, ['media_id' => $media->getId(), 'frame_count' => count($frames)]); // Add entry log
        $frameData = [];
        foreach ($frames as $frame) {
            $frameData[] = [
                'path' => $frame['path'],
                'timestamp' => $frame['timestamp'],
                'index' => $frame['index']
            ];
        }

        // Store frame data in media
        $mediaData = $media->getData() ?: [];
        $mediaData['video_frames'] = $frameData;
        $media->setData($mediaData);
        
        $this->entityManager->persist($media);
        $this->entityManager->flush();
        Debug::logExit(__METHOD__); // Add exit log
    }

    /**
     * Callback invoked after each frame is extracted to update progress.
     *
     * Increments the processed frames counter and reports the current extraction progress.
     *
     * @param int $frameIndex Index of the frame that was just extracted.
     */
    public function onFrameExtracted($frameIndex)
    {
        Debug::logEntry(__METHOD__, ['frame_index' => $frameIndex]); // Add entry log
        $this->processedFrames++;
        $progress = ($this->totalFrames > 0) ? ($this->processedFrames / $this->totalFrames) * 100 : 0; // Avoid division by zero
        
        $this->reportProgress(
            $progress,
            sprintf('Extracted frame %d of %d', $this->processedFrames, $this->totalFrames)
        );
        Debug::logExit(__METHOD__); // Add exit log
    }

    /**
     * Updates the job's progress and status message if there is a significant change.
     *
     * Only persists changes if the progress percentage has changed by at least 1% or the status message differs from the current value.
     *
     * @param float $percent The current progress percentage.
     * @param string $message The status message to display.
     */
    protected function reportProgress($percent, $message)
    {
        $job = $this->job;
        $status = $job->getStatus();

        // Only update if status has changed or progress has changed significantly
        if ($status !== $message || abs($job->getData('progress', 0) - $percent) >= 1) {
            $job->setStatus($message);
            $job->setData('progress', $percent);
            
            $this->entityManager->persist($job);
            $this->entityManager->flush();

            Debug::log(sprintf(
                'Job %d progress: %d%% - %s',
                $job->getId(),
                $percent,
                $message
            ), __METHOD__);
        }
    }
}
