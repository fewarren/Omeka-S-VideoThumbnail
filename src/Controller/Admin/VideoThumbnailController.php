<?php
namespace VideoThumbnail\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Omeka\Stdlib\Message;
use VideoThumbnail\Form\ConfigBatchForm;

/**
 * Admin controller for VideoThumbnail module
 */
class VideoThumbnailController extends AbstractActionController
{
    /**
     * Entity manager
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;
    
    /**
     * File manager
     * @var mixed
     */
    protected $fileManager;
    
    /**
     * Settings manager
     * @var \Omeka\Settings\Settings
     */
    protected $settings;
    
    /**
     * Container
     * @var \Interop\Container\ContainerInterface
     */
    protected $serviceLocator;
    
    /**
     * Form manager
     * @var \Laminas\Form\FormElementManager
     */
    protected $formManager;

    /**
     * Constructor
     */
    public function __construct($entityManager, $fileManager = null, $serviceLocator = null)
    {
        $this->entityManager = $entityManager;
        $this->fileManager = $fileManager;
        $this->serviceLocator = $serviceLocator;
        
        if ($serviceLocator && $serviceLocator->has('FormElementManager')) {
            $this->formManager = $serviceLocator->get('FormElementManager');
        }
    }
    
    /**
     * Set settings
     */
    public function setSettings($settings)
    {
        $this->settings = $settings;
        return $this;
    }
    
    /**
     * Write debug info directly to a file
     */
    private function debugLog($message) 
    {
        // Try to use a path we're sure will be writable
        $logPath = __DIR__ . '/../../../../videothumbnail_debug.log';
        
        // Append to log file with timestamp
        $entry = date('Y-m-d H:i:s') . ' - ' . $message . "\n";
        @file_put_contents($logPath, $entry, FILE_APPEND);
        
        // Also echo the message if in CLI mode
        if (PHP_SAPI === 'cli') {
            echo $entry;
        }
    }

    /**
     * Main admin index action
     */
    public function indexAction()
    {
        // Add explicit debug output to verify execution
        $this->debugLog('VideoThumbnail indexAction called');
        error_log('DEBUG: VideoThumbnail indexAction called - ' . date('Y-m-d H:i:s'));
        
        // Create form - don't use form manager to avoid potential issues
        $form = new ConfigBatchForm();
        $this->debugLog('Form created directly - ' . get_class($form));
        
        // Explicitly call init to ensure all elements are added
        $form->init();
        error_log('DEBUG: Form initialized, elements: ' . implode(', ', array_keys($form->getElements())));
        
        // Get current settings with safer defaults
        $defaultFrame = 10;
        $supportedFormats = ['video/mp4', 'video/quicktime', 'video/x-msvideo'];
        $debugMode = false;
        
        // Try to get settings from the service
        if ($this->settings) {
            $defaultFrame = $this->settings->get('videothumbnail_default_frame', 10);
            $supportedFormats = $this->settings->get('videothumbnail_supported_formats', $supportedFormats);
            $debugMode = $this->settings->get('videothumbnail_debug_mode', false);
        }
        
        // Get video count
        $videoCount = $this->getVideoCount($supportedFormats);
        
        // Handle form submission
        $request = $this->getRequest();
        if ($request->isPost()) {
            // Get POST data
            $data = $request->getPost()->toArray();
            
            // Set data to form
            $form->setData($data);
            
            // Validate form
            if ($form->isValid()) {
                $formData = $form->getData();
                
                // Save settings if settings service is available
                if ($this->settings) {
                    // Save default frame position
                    $framePosition = isset($formData['default_frame_position']) ? (int)$formData['default_frame_position'] : 10;
                    $this->settings->set('videothumbnail_default_frame', $framePosition);
                    
                    // Save supported formats
                    $formats = isset($formData['supported_formats']) ? $formData['supported_formats'] : [];
                    if (empty($formats)) {
                        $formats = ['video/mp4']; // Fallback to at least one format
                    }
                    $this->settings->set('videothumbnail_supported_formats', $formats);
                    
                    // Save debug mode
                    $debugMode = isset($formData['debug_mode']) && $formData['debug_mode'] === '1';
                    $this->settings->set('videothumbnail_debug_mode', $debugMode);
                }
                
                // Handle thumbnail regeneration
                if (!empty($formData['regenerate_thumbnails']) && $formData['regenerate_thumbnails'] === '1') {
                    $this->dispatchBatchJob(
                        isset($formData['default_frame_position']) ? (int)$formData['default_frame_position'] : 10,
                        isset($formData['supported_formats']) ? $formData['supported_formats'] : ['video/mp4']
                    );
                }
                
                // Set success message and redirect
                $this->messenger()->addSuccess('Video thumbnail settings updated.');
                return $this->redirect()->toRoute('admin/video-thumbnail');
            } else {
                $this->messenger()->addError('There was an error in the form submission.');
            }
        } else {
            // Populate form with current settings
            $formData = [
                'default_frame_position' => $defaultFrame,
                'supported_formats' => $supportedFormats,
                'debug_mode' => $debugMode ? '1' : '0',
                'regenerate_thumbnails' => '0'
            ];
            
            $form->setData($formData);
        }
        
        // Create view with necessary variables
        $view = new ViewModel([
            'form' => $form,
            'videoCount' => $videoCount,
            'defaultFrame' => $defaultFrame,
            'supportedFormats' => $supportedFormats,
            'debugMode' => $debugMode
        ]);
        
        return $view;
    }
    
    /**
     * Get count of video files in the repository
     */
    protected function getVideoCount($supportedFormats)
    {
        try {
            $queryBuilder = $this->entityManager->createQueryBuilder();
            $queryBuilder->select('COUNT(m.id)')
                ->from('Omeka\Entity\Media', 'm')
                ->where($queryBuilder->expr()->in('m.mediaType', ':formats'))
                ->setParameter('formats', $supportedFormats);
                
            return (int)$queryBuilder->getQuery()->getSingleScalarResult();
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Dispatch batch job to update thumbnails
     */
    protected function dispatchBatchJob($framePosition, $formats)
    {
        try {
            $job = $this->jobDispatcher()->dispatch('VideoThumbnail\Job\ExtractFrames', [
                'frame_position' => $framePosition,
                'formats' => $formats,
            ]);
            
            $this->messenger()->addSuccess('Successfully started background job to update video thumbnails.');
            return true;
        } catch (\Exception $e) {
            $this->messenger()->addError('Error starting background job: ' . $e->getMessage());
            return false;
        }
    }
}