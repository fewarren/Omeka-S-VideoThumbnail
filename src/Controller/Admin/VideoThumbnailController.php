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
        
        error_log('VideoThumbnailController initialized');
    }
    
    /**
     * Set settings
     */
    public function setSettings($settings)
    {
        $this->settings = $settings;
        error_log('Settings service injected into controller');
        return $this;
    }
    
    /**
     * Get a form from the form element manager
     */
    protected function getFormFromManager($formClass)
    {
        if ($this->formManager) {
            try {
                return $this->formManager->get($formClass);
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Error getting form from manager: ' . $e->getMessage());
            }
        }
        
        // Fall back to parent method if form manager is not available
        return parent::getForm($formClass);
    }

    /**
     * Main admin index action
     */
    public function indexAction()
    {
        error_log('VideoThumbnail: Admin index action accessed');
        
        try {
            // Create the form directly
            try {
                // First try with form element manager if available
                if ($this->formManager) {
                    $form = $this->formManager->get(ConfigBatchForm::class);
                    error_log('VideoThumbnail: Created form from form manager');
                } else {
                    // Try using controller's getForm
                    $form = $this->getForm(ConfigBatchForm::class);
                    error_log('VideoThumbnail: Created form using getForm()');
                }
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Error creating form: ' . $e->getMessage());
                
                // Last resort - create directly
                $form = new ConfigBatchForm();
                error_log('VideoThumbnail: Created form directly');
            }
            
            // Ensure form is initialized
            if (method_exists($form, 'init')) {
                $form->init();
            }
            
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
            
            error_log("VideoThumbnail: Got settings: defaultFrame=$defaultFrame, debugMode=" . ($debugMode ? 'true' : 'false'));
            
            // Handle form submission
            $request = $this->getRequest();
            if ($request->isPost()) {
                error_log('VideoThumbnail: Form submitted - processing POST data');
                
                // Get POST data
                $data = $request->getPost()->toArray();
                error_log('VideoThumbnail: POST data: ' . json_encode($data));
                
                // Set data to form
                $form->setData($data);
                
                // Validate form
                if ($form->isValid()) {
                    error_log('VideoThumbnail: Form is valid, saving settings');
                    $formData = $form->getData();
                    error_log('VideoThumbnail: Form data: ' . json_encode($formData));
                    
                    // Save settings if settings service is available
                    if ($this->settings) {
                        error_log('VideoThumbnail: Saving to settings service');
                        
                        // Save default frame position
                        $framePosition = isset($formData['default_frame_position']) ? (int)$formData['default_frame_position'] : 10;
                        $this->settings->set('videothumbnail_default_frame', $framePosition);
                        error_log('VideoThumbnail: Saved frame position: ' . $framePosition);
                        
                        // Save supported formats
                        $formats = isset($formData['supported_formats']) ? $formData['supported_formats'] : [];
                        if (empty($formats)) {
                            $formats = ['video/mp4']; // Fallback to at least one format
                        }
                        $this->settings->set('videothumbnail_supported_formats', $formats);
                        error_log('VideoThumbnail: Saved formats: ' . json_encode($formats));
                        
                        // Save debug mode
                        $debugMode = isset($formData['debug_mode']) && $formData['debug_mode'] === '1';
                        $this->settings->set('videothumbnail_debug_mode', $debugMode);
                        error_log('VideoThumbnail: Saved debug mode: ' . ($debugMode ? 'true' : 'false'));
                    } else {
                        error_log('VideoThumbnail: Settings service not available');
                    }
                    
                    // Handle thumbnail regeneration
                    if (!empty($formData['regenerate_thumbnails']) && $formData['regenerate_thumbnails'] === '1') {
                        error_log('VideoThumbnail: Regenerating thumbnails');
                        $this->dispatchBatchJob(
                            isset($formData['default_frame_position']) ? (int)$formData['default_frame_position'] : 10,
                            isset($formData['supported_formats']) ? $formData['supported_formats'] : ['video/mp4']
                        );
                    }
                    
                    // Set success message and redirect
                    $this->messenger()->addSuccess('Video thumbnail settings updated.');
                    return $this->redirect()->toRoute('admin/video-thumbnail');
                } else {
                    error_log('VideoThumbnail: Form validation failed: ' . json_encode($form->getMessages()));
                    $this->messenger()->addError('There was an error in the form submission.');
                }
            } else {
                error_log('VideoThumbnail: Initial form load, populating with current settings');
                
                // Populate form with current settings
                $formData = [
                    'default_frame_position' => $defaultFrame,
                    'supported_formats' => $supportedFormats,
                    'debug_mode' => $debugMode ? '1' : '0',
                    'regenerate_thumbnails' => '0'
                ];
                
                error_log('VideoThumbnail: Setting form data: ' . json_encode($formData));
                $form->setData($formData);
            }
            
            $view = new ViewModel([
                'form' => $form,
                'videoCount' => $videoCount,
                'defaultFrame' => $defaultFrame,
                'supportedFormats' => $supportedFormats,
                'debugMode' => $debugMode
            ]);
            
            return $view;
        } catch (\Exception $e) {
            // Handle any unexpected errors
            error_log('VideoThumbnail: Error in index action: ' . $e->getMessage());
            $this->messenger()->addError('An error occurred while loading the Video Thumbnail settings: ' . $e->getMessage());
            
            // Return a minimal view
            return new ViewModel([
                'error' => $e->getMessage(),
            ]);
        }
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
            error_log('VideoThumbnail: Error counting videos: ' . $e->getMessage());
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