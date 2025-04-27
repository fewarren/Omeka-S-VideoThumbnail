<?php
namespace VideoThumbnail\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Omeka\Stdlib\Message;
use VideoThumbnail\Form\ConfigBatchForm;
use VideoThumbnail\Stdlib\Debug;

class VideoThumbnailController extends AbstractActionController
{
    protected $entityManager;
    protected $fileManager;
    protected $settings;
    protected $serviceLocator;

    public function __construct($entityManager = null, $fileManager = null, $serviceLocator = null)
    {
        $this->entityManager = $entityManager;
        $this->fileManager = $fileManager;
        $this->serviceLocator = $serviceLocator;
        
        // Use basic error_log instead of Debug to avoid circular dependencies
        error_log('VideoThumbnail: Controller initialized');
    }

    public function setSettings($settings)
    {
        $this->settings = $settings;
        Debug::log('Settings service injected into controller', __METHOD__);
        return $this;
    }

    public function indexAction()
    {
        Debug::logEntry(__METHOD__);
        Debug::log('VideoThumbnailController::indexAction accessed', __METHOD__);
        Debug::traceCallStack(5, 'Admin settings page accessed');
        
        try {
            $form = new ConfigBatchForm();
            $form->init();

            $defaultSupportedFormats = [
                'video/mp4',         // MP4 files
                'video/webm',        // WebM files
                'video/quicktime',   // MOV files
                'video/x-msvideo',   // AVI files
                'video/x-ms-wmv',    // WMV files
                'video/x-matroska',  // MKV files
                'video/3gpp',        // 3GP files
                'video/3gpp2',        // 3G2 files
                'video/x-flv'         // FLV files
            ];
            
            // Load all settings with debugging
            Debug::log('Loading settings from database', __METHOD__);
            
            // Get supported formats setting with detailed debugging
            $storedFormats = null;
            try {
                $storedFormats = $this->settings->get('videothumbnail_supported_formats');
                Debug::log('Retrieved supported formats: ' . 
                    ($storedFormats ? json_encode($storedFormats) : 'null'), __METHOD__);
            } catch (\Exception $e) {
                Debug::logError('Failed to get videothumbnail_supported_formats setting: ' . $e->getMessage(), __METHOD__, $e);
            }
            
            // Apply default if needed with detailed logging
            $supportedFormats = $storedFormats;
            if (!is_array($supportedFormats) || empty($supportedFormats)) {
                Debug::log('Using default formats instead of stored value', __METHOD__);
                $supportedFormats = $defaultSupportedFormats;
            }
            
        } catch (\Exception $e) {
            Debug::logError('Error initializing form: ' . $e->getMessage(), __METHOD__, $e);
            
            // Fallback to create basic form
            $form = new ConfigBatchForm();
            $form->init();
            $supportedFormats = ['video/mp4', 'video/quicktime'];
        }

        // Set debug mode value and other settings with error handling
        try {
            Debug::log('Getting additional settings from database', __METHOD__);
            
            // Get debug mode setting with detailed debugging
            $debugMode = false;
            try {
                $debugMode = (bool)$this->settings->get('videothumbnail_debug_mode', true); // Default to true
                Debug::log('Retrieved debug mode: ' . ($debugMode ? 'true' : 'false'), __METHOD__);
            } catch (\Exception $e) {
                Debug::logError('Failed to get videothumbnail_debug_mode setting: ' . $e->getMessage(), __METHOD__, $e);
                $debugMode = true; // Default if error
            }
            
            // Get default frame setting with detailed debugging
            $defaultFrame = 10;
            try {
                $defaultFrame = (int)$this->settings->get('videothumbnail_default_frame', 10);
                Debug::log('Retrieved default frame: ' . $defaultFrame, __METHOD__);
            } catch (\Exception $e) {
                Debug::logError('Failed to get videothumbnail_default_frame setting: ' . $e->getMessage(), __METHOD__, $e);
            }
            
            // Get timestamp property setting with detailed debugging
            $timestampProperty = null;
            try {
                $timestampProperty = $this->settings->get('videothumbnail_timestamp_property');
                Debug::log('Retrieved timestamp property: ' . ($timestampProperty ?? 'null'), __METHOD__);
            } catch (\Exception $e) {
                Debug::logError('Failed to get videothumbnail_timestamp_property setting: ' . $e->getMessage(), __METHOD__, $e);
            }
            
            // Prepare form data
            $formData = [
                'default_frame_position' => $defaultFrame,
                'supported_formats' => $supportedFormats,
                'debug_mode' => $debugMode,
            ];
            
            // Only add timestamp property if set
            if ($timestampProperty) {
                $formData['timestamp_property'] = $timestampProperty;
            }
            
            Debug::dumpFormData($formData, 'pre-populate', __METHOD__);
            $form->setData($formData);
        } catch (\Exception $e) {
            Debug::logError('Error setting form data: ' . $e->getMessage(), __METHOD__, $e);
            
            // Use defaults if settings access fails
            $form->setData([
                'default_frame_position' => 10,
                'supported_formats' => $supportedFormats,
                'debug_mode' => true,
            ]);
        }

        $request = $this->getRequest();
        if ($request->isPost()) {
            Debug::log('Processing POST request in admin settings', __METHOD__);
            
            $postData = $request->getPost()->toArray();
            Debug::dumpFormData($postData, 'post-submission', __METHOD__);
            
            $form->setData($postData);
            if ($form->isValid()) {
                Debug::log('Form validation succeeded', __METHOD__);
                $formData = $form->getData();
                Debug::dumpFormData($formData, 'post-validation', __METHOD__);
                
                try {
                    // Get old values for debugging
                    $oldDefaultFrame = $this->settings->get('videothumbnail_default_frame', 10);
                    $oldSupportedFormats = $this->settings->get('videothumbnail_supported_formats', []);
                    $oldDebugMode = $this->settings->get('videothumbnail_debug_mode', false);
                    $oldTimestampProperty = $this->settings->get('videothumbnail_timestamp_property', null);
                    
                    // Save settings with logging of changes
                    $this->settings->set('videothumbnail_default_frame', $formData['default_frame_position']);
                    Debug::logSettingChange('videothumbnail_default_frame', $oldDefaultFrame, $formData['default_frame_position'], __METHOD__);
                    
                    $this->settings->set('videothumbnail_supported_formats', $formData['supported_formats']);
                    Debug::logSettingChange('videothumbnail_supported_formats', $oldSupportedFormats, $formData['supported_formats'], __METHOD__);
                    
                    $this->settings->set('videothumbnail_debug_mode', !empty($formData['debug_mode']));
                    Debug::logSettingChange('videothumbnail_debug_mode', $oldDebugMode, !empty($formData['debug_mode']), __METHOD__);
                    
                    // Only update timestamp property if it exists in form data
                    if (isset($formData['timestamp_property'])) {
                        $this->settings->set('videothumbnail_timestamp_property', $formData['timestamp_property']);
                        Debug::logSettingChange('videothumbnail_timestamp_property', $oldTimestampProperty, $formData['timestamp_property'], __METHOD__);
                    }
                    
                    Debug::logConfigAction('save_complete', ['formData' => $formData], __METHOD__);
                    $this->messenger()->addSuccess('Video thumbnail settings updated.'); // Success message
                } catch (\Exception $e) {
                    Debug::logError('Failed to save settings: ' . $e->getMessage(), __METHOD__, $e);
                    $this->messenger()->addError('Failed to save video thumbnail settings: ' . $e->getMessage()); // Error message
                }
            } else {
                Debug::log('Form validation failed', __METHOD__);
                $messages = $form->getMessages();
                Debug::logFormValidation($messages, __METHOD__);
                $this->messenger()->addError('There was an error during form validation. Please check the form and try again.'); // Form validation error
            }
        }

        try {
            $totalVideos = $this->getTotalVideos();
        } catch (\Exception $e) {
            \VideoThumbnail\Stdlib\Debug::logError('Error getting total videos: ' . $e->getMessage(), __METHOD__, $e);
            $totalVideos = 0;
        }
        
        $view = new ViewModel();
        $view->setVariable('form', $form);
        $view->setVariable('totalVideos', $totalVideos);
        $view->setVariable('supportedFormats', implode(', ', $supportedFormats));
        
        \VideoThumbnail\Stdlib\Debug::log('Rendering index view', __METHOD__);
        
        $view->setTemplate('video-thumbnail/admin/video-thumbnail/index');
        
        return $view;
    }

