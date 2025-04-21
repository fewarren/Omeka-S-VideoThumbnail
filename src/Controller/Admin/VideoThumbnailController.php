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

    public function __construct($entityManager, $fileManager = null, $serviceLocator = null)
    {
        $this->entityManager = $entityManager;
        $this->fileManager = $fileManager;
        $this->serviceLocator = $serviceLocator;
        
        // Log initialization
        error_log('VideoThumbnail: Controller initialized');
    }

    public function setSettings($settings)
    {
        $this->settings = $settings;
        return $this;
    }

    public function indexAction()
    {
        // Log initialization of action
        error_log('VideoThumbnail: indexAction started');
        
        // Initialize the debug system with settings
        if ($this->settings) {
            Debug::logEntry(__METHOD__);
        } else {
            error_log('VideoThumbnail: Settings not available for debug initialization');
        }
        
        // Get the form from service manager instead of creating it directly
        try {
            // Ensure we have a service locator
            if (!$this->serviceLocator) {
                error_log('VideoThumbnail: Service locator was not injected properly');
                throw new \RuntimeException('Service locator is not available');
            }
            $form = $this->serviceLocator->get('FormElementManager')->get(ConfigBatchForm::class);
            $form->init();

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
            $supportedFormats = $this->settings->get('videothumbnail_supported_formats', $defaultSupportedFormats);
            if (!is_array($supportedFormats) || empty($supportedFormats)) {
                $supportedFormats = $defaultSupportedFormats;
            }
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error initializing form: ' . $e->getMessage());
            error_log('VideoThumbnail: ' . $e->getTraceAsString());
            
            // Fallback to create basic form
            $form = new ConfigBatchForm();
            $form->init();
            $supportedFormats = ['video/mp4', 'video/quicktime'];
        }

        // Set debug mode value and other settings with error handling
        try {
            $debugMode = $this->settings->get('videothumbnail_debug_mode', false);
            $defaultFrame = $this->settings->get('videothumbnail_default_frame', 10);
            
            $form->setData([
                'default_frame_position' => $defaultFrame,
                'supported_formats' => $supportedFormats,
                'debug_mode' => $debugMode,
            ]);
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error setting form data: ' . $e->getMessage());
            
            // Use defaults if settings access fails
            $form->setData([
                'default_frame_position' => 10,
                'supported_formats' => $supportedFormats,
                'debug_mode' => false,
            ]);
        }

        $request = $this->getRequest();
        if ($request->isPost()) {
            $form->setData($request->getPost());
            if ($form->isValid()) {
                $formData = $form->getData();
                $this->settings->set('videothumbnail_default_frame', $formData['default_frame_position']);
                $this->settings->set('videothumbnail_supported_formats', $formData['supported_formats']);
                
                // Set debug mode if present in form
                if (isset($formData['debug_mode'])) {
                    $this->settings->set('videothumbnail_debug_mode', (bool)$formData['debug_mode']);
                    Debug::log('Debug mode ' . ((bool)$formData['debug_mode'] ? 'enabled' : 'disabled'), __METHOD__);
                }
                
                $this->messenger()->addSuccess('Video thumbnail settings updated.');

                if (!empty($formData['regenerate_thumbnails'])) {
                    try {
                        $dispatcher = $this->jobDispatcher();
                        
                        // Log job dispatch attempt
                        Debug::log('Attempting to dispatch video thumbnail job with frame_position: ' . $formData['default_frame_position'], __METHOD__);
                        error_log('VideoThumbnail: Attempting to dispatch video thumbnail job');
                        
                        $job = $dispatcher->dispatch('VideoThumbnail\Job\ExtractFrames', [
                            'frame_position' => $formData['default_frame_position'],
                        ]);
                        
                        $message = new Message(
                            'Regenerating video thumbnails in the background (job %s). This may take a while.',
                            $job->getId()
                        );
                        $this->messenger()->addSuccess($message);
                        
                        Debug::log('Job dispatched successfully: ' . $job->getId(), __METHOD__);
                        error_log('VideoThumbnail: Job dispatched successfully, ID: ' . $job->getId());
                    } catch (\Exception $e) {
                        // Log detailed error
                        Debug::logError('Job dispatch failed: ' . $e->getMessage(), __METHOD__);
                        error_log('VideoThumbnail: Job dispatch failed: ' . $e->getMessage());
                        error_log('VideoThumbnail: ' . $e->getTraceAsString());
                        
                        // Try fallback to default strategy
                        try {
                            Debug::log('Attempting fallback to default PhpCli strategy', __METHOD__);
                            
                            // Attempt to get the default PHP CLI strategy
                            $dispatcher = $this->jobDispatcher();
                            $serviceLocator = $this->serviceLocator;
                            
                            // Force the strategy to PhpCli
                            $job = $dispatcher->dispatch('VideoThumbnail\Job\ExtractFrames', [
                                'frame_position' => $formData['default_frame_position'],
                                'force_strategy' => 'PhpCli',
                            ]);
                            
                            $message = new Message(
                                'Using default job strategy. Regenerating video thumbnails in the background (job %s). This may take a while.',
                                $job->getId()
                            );
                            $this->messenger()->addSuccess($message);
                            
                            Debug::log('Job dispatched with fallback strategy: ' . $job->getId(), __METHOD__);
                            error_log('VideoThumbnail: Job dispatched with fallback strategy, ID: ' . $job->getId());
                        } catch (\Exception $fallbackException) {
                            Debug::logError('Fallback strategy also failed: ' . $fallbackException->getMessage(), __METHOD__);
                            error_log('VideoThumbnail: Fallback strategy also failed: ' . $fallbackException->getMessage());
                            
                            $this->messenger()->addError('Failed to start thumbnail regeneration job. Check server logs for details.');
                        }
                    }
                }

                return $this->redirect()->toRoute('admin/video-thumbnail');
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel();
        $view->setVariable('form', $form);
        
        try {
            $totalVideos = $this->getTotalVideos();
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error getting total videos: ' . $e->getMessage());
            $totalVideos = 0;
        }
        
        $view->setVariable('totalVideos', $totalVideos);
        $view->setVariable('supportedFormats', implode(', ', $supportedFormats));
        
        if ($this->settings) {
            Debug::logExit(__METHOD__);
        }
        
        // Explicitly set view template to ensure it's found
        $view->setTemplate('video-thumbnail/admin/video-thumbnail/index');
        
        return $view;
    }

    protected function getTotalVideos()
    {
        if ($this->settings) {
            Debug::logEntry(__METHOD__);
        }
        
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
        Debug::logEntry(__METHOD__);
        if (!$this->getRequest()->isPost()) {
            Debug::logExit(__METHOD__, 'Not a POST request');
            return $this->redirect()->toRoute('admin');
        }

        $mediaId = $this->params()->fromPost('media_id');
        $position = $this->params()->fromPost('position');
        
        if (!$mediaId || !is_numeric($position)) {
            Debug::logError('Invalid parameters', __METHOD__);
            return new JsonModel([
                'success' => false,
                'message' => 'Invalid parameters',
            ]);
        }
        
        try {
            $media = $this->api()->read('media', $mediaId)->getContent();
            
            if (!$media) {
                Debug::logError('Media not found: ' . $mediaId, __METHOD__);
                return new JsonModel([
                    'success' => false,
                    'message' => 'Media not found',
                ]);
            }
            
            // Get the file store and video frame extractor to process the video
            $fileStore = $this->serviceLocator->get('Omeka\File\Store');
            $videoFrameExtractor = $this->serviceLocator->get('VideoThumbnail\VideoFrameExtractor');
            
            // Get the file path
            $storagePath = sprintf('original/%s', $media->filename());
            $filePath = $fileStore->getLocalPath($storagePath);
            
            // Get video duration
            $duration = $videoFrameExtractor->getVideoDuration($filePath);
            if ($duration <= 0) {
                Debug::logError('Could not determine video duration for: ' . $filePath, __METHOD__);
                $duration = 1.0; // Use a minimal duration for very short videos
            } else if ($duration < 5.0) {
                Debug::log('Very short video detected: ' . $filePath . ' (duration: ' . $duration . ' seconds)', __METHOD__);
            } else if ($duration === 60.0) {
                // Check if this is likely the default fallback value
                $fileSize = filesize($filePath);
                if ($fileSize < 10485760) { // Less than 10MB
                    Debug::log('Default duration (60s) may be inaccurate for small video: ' . $filePath . 
                        ' (' . ($fileSize / 1048576) . ' MB)', __METHOD__);
                    // For small files with default duration, use a smaller estimate
                    $duration = 5.0;
                }
            }
            
            // Calculate time in seconds from the percentage
            $percentagePosition = (float) $position;
            $timeInSeconds = ($duration * $percentagePosition) / 100;
            
            // Ensure the position is within valid range (at least 0.1s from start)
            $timeInSeconds = max(0.1, min($timeInSeconds, $duration - 0.1));
            
            Debug::log(sprintf('Saving frame at %.2f%% (%.2f seconds of %.2f seconds duration)', 
                $percentagePosition, $timeInSeconds, $duration), __METHOD__);
            
            // Extract the frame at the selected position
            $framePath = $videoFrameExtractor->extractFrame($filePath, $timeInSeconds);
            
            if (!$framePath) {
                Debug::logError('Failed to extract frame from video: ' . $filePath, __METHOD__);
                return new JsonModel([
                    'success' => false,
                    'message' => 'Failed to extract frame from video',
                ]);
            }
            
            // Store the frame in Omeka's thumbnail system
            try {
                // Get the temp file factory
                $tempFileFactory = $this->serviceLocator->get('Omeka\File\TempFileFactory');
                $tempFile = $tempFileFactory->build();
                
                // Get the entity manager for direct entity manipulation
                $entityManager = $this->entityManager;
                
                // Get the actual media entity
                $mediaEntity = $entityManager->find('Omeka\Entity\Media', $mediaId);
                
                if (!$mediaEntity) {
                    Debug::logError('Media entity not found: ' . $mediaId, __METHOD__);
                    return new JsonModel([
                        'success' => false,
                        'message' => 'Media entity not found',
                    ]);
                }
                
                // Copy the extracted frame to the temp file
                if (copy($framePath, $tempFile->getTempPath())) {
                    // Set the storage ID to match the media's
                    $tempFile->setStorageId($mediaEntity->getStorageId());
                    
                    // Store thumbnails using Omeka's built-in system
                    $hasThumbnails = $tempFile->storeThumbnails();
                    
                    // Set the hasThumbnails flag on the media entity
                    $mediaEntity->setHasThumbnails($hasThumbnails);
                    
                    // Update media data to store the frame position
                    $mediaData = $mediaEntity->getData() ?: [];
                    $mediaData['videothumbnail_frame_percentage'] = $percentagePosition;
                    $mediaData['videothumbnail_frame_time'] = $timeInSeconds;
                    $mediaEntity->setData($mediaData);
                    
                    // Ensure thumbnail paths are properly stored in the database
                    $thumbnailSynchronizer = $this->serviceLocator->get('VideoThumbnail\ThumbnailSynchronizer');
                    $thumbnailSynchronizer->updateThumbnailStoragePaths($mediaEntity);
                    
                    // Persist the entity changes
                    $entityManager->persist($mediaEntity);
                    $entityManager->flush();
                    
                    // Clean up temporary files
                    $tempFile->delete();
                    @unlink($framePath);
                    
                    Debug::log('Successfully stored thumbnails for media ' . $mediaId, __METHOD__);
                } else {
                    Debug::logError('Failed to copy extracted frame to temp file for media ' . $mediaId, __METHOD__);
                    @unlink($framePath);
                    return new JsonModel([
                        'success' => false,
                        'message' => 'Failed to process extracted frame',
                    ]);
                }
            } catch (\Exception $e) {
                Debug::logError('Error storing thumbnails: ' . $e->getMessage(), __METHOD__);
                @unlink($framePath);
                return new JsonModel([
                    'success' => false,
                    'message' => 'Error storing thumbnails: ' . $e->getMessage(),
                ]);
            }
            
            Debug::logExit(__METHOD__, 'Success');
            return new JsonModel([
                'success' => true,
                'message' => 'Thumbnail frame updated successfully',
            ]);
        } catch (\Exception $e) {
            Debug::logError('Error updating frame: ' . $e->getMessage(), __METHOD__);
            return new JsonModel([
                'success' => false,
                'message' => 'Error updating frame: ' . $e->getMessage(),
            ]);
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
        Debug::logEntry(__METHOD__);
        if (!$this->getRequest()->isPost()) {
            Debug::logExit(__METHOD__, 'Not a POST request');
            return $this->redirect()->toRoute('admin');
        }
        
        $mediaId = $this->params()->fromPost('media_id');
        
        if (!$mediaId) {
            Debug::logError('No media ID provided', __METHOD__);
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
                    'message' => 'This media is not a supported video format. Please check the supported formats in the Video Thumbnail settings.',
                    'help' => 'See the Troubleshooting Guide for supported formats and solutions.'
                ]);
            }
            $ffmpegPath = $this->settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
            $frameCount = $this->settings->get('videothumbnail_frames_count', 5);
            
            // Get the extractor from service manager
            if (!$this->serviceLocator) {
                throw new \RuntimeException('Service locator is not available');
            }
            $extractor = $this->serviceLocator->get('VideoThumbnail\VideoFrameExtractor');
            // Get the file store service
            if (!$this->serviceLocator) {
                throw new \RuntimeException('Service locator is not available');
            }
            $fileStore = $this->serviceLocator->get('Omeka\File\Store');
                
            // Construct storage path
            $storagePath = sprintf('original/%s', $media->filename());
            $filePath = $fileStore->getLocalPath($storagePath);
            
            // Get video duration first
            $duration = $extractor->getVideoDuration($filePath);
            if ($duration <= 0) {
                Debug::log('Could not determine video duration, using minimum fallback value', __METHOD__);
                $duration = 1.0; // Use a minimal duration for very short videos
            } else if ($duration < 5.0) {
                Debug::log('Very short video detected: ' . $filePath . ' (duration: ' . $duration . ' seconds)', __METHOD__);
            } else if ($duration === 60.0) {
                // Check if this is likely the default fallback value
                $fileSize = filesize($filePath);
                if ($fileSize < 10485760) { // Less than 10MB
                    Debug::log('Default duration (60s) may be inaccurate for small video: ' . $filePath . 
                        ' (' . ($fileSize / 1048576) . ' MB)', __METHOD__);
                    // For small files with default duration, use a smaller estimate
                    $duration = 5.0;
                }
            }
            
            Debug::log(sprintf('Extracting frames from video: %s (duration: %.2f seconds)', $filePath, $duration), __METHOD__);
            $frames = $extractor->extractFrames($filePath, $frameCount);
            
            $framePaths = [];
            foreach ($frames as $index => $framePath) {
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
                
                // Ensure the position is within valid range (at least 0.1s from start)
                $timeInSeconds = max(0.1, min($timeInSeconds, $duration - 0.1));
                
                // Store both the percentage and the actual time in seconds
                $framePaths[] = [
                    'index' => $index,
                    'path' => $frameUrl,
                    'position' => $percentPosition,
                    'time' => $timeInSeconds, // Time in seconds
                    'original_path' => $framePath, // Keep track of the original path for cleanup
                ];
            }
            
            // Comment out register_shutdown_function to prevent possible hangs
            // register_shutdown_function(function() use ($originalPaths) {
            //     foreach ($originalPaths as $path) {
            //         if (file_exists($path)) {
            //             @unlink($path);
            //         }
            //     }
            // });
            
            Debug::logExit(__METHOD__, 'Generated ' . count($framePaths) . ' frames');
            return new JsonModel([
                'success' => true,
                'frames' => $framePaths,
            ]);
        } catch (\Exception $e) {
            Debug::logError('Error loading media: ' . $e->getMessage(), __METHOD__);
            return new JsonModel([
                'success' => false,
                'message' => 'Error loading media. Please check file permissions and try again. If the problem persists, see the Troubleshooting Guide.',
                'details' => $e->getMessage(),
                'help' => 'See TROUBLESHOOTING.md for more information.'
            ]);
        }
    }
}