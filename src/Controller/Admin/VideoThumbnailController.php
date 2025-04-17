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

    public function __construct($entityManager, $fileManager, $serviceLocator = null)
    {
        $this->entityManager = $entityManager;
        $this->fileManager = $fileManager;
        $this->serviceLocator = $serviceLocator;
    }

    public function setSettings($settings)
    {
        $this->settings = $settings;
        return $this;
    }

    public function indexAction()
    {
        // Initialize the debug system with settings
        Debug::init($this->settings);
        Debug::logEntry(__METHOD__);
        
        // Get the form from service manager instead of creating it directly
        $form = $this->serviceLocator->get('FormElementManager')->get(ConfigBatchForm::class);
        $form->init();

        $supportedFormats = $this->settings->get('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']);
        if (!is_array($supportedFormats)) {
            $supportedFormats = ['video/mp4', 'video/quicktime'];
        }

        // Set debug mode value
        $debugMode = $this->settings->get('videothumbnail_debug_mode', false);
        
        $form->setData([
            'default_frame_position' => $this->settings->get('videothumbnail_default_frame', 10),
            'supported_formats' => $supportedFormats,
            'debug_mode' => $debugMode,
        ]);

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
        $view->setVariable('totalVideos', $this->getTotalVideos());
        $view->setVariable('supportedFormats', implode(', ', $supportedFormats));
        Debug::logExit(__METHOD__);
        return $view;
    }

    protected function getTotalVideos()
    {
        Debug::logEntry(__METHOD__);
        // Assuming you have access to the entity manager to query the database
        $repository = $this->entityManager->getRepository('Omeka\Entity\Media');
        
        // Query for the total number of videos based on supported formats
        $supportedFormats = $this->settings->get('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']);
        $queryBuilder = $repository->createQueryBuilder('media');
        $queryBuilder->select('COUNT(media.id)')
                     ->where($queryBuilder->expr()->in('media.mediaType', ':formats'))
                     ->setParameter('formats', $supportedFormats);

        $result = (int) $queryBuilder->getQuery()->getSingleScalarResult();
        Debug::logExit(__METHOD__, $result);
        return $result;
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