    protected function getTotalVideos()
    {
        \VideoThumbnail\Stdlib\Debug::logEntry(__METHOD__);
        
        try {
            // Check if entity manager is available
            if (!$this->entityManager) {
                \VideoThumbnail\Stdlib\Debug::logError('Entity manager is not available', __METHOD__);
                return 0;
            }
            
            // Get the repository
            $repository = $this->entityManager->getRepository('Omeka\Entity\Media');
            
            // Query for the total number of videos based on supported formats
            $defaultSupportedFormats = [
                'video/mp4',          // MP4 files
                'video/quicktime',    // MOV files
                'video/x-msvideo',    // AVI files
                'video/x-ms-wmv',     // WMV files
                'video/x-matroska',   // MKV files
                'video/webm',         // WebM files
                'video/3gpp',         // 3GP files
                'video/3gpp2',        // 3G2 files
                'video/x-flv'         // FLV files
            ];
            $supportedFormats = $this->settings ? 
                $this->settings->get('videothumbnail_supported_formats', $defaultSupportedFormats) : 
                $defaultSupportedFormats;
                
            if (!is_array($supportedFormats) || empty($supportedFormats)) {
                $supportedFormats = $defaultSupportedFormats;
            }
            
            $queryBuilder = $repository->createQueryBuilder('media');
            $queryBuilder->select('COUNT(media.id)')
                        ->where($queryBuilder->expr()->in('media.mediaType', ':formats'))
                        ->setParameter('formats', $supportedFormats);

            $result = (int) $queryBuilder->getQuery()->getSingleScalarResult();
            
            if ($this->settings) {
                Debug::logExit(__METHOD__, $result);
            }
            return $result;
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error in getTotalVideos: ' . $e->getMessage());
            if ($this->settings) {
                Debug::logError('Error in getTotalVideos: ' . $e->getMessage(), __METHOD__);
            }
            return 0;
        }
    }

