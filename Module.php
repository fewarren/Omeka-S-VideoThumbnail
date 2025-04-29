<?php
namespace VideoThumbnail;

use Omeka\Module\AbstractModule;
use VideoThumbnail\Form\ConfigForm;
use VideoThumbnail\Media\Ingester\VideoThumbnail as VideoThumbnailIngester;
use VideoThumbnail\Media\Renderer\VideoThumbnail as VideoThumbnailRenderer;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\EventManager\Event;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Entity\Media;
use Omeka\Api\Representation\MediaRepresentation;
use Laminas\Permissions\Acl\Resource\GenericResource;
// Don't use Debug in the main class declaration to avoid early initialization
// use VideoThumbnail\Stdlib\Debug;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;
    
    // Control debug mode at the module level
    private $debugEnabled = false;

    public function getConfig(): array
    {
        try {
            $config = include __DIR__ . '/config/module.config.php';
            return $config;
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Critical error loading module configuration: ' . $e->getMessage());
            return [];
        }
    }

    public function getConfigForm(PhpRenderer $renderer): string
    {
        $services = $this->getServiceLocator();
        $form = $services->get('FormElementManager')->get(ConfigForm::class);
        $form->init();
        
        $settings = $services->get('Omeka\Settings');
        
        // Get current settings
        $formData = [
            'videothumbnail_ffmpeg_path' => $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg'),
            'videothumbnail_frames_count' => $settings->get('videothumbnail_frames_count', 5),
            'videothumbnail_default_frame' => $settings->get('videothumbnail_default_frame', 10),
            'videothumbnail_memory_limit' => $settings->get('videothumbnail_memory_limit', 512),
            'videothumbnail_log_level' => $settings->get('videothumbnail_log_level', 'error'),
            'video_thumbnail_timestamp_property' => $settings->get('video_thumbnail_timestamp_property', ''),
            'videothumbnail_supported_formats' => $settings->get('videothumbnail_supported_formats', [
                'video/mp4',
                'video/webm',
                'video/quicktime',
                'video/x-msvideo',
                'video/x-ms-wmv',
                'video/x-matroska',
                'video/3gpp',
                'video/3gpp2',
                'video/x-flv',
            ]),
            'videothumbnail_debug_mode' => $settings->get('videothumbnail_debug_mode', false),
        ];
        
        $form->setData($formData);
        
        return $renderer->render('video-thumbnail/admin/config-form', [
            'form' => $form,
        ]);
    }

    public function handleConfigForm(AbstractController $controller): bool
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(ConfigForm::class);

        $form->init();
        $postData = $controller->params()->fromPost();
        
        // Log POST data for debugging
        error_log('VideoThumbnail: POST data received: ' . json_encode(array_keys($postData)));
        
        // Find the CSRF element name from the form
        $csrfElement = null;
        foreach ($form->getElements() as $element) {
            if ($element instanceof \Laminas\Form\Element\Csrf) {
                $csrfElement = $element;
                break;
            }
        }
        
        // Check if csrf token is present
        if ($csrfElement) {
            $csrfName = $csrfElement->getName();
            if (!isset($postData[$csrfName])) {
                error_log('VideoThumbnail: CSRF token missing in POST data (element name: ' . $csrfName . ')');
                $controller->messenger()->addError('Security token missing. Please try again.');
                return false;
            }
        } else {
            error_log('VideoThumbnail: No CSRF element found in form');
            $controller->messenger()->addError('Form validation error: security element not found.');
            return false;
        }
        
        $form->setData($postData);
        
        // Check form structure
        try {
            $elements = [];
            foreach ($form->getElements() as $element) {
                $elements[] = $element->getName();
            }
            error_log('VideoThumbnail: Form elements: ' . implode(', ', $elements));
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error getting form elements: ' . $e->getMessage());
        }
        
        // Check validation separately for each element
        $isValid = $form->isValid();
        $messages = $form->getMessages();
        
        if (!empty($messages)) {
            error_log('VideoThumbnail: Form validation failed with messages: ' . json_encode($messages));
            
            // Create a more detailed log if possible
            try {
                $logfile = OMEKA_PATH . '/logs/form_validation_' . time() . '.log';
                file_put_contents($logfile, "Form validation failed\n\n");
                file_put_contents($logfile, "POST data:\n" . print_r($postData, true) . "\n\n", FILE_APPEND);
                file_put_contents($logfile, "Validation messages:\n" . print_r($messages, true) . "\n\n", FILE_APPEND);
                
                // Check each element for specific validation issues
                foreach ($elements as $elementName) {
                    try {
                        $element = $form->get($elementName);
                        $elementMessages = $form->getInputFilter()->get($elementName)->getMessages();
                        if (!empty($elementMessages)) {
                            file_put_contents($logfile, "Element '$elementName' failed with: " . print_r($elementMessages, true) . "\n", FILE_APPEND);
                        }
                    } catch (\Exception $e) {
                        file_put_contents($logfile, "Could not check element '$elementName': " . $e->getMessage() . "\n", FILE_APPEND);
                    }
                }
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Error writing detailed log: ' . $e->getMessage());
            }
        } else {
            error_log('VideoThumbnail: Form validation passed but isValid() returned false');
        }
        
        if (!$isValid) {
            if (method_exists($controller, 'messenger')) {
                $controller->messenger()->addFormErrors($form);
            } elseif (method_exists($controller, 'flashMessenger')) {
                $controller->flashMessenger()->addFormErrors($form);
            } else {
                error_log('VideoThumbnail: Unable to add form errors - no messenger method found');
            }
            return false;
        }

        $formData = $form->getData();

        // Save settings
        $settings->set('videothumbnail_ffmpeg_path', $formData['videothumbnail_ffmpeg_path']);
        $settings->set('videothumbnail_frames_count', $formData['videothumbnail_frames_count']);
        $settings->set('videothumbnail_default_frame', $formData['videothumbnail_default_frame']);
        $settings->set('videothumbnail_memory_limit', $formData['videothumbnail_memory_limit']);
        $settings->set('videothumbnail_log_level', $formData['videothumbnail_log_level']);
        $settings->set('video_thumbnail_timestamp_property', $formData['video_thumbnail_timestamp_property']);
        $settings->set('videothumbnail_supported_formats', $formData['videothumbnail_supported_formats']);
        $settings->set('videothumbnail_debug_mode', !empty($formData['videothumbnail_debug_mode']));

        // Validate FFmpeg path
        $ffmpegPath = $formData['videothumbnail_ffmpeg_path'];
        if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
            $controller->messenger()->addError('FFmpeg path is not executable or not found.');
            return false;
        }

        // Test FFmpeg execution
        try {
            $output = [];
            $returnVar = 0;
            // Use properly quoted command, especially important for Windows paths with spaces
            $command = sprintf('%s -version', escapeshellarg($ffmpegPath));
            exec($command, $output, $returnVar);
            
            if ($returnVar !== 0) {
                $controller->messenger()->addError('FFmpeg could not be executed at the specified path.');
                return false;
            }
        } catch (\Exception $e) {
            $controller->messenger()->addError('Error testing FFmpeg: ' . $e->getMessage());
            return false;
        }

        if (method_exists($controller, 'messenger')) {
            $controller->messenger()->addSuccess('Video Thumbnail settings updated successfully.');
        } elseif (method_exists($controller, 'flashMessenger')) {
            $controller->flashMessenger()->addSuccess('Video Thumbnail settings updated successfully.');
        }
        return true;
    }

    public function getAutoloaderConfig(): array
    {
        return [
            'Laminas\Loader\StandardAutoloader' => [
                'namespaces' => [
                    __NAMESPACE__ => __DIR__ . '/src',
                ],
            ],
        ];
    }

    public function onBootstrap(MvcEvent $event): void
    {
        try {
            // Call parent bootstrap first
            parent::onBootstrap($event);

            // Get the service manager
            $application = $event->getApplication();
            $serviceManager = $application->getServiceManager();
            
            // Force garbage collection to be enabled
            gc_enable();

            // Get module configuration first
            $config = $serviceManager->get('Config');
            $moduleConfig = $config['videothumbnail'] ?? [];
            
            // Set memory limit for module operations if configured
            if (isset($moduleConfig['job_dispatch']['memory_limit'])) {
                ini_set('memory_limit', $moduleConfig['job_dispatch']['memory_limit']);
            }
            
            // Configure garbage collection
            if (isset($moduleConfig['memory_management']['gc_probability'])) {
                ini_set('zend.gc_probability', $moduleConfig['memory_management']['gc_probability']);
            }

            // Get the settings
            $settings = $serviceManager->get('Omeka\Settings');
            
            // Check if debug mode is enabled in settings
            $this->debugEnabled = (bool)$settings->get('videothumbnail_debug_mode', false);
            
            // Only initialize debug system if explicitly enabled
            if ($this->debugEnabled) {
                // Build debug configuration from settings
                $debugConfig = [
                    'enabled' => true,
                    'log_dir' => isset($moduleConfig['settings']['debug_log_dir']) ? $moduleConfig['settings']['debug_log_dir'] : (defined('OMEKA_PATH') ? OMEKA_PATH . '/logs' : null),
                    'log_file' => isset($moduleConfig['settings']['debug_log_file']) ? $moduleConfig['settings']['debug_log_file'] : 'videothumbnail.log',
                    'max_size' => isset($moduleConfig['settings']['debug_max_size']) ? $moduleConfig['settings']['debug_max_size'] : 10485760,
                    'max_files' => isset($moduleConfig['settings']['debug_max_files']) ? $moduleConfig['settings']['debug_max_files'] : 5
                ];
                
                // Try to initialize debug system
                try {
                    \VideoThumbnail\Stdlib\Debug::init($debugConfig);
                } catch (\Exception $e) {
                    error_log('VideoThumbnail: Failed to initialize debug system: ' . $e->getMessage());
                }
            }

            // Register CSS and JS assets
            $this->attachListenersForAssets($event);

            // Add ACL rules
            $this->addAclRules($serviceManager);

        } catch (\Exception $e) {
            // Log critical bootstrap errors
            error_log('VideoThumbnail: Critical Bootstrap error: ' . $e->getMessage());
            error_log($e->getTraceAsString());
        }
    }

    /**
     * Initialize debug mode safely without using Debug class during bootstrap
     */
    protected function initializeDebugMode($serviceManager, $moduleConfig): void
    {
        try {
            // Only initialize if debug is explicitly enabled in settings
            if (!empty($moduleConfig['settings']['debug_mode'])) {
                // Build debug configuration from settings
                $debugConfig = [
                    'enabled' => true,
                    'log_dir' => $moduleConfig['settings']['debug_log_dir'] ?? OMEKA_PATH . '/logs',
                    'log_file' => $moduleConfig['settings']['debug_log_file'] ?? 'videothumbnail.log',
                    'max_size' => $moduleConfig['settings']['debug_max_size'] ?? 10485760,
                    'max_files' => $moduleConfig['settings']['debug_max_files'] ?? 5
                ];
                
                // Ensure log directory exists
                if (!is_dir($debugConfig['log_dir'])) {
                    @mkdir($debugConfig['log_dir'], 0755, true);
                }
                
                if (!is_writable($debugConfig['log_dir'])) {
                    error_log('VideoThumbnail: Debug log directory not writable: ' . $debugConfig['log_dir']);
                    return;
                }

                // Initialize debug configuration
                \VideoThumbnail\Stdlib\Debug::init($debugConfig);
            }
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Failed to initialize debug mode: ' . $e->getMessage());
        }
    }

    /**
     * Get the module version from module.ini
     */
    protected function getModuleVersion(): string
    {
        $path = __DIR__ . '/config/module.ini';
        if (!file_exists($path)) {
            return 'unknown';
        }
        
        $config = parse_ini_file($path);
        return $config['version'] ?? 'unknown';
    }

    /**
     * Register CSS and JS assets
     */
    protected function attachListenersForAssets(MvcEvent $event): void
    {
        $serviceManager = $event->getApplication()->getServiceManager();
        $viewHelperManager = $serviceManager->get('ViewHelperManager');
        $sharedEvents = $serviceManager->get('SharedEventManager');

        // Register assets for all admin routes
        $sharedEvents->attach(
            'Omeka\Controller\Admin',
            'view.layout',
            function ($event) use ($viewHelperManager) {
                $view = $event->getTarget();
                $assetUrl = $viewHelperManager->get('assetUrl');
                $headLink = $viewHelperManager->get('headLink');
                $headScript = $viewHelperManager->get('headScript');
                
                // Always load these assets in admin
                $headLink->appendStylesheet($assetUrl('css/video-thumbnail.css', 'VideoThumbnail'));
                $headScript->appendFile($assetUrl('js/video-thumbnail.js', 'VideoThumbnail'), 'text/javascript', ['defer' => false]);
                
                // Load block admin JS for page edit
                $routeMatch = $event->getRouteMatch();
                if ($routeMatch && 
                    ($routeMatch->getParam('controller') === 'Omeka\Controller\Admin\Page' ||
                     $routeMatch->getParam('__CONTROLLER__') === 'Omeka\Controller\Admin\Page') &&
                    in_array($routeMatch->getParam('action'), ['add', 'edit'])) {
                    
                    $headScript->appendFile($assetUrl('js/video-thumbnail-block-admin.js', 'VideoThumbnail'), 'text/javascript', ['defer' => false]);
                }
            }
        );

        // Specifically for video thumbnail admin pages
        $sharedEvents->attach(
            'VideoThumbnail\Controller\Admin\VideoThumbnailController',
            'view.layout',
            function ($event) use ($viewHelperManager) {
                $view = $event->getTarget();
                $assetUrl = $viewHelperManager->get('assetUrl');
                $headLink = $viewHelperManager->get('headLink');
                $headScript = $viewHelperManager->get('headScript');
                
                $headLink->appendStylesheet($assetUrl('css/video-thumbnail-monitor.css', 'VideoThumbnail'));
                $headScript->appendFile($assetUrl('js/video-thumbnail-monitor.js', 'VideoThumbnail'), 'text/javascript', ['defer' => false]);
            }
        );
    }

    public function install(ServiceLocatorInterface $serviceLocator): void
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        
        // Set default settings with debug mode DISABLED by default
        $defaults = [
            'videothumbnail_ffmpeg_path' => '', // Set blank default, require user config
            'videothumbnail_default_frame' => 10,
            'videothumbnail_frames_count' => 5,
            'videothumbnail_memory_limit' => 512,
            'videothumbnail_process_timeout' => 3600,
            'videothumbnail_debug_mode' => false,  // Default to false
            'videothumbnail_supported_formats' => [
                'video/mp4',
                'video/quicktime',
                'video/x-msvideo',
                'video/x-ms-wmv',
                'video/x-matroska',
                'video/webm',
                'video/3gpp',
                'video/3gpp2',
                'video/x-flv'
            ]
        ];

        foreach ($defaults as $key => $value) {
            $settings->set($key, $value);
        }

        // Try to auto-detect FFmpeg
        $ffmpegPath = $this->detectFfmpegPath();
        if ($ffmpegPath) {
            $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
        }

        $this->createRequiredDirectories();
    }

    protected function detectFfmpegPath()
    {
        $possiblePaths = [
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/local/bin/ffmpeg',
            '/opt/homebrew/bin/ffmpeg',

            'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe',
            'C:\\Program Files (x86)\\ffmpeg\\bin\\ffmpeg.exe'
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try to detect using which command on Unix-like systems
        if (function_exists('exec')) {
            $output = [];
            $returnVar = null;
            exec('which ffmpeg 2>/dev/null', $output, $returnVar);
            if ($returnVar === 0 && !empty($output)) {
                return trim($output[0]);
            }
        }

        return '';
    }

    protected function createRequiredDirectories()
    {
        $directories = [
            OMEKA_PATH . '/files/temp/video-thumbnails',
            OMEKA_PATH . '/logs'
        ];

        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator): void
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        
        // Remove all module settings
        $settings->delete('videothumbnail_ffmpeg_path');
        $settings->delete('videothumbnail_default_frame');
        $settings->delete('videothumbnail_frames_count');
        $settings->delete('videothumbnail_memory_limit');
        $settings->delete('videothumbnail_process_timeout');
        $settings->delete('videothumbnail_debug_mode');
        $settings->delete('videothumbnail_log_level');
        $settings->delete('videothumbnail_supported_formats');

        // Clean up temporary directories
        $this->cleanupTempDirectories();
    }

    protected function cleanupTempDirectories()
    {
        $directories = [
            OMEKA_PATH . '/files/temp/video-thumbnails'
        ];

        foreach ($directories as $dir) {
            if (file_exists($dir)) {
                $this->recursiveRemoveDirectory($dir);
            }
        }
    }

    protected function recursiveRemoveDirectory($directory): void
    {
        if (is_dir($directory)) {
            $objects = scandir($directory);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    $path = $directory . DIRECTORY_SEPARATOR . $object;
                    is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /**
     * Attach listeners for Omeka events.
     * This method is called automatically by Omeka S.
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Handle media events directly in the module class
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.create.post',
            [$this, 'handleMediaIngestion']
        );

        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.update.post',
            [$this, 'handleMediaUpdatePost']
        );

        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.edit.form.after',
            [$this, 'handleViewEditFormAfter']
        );

        // Listener for adding config form to module page (if needed, depends on Omeka version/theme)
        // This might be redundant if using standard config handling
        // $sharedEventManager->attach(
        //     'Omeka\Controller\Admin\Module',
        //     'view.details', // Check if this event is still correct/needed
        //     [$this, 'handleViewDetails']
        // );
        // Use basic error_log instead of Debug to avoid possible issues
        error_log('VideoThumbnail: Core event listeners attached');
    }

    /**
     * Handle media ingestion events
     */
    public function handleMediaIngestion($event): void
    {
        $response = $event->getParam('response');
        if (!$response) {
            return;
        }

        $media = $response->getContent();
        if (!$this->isVideoMedia($media)) {
            return;
        }

        if ($this->debugEnabled) {
            // Only try to use Debug if explicitly enabled
            try {
                \VideoThumbnail\Stdlib\Debug::log('Media ingestion detected for media ID: ' . $media->id(), __METHOD__);
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Debug log failed: ' . $e->getMessage());
            }
        }
    }

    public function handleViewEditFormAfter($event): void
    {
        $view = $event->getTarget();
        $media = $view->media;

        if (!$media || !$this->isVideoMedia($media)) {
            return;
        }

        echo $view->videoThumbnailSelector($media);
    }

    public function handleMediaUpdatePost($event): void
    {
        $request = $event->getParam('request');
        $media = $event->getParam('response')->getContent();

        if (!$this->isVideoMedia($media)) {
            return;
        }

        $data = $request->getContent();
        if (isset($data['videothumbnail_frame'])) {
            $this->updateVideoThumbnail($media, $data['videothumbnail_frame']);
        }
    }

    protected function isVideoMedia($media): bool
    {
        $mediaType = $media instanceof Media ? $media->getMediaType() : ($media instanceof MediaRepresentation ? $media->mediaType() : null);
        return $mediaType && strpos($mediaType, 'video/') === 0;
    }

    protected function updateVideoThumbnail($media, $selectedFrame): void
    {
        try {
            $serviceLocator = $this->getServiceLocator();
            $settings = $serviceLocator->get('Omeka\Settings');
            // Get the VideoFrameExtractor service from service manager instead of instantiating directly
            $extractor = null;
            try {
                if ($serviceLocator->has('VideoThumbnail\Stdlib\VideoFrameExtractor')) {
                    $extractor = $serviceLocator->get('VideoThumbnail\Stdlib\VideoFrameExtractor');
                } else {
                    // Fallback to direct instantiation only if service is not available
                    $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path');
                    error_log('VideoThumbnail: Service not found, creating extractor directly');
                    $extractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
                }
            } catch (\Exception $e) {
                // Final fallback
                $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path');
                error_log('VideoThumbnail: Error getting extractor service: ' . $e->getMessage());
                $extractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
            }
            $fileStore = $serviceLocator->get('Omeka\File\Store');
            if ($media instanceof MediaRepresentation) {
                // For MediaRepresentation objects
                $filename = $media->filename();
            } else {
                // For Media entity objects
                $filename = $media->getFilename();
            }
            $storagePath = sprintf('original/%s', $filename);
            $filePath = $fileStore->getLocalPath($storagePath);
            $mediaId = $media instanceof MediaRepresentation ? $media->id() : $media->getId();

            $duration = $extractor->getVideoDuration($filePath);
            $frameTime = ($duration * $selectedFrame) / 100;
            $extractedFrame = $extractor->extractFrame($filePath, $frameTime);

            if ($extractedFrame) {
                $tempManager = $serviceLocator->get('Omeka\File\TempFileFactory');
                $tempFile = $tempManager->build();
                $tempFile->setSourceName('thumbnail.jpg');
                $tempFile->setTempPath($extractedFrame);

                $entityManager = $serviceLocator->get('Omeka\EntityManager');
                $mediaEntity = $entityManager->find('Omeka\Entity\Media', $mediaId);

                $fileManager = $serviceLocator->get('Omeka\File\Manager');
                $fileManager->storeThumbnails($tempFile, $mediaEntity);
                $entityManager->flush();

                unlink($tempFile->getTempPath());
            }
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error updating thumbnail: ' . $e->getMessage());
            
            if ($this->debugEnabled) {
                try {
                    \VideoThumbnail\Stdlib\Debug::logError('Error updating thumbnail: ' . $e->getMessage(), __METHOD__, $e);
                } catch (\Exception $debugException) {
                    // Ignore debug errors
                }
            }
        }
    }
    
    /**
     * Add ACL rules for this module
     */
    protected function addAclRules($serviceManager): void
    {
        try {
            /** @var \Laminas\Permissions\Acl\Acl $acl */
            $acl = $serviceManager->get('Omeka\\Acl');

            // Define resources
            $controllerResource = 'VideoThumbnail\Controller\Admin\VideoThumbnailController';
            
            // Ensure controller resource exists
            if (!$acl->hasResource($controllerResource)) {
                $acl->addResource(new GenericResource($controllerResource));
            }
            
            // Allow editor and above roles access (not null/anonymous)
            $roles = ['editor', 'site_admin', 'global_admin'];
            foreach ($roles as $role) {
                if ($acl->hasRole($role)) {
                    $acl->allow($role, $controllerResource);
                }
            }
        } catch (\Exception $e) {
            // Fail silently - don't break the site for ACL issues
            error_log('VideoThumbnail: ACL setup error: ' . $e->getMessage());
        }
    }
}

