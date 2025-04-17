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
            Debug::init($this->settings);
            Debug::logEntry(__METHOD__);
        } else {
            error_log('VideoThumbnail: Settings not available for debug initialization');
        }
        
        // Get the form from service manager instead of creating it directly
        try {
            if (!$this->serviceLocator) {
                error_log('VideoThumbnail: Service locator is null');
                $this->serviceLocator = $this->getEvent()->getApplication()->getServiceManager();
            }
            $form = $this->serviceLocator->get('FormElementManager')->get(ConfigBatchForm::class);
            $form->init();

            $supportedFormats = $this->settings->get('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']);
            if (!is_array($supportedFormats)) {
                $supportedFormats = ['video/mp4', 'video/quicktime'];
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
                    Debug::setEnabled((bool)$formData['debug_mode']);
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
            $supportedFormats = $this->settings ? 
                $this->settings->get('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']) : 
                ['video/mp4', 'video/quicktime'];
                
            if (!is_array($supportedFormats)) {
                $supportedFormats = ['video/mp4', 'video/quicktime'];
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
            
            // Update the media with the selected frame position
            $response = $this->api()->update('media', $mediaId, [
                'videothumbnail_frame' => (int) $position,
            ]);
            
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
            
            $view = new ViewModel();
            $view->setVariable('media', $media);
            $view->setVariable('frameCount', $this->settings->get('videothumbnail_frames_count', 5));
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
                Debug::logError('Invalid media type or media not found', __METHOD__);
                return new JsonModel([
                    'success' => false,
                    'message' => 'Invalid media type or media not found',
                ]);
            }
            
            $ffmpegPath = $this->settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
            $frameCount = $this->settings->get('videothumbnail_frames_count', 5);
            
            // Get the extractor from service manager properly
            if ($this->serviceLocator) {
                $extractor = $this->serviceLocator->get('VideoThumbnail\VideoFrameExtractor');
            } else {
                // Fallback to use controller plugin
                $extractor = $this->extractVideoFrames();
            }
            $filePath = $media->originalFilePath();
            
            Debug::log('Extracting frames from video: ' . $filePath, __METHOD__);
            $frames = $extractor->extractFrames($filePath, $frameCount);
            
            $framePaths = [];
            foreach ($frames as $index => $framePath) {
                $frameUrl = $this->url()->fromRoute('asset', [
                    'asset' => 'temp-' . basename($framePath),
                ]);
                $framePaths[] = [
                    'index' => $index,
                    'path' => $frameUrl,
                    'position' => ($index + 1) * (100 / ($frameCount + 1)),
                ];
            }
            
            Debug::logExit(__METHOD__, 'Generated ' . count($framePaths) . ' frames');
            return new JsonModel([
                'success' => true,
                'frames' => $framePaths,
            ]);
        } catch (\Exception $e) {
            Debug::logError('Error generating frames: ' . $e->getMessage(), __METHOD__);
            return new JsonModel([
                'success' => false,
                'message' => 'Error generating frames: ' . $e->getMessage(),
            ]);
        }
    }
}