    public function saveFrameAction()
    {
        Debug::logEntry(__METHOD__, ['request' => 'Starting frame save']);
        
        if (!$this->getRequest()->isPost()) {
            Debug::logWarning('Request is not POST method', __METHOD__);
            return $this->redirect()->toRoute('admin');
        }

        $mediaId = $this->params()->fromPost('media_id');
        $position = $this->params()->fromPost('position');
        
        Debug::log("Processing frame save - Media ID: {$mediaId}, Position: {$position}", __METHOD__);
        
        if (!$mediaId || !is_numeric($position)) {
            Debug::logError("Invalid parameters - Media ID: {$mediaId}, Position: {$position}", __METHOD__);
            return new JsonModel([
                'success' => false,
                'message' => 'Invalid parameters',
            ]);
        }
        
        try {
            $media = $this->api()->read('media', $mediaId)->getContent();
            Debug::log("Retrieved media: " . $media->filename(), __METHOD__);
            
            if (!$media) {
                Debug::logError("Media not found: {$mediaId}", __METHOD__);
                return new JsonModel([
                    'success' => false,
                    'message' => 'Media not found',
                ]);
            }
            
            // Extract and save the frame
            $result = $this->extractAndSaveFrame($media, $position);
            
            Debug::logExit(__METHOD__, ['success' => $result['success']]);
            return new JsonModel($result);
            
        } catch (\Exception $e) {
            Debug::logError('Frame save error: ' . $e->getMessage(), __METHOD__, $e);
            return new JsonModel([
                'success' => false,
                'message' => 'Error saving frame',
                'details' => $e->getMessage()
            ]);
        }
    }

