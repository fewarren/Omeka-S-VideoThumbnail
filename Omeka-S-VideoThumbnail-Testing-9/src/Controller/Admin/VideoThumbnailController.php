<?php
namespace VideoThumbnail\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Omeka\Stdlib\Message;
use VideoThumbnail\Form\ConfigBatchForm;
// Don't use Debug in controller initialization
// use VideoThumbnail\Stdlib\Debug;

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
        
        // Simple error_log instead of Debug
        error_log('VideoThumbnail: Controller initialized');
    }

    public function setSettings($settings)
    {
        $this->settings = $settings;
        // Remove Debug call
        return $this;
    }

    public function indexAction()
    {
        // Remove all Debug calls from initialization
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
            
            // Get supported formats setting
            $storedFormats = null;
            try {
                $storedFormats = $this->settings->get('videothumbnail_supported_formats');
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Failed to get videothumbnail_supported_formats: ' . $e->getMessage());
            }
            
            // Apply default if needed
            $supportedFormats = $storedFormats;
            if (!is_array($supportedFormats) || empty($supportedFormats)) {
                $supportedFormats = $defaultSupportedFormats;
            }
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error initializing form: ' . $e->getMessage());
            
            // Fallback to create basic form
            $form = new ConfigBatchForm();
            $form->init();
            $supportedFormats = ['video/mp4', 'video/quicktime'];
        }

        // Set settings with error handling
        try {
            // Get debug mode setting
            $debugMode = false;
            try {
                $debugMode = (bool)$this->settings->get('videothumbnail_debug_mode', false);
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Failed to get videothumbnail_debug_mode: ' . $e->getMessage());
                $debugMode = false; // Default to false for stability
            }
            
            // Get default frame setting
            $defaultFrame = 10;
            try {
                $defaultFrame = (int)$this->settings->get('videothumbnail_default_frame', 10);
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Failed to get videothumbnail_default_frame: ' . $e->getMessage());
            }
            
            // Get timestamp property setting
            $timestampProperty = null;
            try {
                $timestampProperty = $this->settings->get('videothumbnail_timestamp_property');
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Failed to get videothumbnail_timestamp_property: ' . $e->getMessage());
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
            
            $form->setData($formData);
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error setting form data: ' . $e->getMessage());
            
            // Use defaults if settings access fails
            $form->setData([
                'default_frame_position' => 10,
                'supported_formats' => $supportedFormats,
                'debug_mode' => false, // Default to false for stability
            ]);
        }

        $request = $this->getRequest();
        if ($request->isPost()) {
            $postData = $request->getPost()->toArray();
            
            $form->setData($postData);
            if ($form->isValid()) {
                $formData = $form->getData();
                
                try {
                    // Save settings without detailed debugging
                    $this->settings->set('videothumbnail_default_frame', $formData['default_frame_position']);
                    $this->settings->set('videothumbnail_supported_formats', $formData['supported_formats']);
                    $this->settings->set('videothumbnail_debug_mode', !empty($formData['debug_mode']));
                    
                    // Only update timestamp property if it exists in form data
                    if (isset($formData['timestamp_property'])) {
                        $this->settings->set('videothumbnail_timestamp_property', $formData['timestamp_property']);
                    }
                    
                    $this->messenger()->addSuccess('Video thumbnail settings updated.'); // Success message
                } catch (\Exception $e) {
                    error_log('VideoThumbnail: Failed to save settings: ' . $e->getMessage());
                    $this->messenger()->addError('Failed to save video thumbnail settings: ' . $e->getMessage()); // Error message
                }
            } else {
                $messages = $form->getMessages();
                $this->messenger()->addError('There was an error during form validation. Please check the form and try again.'); // Form validation error
            }
        }

        try {
            $totalVideos = $this->getTotalVideos();
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error getting total videos: ' . $e->getMessage());
            $totalVideos = 0;
        }
        
        $view = new ViewModel();
        $view->setVariable('form', $form);
        $view->setVariable('totalVideos', $totalVideos);
        $view->setVariable('supportedFormats', implode(', ', $supportedFormats));
        
        $view->setTemplate('video-thumbnail/admin/video-thumbnail/index');
        
        return $view;
    }

    protected function getTotalVideos()
    {
        try {
            // Check if entity manager is available
            if (!$this->entityManager) {
                error_log('VideoThumbnail: Entity manager is not available');
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
            
            return $result;
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error in getTotalVideos: ' . $e->getMessage());
            return 0;
        }
    }

    public function saveFrameAction()
    {
        // Conditional debug logging that's safe
        $debugMode = false;
        try {
            if ($this->settings) {
                $debugMode = (bool)$this->settings->get('videothumbnail_debug_mode', false);
            }
        } catch (\Exception $e) {
            // Ignore settings errors
        }
        
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin');
        }

        $mediaId = $this->params()->fromPost('media_id');
        $position = $this->params()->fromPost('position');
        
        if (!$mediaId || !is_numeric($position)) {
            return new JsonModel([
                'success' => false,
                'message' => 'Invalid parameters',
            ]);
        }
        
        try {
            $media = $this->api()->read('media', $mediaId)->getContent();
            
            if (!$media) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Media not found',
                ]);
            }
            
            // Extract and save the frame
            $result = $this->extractAndSaveFrame($media, $position);
            
            return new JsonModel($result);
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Frame save error: ' . $e->getMessage());
            return new JsonModel([
                'success' => false,
                'message' => 'Error saving frame',
                'details' => $e->getMessage()
            ]);
        }
    }

    protected function extractAndSaveFrame($media, $position)
    {
        try {
            $fileStore = $this->serviceLocator->get('Omeka\File\Store');
            $extractor = $this->serviceLocator->get('VideoThumbnail\VideoFrameExtractor');
            
            $storagePath = sprintf('original/%s', $media->filename());
            $filePath = $fileStore->getLocalPath($storagePath);
            
            // Get video duration
            $duration = $extractor->getVideoDuration($filePath);
            
            if ($duration <= 0) {
                $duration = 5.0;
            }
            
            // Calculate frame time
            $timeInSeconds = ($duration * $position) / 100;
            $timeInSeconds = max(0.1, min($timeInSeconds, $duration - 0.1));
            
            // Extract the frame
            $framePath = $extractor->extractFrame($filePath, $timeInSeconds);
            if (!$framePath) {
                return [
                    'success' => false,
                    'message' => 'Failed to extract frame'
                ];
            }
            
            // Store the frame
            $result = $this->storeThumbnail($media, $framePath, $position, $timeInSeconds);
            
            return $result;
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Frame extraction error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error processing frame: ' . $e->getMessage()
            ];
        }
    }

    protected function storeThumbnail($media, $framePath, $position, $timeInSeconds)
    {
        try {
            $tempFileFactory = $this->serviceLocator->get('Omeka\File\TempFileFactory');
            $tempFile = $tempFileFactory->build();
            
            if (!copy($framePath, $tempFile->getTempPath())) {
                return [
                    'success' => false,
                    'message' => 'Failed to process frame'
                ];
            }
            
            // Get the media entity
            $mediaEntity = $this->entityManager->find('Omeka\Entity\Media', $media->id());
            if (!$mediaEntity) {
                return [
                    'success' => false,
                    'message' => 'Media entity not found'
                ];
            }
            
            // Store thumbnails
            $tempFile->setStorageId($mediaEntity->getStorageId());
            $hasThumbnails = $tempFile->storeThumbnails();
            
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
            
            return [
                'success' => true,
                'message' => 'Thumbnail frame updated successfully'
            ];
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error storing thumbnail: ' . $e->getMessage());
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
        $id = $this->params('id');
        
        if (!$id) {
            return $this->redirect()->toRoute('admin');
        }
        
        try {
            $media = $this->api()->read('media', $id)->getContent();
            
            if (!$media || strpos($media->mediaType(), 'video/') !== 0) {
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
            return $view;
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error loading media: ' . $e->getMessage());
            $this->messenger()->addError('Error loading media: ' . $e->getMessage());
            return $this->redirect()->toRoute('admin');
        }
    }

    public function generateFramesAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin');
        }
        
        $mediaId = $this->params()->fromPost('media_id');
        
        if (!$mediaId) {
            return new JsonModel([
                'success' => false,
                'message' => 'No media ID provided',
            ]);
        }
        
        try {
            $media = $this->api()->read('media', $mediaId)->getContent();
            
            if (!$media || strpos($media->mediaType(), 'video/') !== 0) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'This media is not a supported video format.',
                ]);
            }

            // Get services and settings
            $ffmpegPath = $this->settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
            $frameCount = $this->settings->get('videothumbnail_frames_count', 5);
            
            $fileStore = $this->serviceLocator->get('Omeka\File\Store');
            $storagePath = sprintf('original/%s', $media->filename());
            $filePath = $fileStore->getLocalPath($storagePath);
            
            // Get video duration with enhanced logging
            $extractor = $this->serviceLocator->get('VideoThumbnail\VideoFrameExtractor');
            $duration = $extractor->getVideoDuration($filePath);
            
            if ($duration <= 0) {
                $duration = 5.0;
            }
            
            // Extract frames with progress logging
            $frames = $extractor->extractFrames($filePath, $frameCount);
            
            $framePaths = [];
            foreach ($frames as $index => $framePath) {
                $tempFilename = 'temp-' . basename($framePath);
                $tempFilePath = OMEKA_PATH . '/files/temp/' . $tempFilename;
                
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
                }
            }
            
            return new JsonModel([
                'success' => true,
                'frames' => $framePaths,
            ]);
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Frame generation error: ' . $e->getMessage());
            return new JsonModel([
                'success' => false,
                'message' => 'Error generating frames. Check logs for details.',
                'details' => $e->getMessage()
            ]);
        }
    }
}