    protected function extractAndSaveFrame($media, $position)
    {
        Debug::logEntry(__METHOD__, ['media_id' => $media->id(), 'position' => $position]);
        
        try {
            $fileStore = $this->serviceLocator->get('Omeka\File\Store');
            $extractor = $this->serviceLocator->get('VideoThumbnail\VideoFrameExtractor');
            
            $storagePath = sprintf('original/%s', $media->filename());
            $filePath = $fileStore->getLocalPath($storagePath);
            Debug::log("Video file path: {$filePath}", __METHOD__);
            
            // Get video duration
            $duration = $extractor->getVideoDuration($filePath);
            Debug::log("Video duration: {$duration} seconds", __METHOD__);
            
            if ($duration <= 0) {
                Debug::logWarning("Invalid duration, using fallback value", __METHOD__);
                $duration = 5.0;
            }
            
            // Calculate frame time
            $timeInSeconds = ($duration * $position) / 100;
            $timeInSeconds = max(0.1, min($timeInSeconds, $duration - 0.1));
            Debug::log("Calculated frame time: {$timeInSeconds}s", __METHOD__);
            
            // Extract the frame
            $framePath = $extractor->extractFrame($filePath, $timeInSeconds);
            if (!$framePath) {
                Debug::logError("Frame extraction failed", __METHOD__);
                return [
                    'success' => false,
                    'message' => 'Failed to extract frame'
                ];
            }
            
            Debug::log("Frame extracted to: {$framePath}", __METHOD__);
            
            // Store the frame
            $result = $this->storeThumbnail($media, $framePath, $position, $timeInSeconds);
            
            Debug::logExit(__METHOD__, ['success' => $result['success']]);
            return $result;
            
        } catch (\Exception $e) {
            Debug::logError('Frame extraction error: ' . $e->getMessage(), __METHOD__, $e);
            return [
                'success' => false,
                'message' => 'Error processing frame: ' . $e->getMessage()
            ];
        }
    }

    protected function storeThumbnail($media, $framePath, $position, $timeInSeconds)
    {
        Debug::logEntry(__METHOD__, ['media_id' => $media->id(), 'frame_path' => $framePath]);
        
        try {
            $tempFileFactory = $this->serviceLocator->get('Omeka\File\TempFileFactory');
            $tempFile = $tempFileFactory->build();
            
            if (!copy($framePath, $tempFile->getTempPath())) {
                Debug::logError("Failed to copy frame to temp location", __METHOD__);
                return [
                    'success' => false,
                    'message' => 'Failed to process frame'
                ];
            }
            
            Debug::log("Frame copied to temp location: " . $tempFile->getTempPath(), __METHOD__);
            
            // Get the media entity
            $mediaEntity = $this->entityManager->find('Omeka\Entity\Media', $media->id());
            if (!$mediaEntity) {
                Debug::logError("Media entity not found", __METHOD__);
                return [
                    'success' => false,
                    'message' => 'Media entity not found'
                ];
            }
            
            // Store thumbnails
            $tempFile->setStorageId($mediaEntity->getStorageId());
            $hasThumbnails = $tempFile->storeThumbnails();
            Debug::log("Thumbnails stored: " . ($hasThumbnails ? 'true' : 'false'), __METHOD__);
            
            // Update media data
            $mediaEntity->setHasThumbnails($hasThumbnails);
            $mediaData = $mediaEntity->getData() ?: [];
            $mediaData['videothumbnail_frame_percentage'] = $position;
            $mediaData['videothumbnail_frame_time'] = $timeInSeconds;
            $mediaEntity->setData($mediaData);
            
            // Save changes
            $this->entityManager->persist($mediaEntity);
            $this->entityManager->flush();
            
            // Cleanup
            $tempFile->delete();
            @unlink($framePath);
            
            Debug::logExit(__METHOD__, ['success' => true]);
            return [
                'success' => true,
                'message' => 'Thumbnail frame updated successfully'
            ];
            
        } catch (\Exception $e) {
            Debug::logError('Error storing thumbnail: ' . $e->getMessage(), __METHOD__, $e);
            return [
                'success' => false,
                'message' => 'Error storing thumbnail: ' . $e->getMessage()
            ];
        }
    }

    protected function validateAndExtractFrame($media, $position = null)
    {
        $fileStore = $this->serviceLocator->get('Omeka\File\Store');
        $extractor = $this->serviceLocator->get('VideoThumbnail\VideoFrameExtractor');
        $settings = $this->settings;

        try {
            $storagePath = sprintf('original/%s', $media->filename());
            $filePath = $fileStore->getLocalPath($storagePath);

            if (!file_exists($filePath) || !is_readable($filePath)) {
                throw new \RuntimeException('Video file not found or not readable');
            }

            // Validate video format
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($filePath);
            if (strpos($mimeType, 'video/') !== 0) {
                throw new \RuntimeException('Invalid file type: ' . $mimeType);
            }

            // Get video duration with enhanced error checking
            $duration = $extractor->getVideoDuration($filePath);
            if ($duration <= 0) {
                // For very small files, use minimum duration
                if (filesize($filePath) < 1048576) { // Less than 1MB
                    $duration = 1.0;
                } else {
                    throw new \RuntimeException('Could not determine video duration');
                }
            }

            // Calculate frame position
            if ($position === null) {
                $defaultPosition = (float)$settings->get('videothumbnail_default_frame', 10);
                $position = max(0, min(100, $defaultPosition));
            }

            $timeInSeconds = ($duration * $position) / 100;
            $timeInSeconds = max(0.1, min($timeInSeconds, $duration - 0.1));

            // Extract frame with multiple fallback attempts
            $framePath = $this->extractFrameWithFallback($extractor, $filePath, $timeInSeconds, $duration);
            
            if (!$framePath) {
                throw new \RuntimeException('Failed to extract frame from video');
            }

            return [
                'success' => true,
                'framePath' => $framePath,
                'position' => $position,
                'duration' => $duration
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    protected function extractFrameWithFallback($extractor, $filePath, $timeInSeconds, $duration)
    {
        // First attempt at specified position
        $framePath = $extractor->extractFrame($filePath, $timeInSeconds);
        if ($this->isValidFrame($framePath)) {
            return $framePath;
        }

        // Try at 25% of duration
        $earlierTime = $duration * 0.25;
        $framePath = $extractor->extractFrame($filePath, $earlierTime);
        if ($this->isValidFrame($framePath)) {
            return $framePath;
        }

        // Try at start of video
        $framePath = $extractor->extractFrame($filePath, 1.0);
        if ($this->isValidFrame($framePath)) {
            return $framePath;
        }

        return null;
    }

    protected function isValidFrame($framePath)
    {
        return $framePath && file_exists($framePath) && filesize($framePath) > 0;
    }

    public function selectFrameAction()
    {
        Debug::log('VideoThumbnailController::selectFrameAction accessed', __METHOD__); // Add this line
        Debug::logEntry(__METHOD__);
        $id = $this->params('id');
        
        if (!$id) {
            Debug::logError('No media ID provided', __METHOD__);
            return $this->redirect()->toRoute('admin');
        }
        
        try {
            $media = $this->api()->read('media', $id)->getContent();
            
            if (!$media || strpos($media->mediaType(), 'video/') !== 0) {
                Debug::logError('Invalid media type or media not found', __METHOD__);
                $this->messenger()->addError('Invalid media type or media not found');
                return $this->redirect()->toRoute('admin');
            }
            
            // Get the file store service
            if (!$this->serviceLocator) {
                throw new \RuntimeException('Service locator is not available');
            }
            $fileStore = $this->serviceLocator->get('Omeka\File\Store');
                
            // Get the video frame extractor
            $extractor = $this->serviceLocator->get('VideoThumbnail\VideoFrameExtractor');
                
            // Construct storage path
            $storagePath = sprintf('original/%s', $media->filename());
            $filePath = $fileStore->getLocalPath($storagePath);
            
            // Get video duration
            $duration = $extractor->getVideoDuration($filePath);
            if ($duration <= 0) {
                Debug::log('Could not determine video duration, using minimum fallback value', __METHOD__);
                $duration = 1.0; // Use a minimal duration for very short videos
            }
            
            // Get number of frames to extract
            $frameCount = $this->settings->get('videothumbnail_frames_count', 5);
            
            // Extract frames
            $extractedFrames = $extractor->extractFrames($filePath, $frameCount);
            
            // Prepare frame data for the view
            $frames = [];
            foreach ($extractedFrames as $index => $framePath) {
                // Create a URL that can be accessed by the browser
                $tempFilename = 'temp-' . basename($framePath);
                $tempFilePath = OMEKA_PATH . '/files/temp/' . $tempFilename;
                
                // Copy the frame to the Omeka temp directory for web access
                copy($framePath, $tempFilePath);
                
                $frameUrl = $this->url()->fromRoute('asset', [
                    'asset' => $tempFilename,
                ]);
                
                // Calculate the position as a percentage of video duration
                $percentPosition = ($index + 1) * (100 / ($frameCount + 1));
                $timeInSeconds = ($duration * $percentPosition) / 100;
                
                // Add frame data
                $frames[] = [
                    'image' => $frameUrl,
                    'time' => $timeInSeconds,
                    'percent' => $percentPosition,
                ];
                
                // Clean up the original frame
                @unlink($framePath);
            }
            
            $view = new ViewModel();
            $view->setVariable('media', $media);
            $view->setVariable('frameCount', $frameCount);
            $view->setVariable('duration', $duration);
            $view->setVariable('frames', $frames);
            Debug::logExit(__METHOD__, 'Success');
            return $view;
        } catch (\Exception $e) {
            Debug::logError('Error loading media: ' . $e->getMessage(), __METHOD__);
            $this->messenger()->addError('Error loading media: ' . $e->getMessage());
            return $this->redirect()->toRoute('admin');
        }
    }

    public function generateFramesAction()
    {
        Debug::logEntry(__METHOD__, ['request' => 'Starting frame generation']);
        
        if (!$this->getRequest()->isPost()) {
            Debug::logWarning('Request is not POST method', __METHOD__);
            return $this->redirect()->toRoute('admin');
        }
        
        $mediaId = $this->params()->fromPost('media_id');
        Debug::log("Processing frame generation for media ID: {$mediaId}", __METHOD__);
        
        if (!$mediaId) {
            Debug::logError('No media ID provided', __METHOD__);
            return new JsonModel([
                'success' => false,
                'message' => 'No media ID provided',
            ]);
        }
        
        try {
            $media = $this->api()->read('media', $mediaId)->getContent();
            Debug::log("Retrieved media: " . $media->filename(), __METHOD__);
            
            if (!$media || strpos($media->mediaType(), 'video/') !== 0) {
                Debug::logWarning("Invalid media type: " . ($media ? $media->mediaType() : 'null'), __METHOD__);
                return new JsonModel([
                    'success' => false,
                    'message' => 'This media is not a supported video format.',
                ]);
            }

            // Get services and settings
            $ffmpegPath = $this->settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
            $frameCount = $this->settings->get('videothumbnail_frames_count', 5);
            Debug::log("Using FFmpeg path: {$ffmpegPath}, Frame count: {$frameCount}", __METHOD__);
            
            $fileStore = $this->serviceLocator->get('Omeka\File\Store');
            $storagePath = sprintf('original/%s', $media->filename());
            $filePath = $fileStore->getLocalPath($storagePath);
            Debug::log("Video file path: {$filePath}", __METHOD__);
            
            // Get video duration with enhanced logging
            $extractor = $this->serviceLocator->get('VideoThumbnail\VideoFrameExtractor');
            $duration = $extractor->getVideoDuration($filePath);
            Debug::log("Video duration detected: {$duration} seconds", __METHOD__);
            
            if ($duration <= 0) {
                Debug::logWarning("Invalid duration detected, using fallback", __METHOD__);
                $duration = 5.0;
            }
            
            // Extract frames with progress logging
            Debug::log("Starting frame extraction process", __METHOD__);
            $frames = $extractor->extractFrames($filePath, $frameCount);
            Debug::log("Extracted " . count($frames) . " frames", __METHOD__);
            
            $framePaths = [];
            foreach ($frames as $index => $framePath) {
                $tempFilename = 'temp-' . basename($framePath);
                $tempFilePath = OMEKA_PATH . '/files/temp/' . $tempFilename;
                
                Debug::log("Processing frame {$index}: {$framePath} -> {$tempFilePath}", __METHOD__);
                
                if (copy($framePath, $tempFilePath)) {
                    $frameUrl = $this->url()->fromRoute('asset', ['asset' => $tempFilename]);
                    $percentPosition = ($index + 1) * (100 / ($frameCount + 1));
                    $timeInSeconds = ($duration * $percentPosition) / 100;
                    
                    $framePaths[] = [
                        'index' => $index,
                        'path' => $frameUrl,
                        'position' => $percentPosition,
                        'time' => $timeInSeconds,
                        'original_path' => $framePath,
                    ];
                    Debug::log("Frame {$index} processed successfully", __METHOD__);
                } else {
                    Debug::logError("Failed to copy frame {$index} to temp location", __METHOD__);
                }
            }
            
            Debug::logExit(__METHOD__, ['frames_processed' => count($framePaths)]);
            return new JsonModel([
                'success' => true,
                'frames' => $framePaths,
            ]);
            
        } catch (\Exception $e) {
            Debug::logError('Frame generation error: ' . $e->getMessage(), __METHOD__, $e);
            return new JsonModel([
                'success' => false,
                'message' => 'Error generating frames. Check logs for details.',
                'details' => $e->getMessage()
            ]);
        }
    }
}