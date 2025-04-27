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
use VideoThumbnail\Stdlib\Debug; // Add this use statement

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

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
        try {
            $services = $this->getServiceLocator();
            
            // Get form from FormElementManager
            try {
                $form = $services->get('FormElementManager')->get(ConfigForm::class);
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Failed to get ConfigForm: ' . $e->getMessage());
                return '<p class="error">Error loading configuration form.</p>';
            }

            // Get settings
            $settings = $services->get('Omeka\Settings');
            $form->init();
            
            // Get current settings values
            $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
            $framesCount = $settings->get('videothumbnail_frames_count', 5);
            $defaultFrame = $settings->get('videothumbnail_default_frame', 10);
            $debugMode = $settings->get('videothumbnail_debug_mode', false);
            $memoryLimit = $settings->get('videothumbnail_memory_limit', 512);
            $logLevel = $settings->get('videothumbnail_log_level', 'error');
            $timestampProperty = $settings->get('video_thumbnail_timestamp_property', '');
            
            // Get supported formats from settings or fallback to defaults
            $supportedFormats = $settings->get('videothumbnail_supported_formats', [
                'video/mp4',
                'video/webm',
                'video/quicktime',
                'video/x-msvideo',
            ]);
            
            // Prepare form data
            $formData = [
                'videothumbnail_ffmpeg_path' => $ffmpegPath,
                'videothumbnail_frames_count' => $framesCount,
                'videothumbnail_default_frame' => $defaultFrame,
                'videothumbnail_debug_mode' => $debugMode,
                'videothumbnail_memory_limit' => $memoryLimit,
                'videothumbnail_log_level' => $logLevel,
                'videothumbnail_supported_formats' => $supportedFormats,
                'video_thumbnail_timestamp_property' => $timestampProperty,
            ];
            
            $form->setData($formData);

            // Render form
            try {
                return $renderer->render('video-thumbnail/admin/config-form', [
                    'form' => $form,
                ]);
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Error rendering config form: ' . $e->getMessage());
                return '<p class="error">Error rendering configuration form.</p>';
            }
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Exception in getConfigForm: ' . $e->getMessage());
            return '<p class="error">An error occurred while loading the configuration form.</p>';
        }
    }

    public function handleConfigForm(AbstractController $controller): bool
    {
        try {
            $services = $this->getServiceLocator();
            $settings = $services->get('Omeka\Settings');
            
            // Get form
            try {
                $form = $services->get('FormElementManager')->get(ConfigForm::class);
            } catch (\Exception $e) {
                error_log('VideoThumbnail: Failed to get ConfigForm: ' . $e->getMessage());
                $controller->flashMessenger()->addError('Error initializing configuration form.');
                return false;
            }

            $form->init();
            $postData = $controller->params()->fromPost();
            
            $form->setData($postData);
            
            if (!$form->isValid()) {
                $controller->flashMessenger()->addFormErrors($form);
                return false;
            }

            $formData = $form->getData();

            // Validate frame count and default frame
            if ((int)$formData['videothumbnail_frames_count'] <= 0 || (int)$formData['videothumbnail_default_frame'] < 0) {
                $controller->flashMessenger()->addError('Frame count and default frame must be non-negative integers.');
                return false;
            }

            // Save form data to settings
            $settings->set('videothumbnail_ffmpeg_path', $formData['videothumbnail_ffmpeg_path']);
            $settings->set('videothumbnail_frames_count', (int)$formData['videothumbnail_frames_count']);
            $settings->set('videothumbnail_default_frame', (int)$formData['videothumbnail_default_frame']);
            $settings->set('videothumbnail_debug_mode', isset($formData['videothumbnail_debug_mode']) ? (bool)$formData['videothumbnail_debug_mode'] : false);
            
            if (isset($formData['videothumbnail_memory_limit'])) {
                $settings->set('videothumbnail_memory_limit', (int)$formData['videothumbnail_memory_limit']);
            }
            
            if (isset($formData['videothumbnail_log_level'])) {
                $settings->set('videothumbnail_log_level', $formData['videothumbnail_log_level']);
            }
            
            if (isset($formData['video_thumbnail_timestamp_property'])) {
                $settings->set('video_thumbnail_timestamp_property', $formData['video_thumbnail_timestamp_property']);
            }
            
            if (isset($formData['videothumbnail_supported_formats']) && is_array($formData['videothumbnail_supported_formats'])) {
                $settings->set('videothumbnail_supported_formats', $formData['videothumbnail_supported_formats']);
            }

            // Validate FFmpeg path
            $ffmpegPath = $formData['videothumbnail_ffmpeg_path'];
            if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
                $controller->flashMessenger()->addError('FFmpeg path is not executable or not found.');
                return false;
            }

            // Test FFmpeg execution
            try {
                $output = [];
                $returnVar = 0;
                $command = escapeshellcmd($ffmpegPath) . ' -version';
                exec($command, $output, $returnVar);
                
                if ($returnVar !== 0) {
                    $controller->flashMessenger()->addError('FFmpeg could not be executed at the specified path.');
                    return false;
                }
            } catch (\Exception $e) {
                $controller->flashMessenger()->addError('Error testing FFmpeg: ' . $e->getMessage());
                return false;
            }

            $controller->flashMessenger()->addSuccess('Video Thumbnail settings updated successfully.');
            return true;
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Exception in handleConfigForm: ' . $e->getMessage());
            $controller->flashMessenger()->addError('An unexpected error occurred while saving settings.');
            return false;
        }
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
            // Call parent bootstrap which handles basic initialization
            parent::onBootstrap($event);
            
            // Get the service manager
            $application = $event->getApplication();
            $serviceManager = $application->getServiceManager();
            
            // Critical: Explicitly register our controller with the ControllerManager
            $controllerManager = $serviceManager->get('ControllerManager');
            if (!$controllerManager->has('VideoThumbnail\Controller\Admin\VideoThumbnailController')) {
                // Create controller factory
                $factory = new \VideoThumbnail\Service\Controller\VideoThumbnailControllerFactory();
                
                // Register controller with the manager
                $controller = $factory($serviceManager, 'VideoThumbnail\Controller\Admin\VideoThumbnailController');
                $controllerManager->setService('VideoThumbnail\Controller\Admin\VideoThumbnailController', $controller);
                
                // Also register the alias
                if (!$controllerManager->has('VideoThumbnail\Controller\Admin\VideoThumbnail')) {
                    $controllerManager->setAlias(
                        'VideoThumbnail\Controller\Admin\VideoThumbnail', 
                        'VideoThumbnail\Controller\Admin\VideoThumbnailController'
                    );
                }
            }
            
            // Register view helper
            $viewHelperManager = $serviceManager->get('ViewHelperManager');
            $viewHelperManager->setAlias('videoThumbnailSelector', 'VideoThumbnail\View\Helper\VideoThumbnailSelector');
            
            // Register minimal ACL rules - needed for controller access
            $this->addAclRules($serviceManager);
            
            // That's all we need for admin to work
            // Don't initialize anything else to avoid hanging
        } catch (\Exception $e) {
            // Log only critical errors
            error_log('VideoThumbnail: Bootstrap error: ' . $e->getMessage());
        }
    }
    
    /**
     * This method is called only when actually needed via event listeners
     * instead of during bootstrap, preventing hangs
     */
    public function initializeModule($serviceManager = null)
    {
        if (!$serviceManager) {
            $serviceManager = $this->getServiceLocator();
        }
        
        try {
            // Only register view helpers - no debug initialization
            $viewHelperManager = $serviceManager->get('ViewHelperManager');
            $viewHelperManager->setAlias('videoThumbnailSelector', 'VideoThumbnail\View\Helper\VideoThumbnailSelector');
            
            // Ensure controller is registered
            $controllerManager = $serviceManager->get('ControllerManager');
            if (!$controllerManager->has('VideoThumbnail\Controller\Admin\VideoThumbnailController')) {
                $factory = new \VideoThumbnail\Service\Controller\VideoThumbnailControllerFactory();
                $controller = $factory($serviceManager, 'VideoThumbnail\Controller\Admin\VideoThumbnailController');
                $controllerManager->setService('VideoThumbnail\Controller\Admin\VideoThumbnailController', $controller);
            }
            
            // Register essential event listeners only
            if ($serviceManager->has('EventManager')) {
                $this->registerMinimalListeners($serviceManager->get('EventManager'));
            }
        } catch (\Exception $e) {
            // Log error but don't rethrow - must not break Omeka S
            error_log('VideoThumbnail: Module initialization failure: ' . $e->getMessage());
        }
    }

    protected function initializeMinimal($serviceManager)
    {
        // Do not initialize debug by default - only enable when explicitly enabled in admin
        try {
            $settings = $serviceManager->get('Omeka\\Settings');
            $debugEnabled = (bool)$settings->get('videothumbnail_debug_mode', false);
            
            // Only initialize debugging if explicitly enabled
            if ($debugEnabled) {
                $config = [
                    'enabled' => true,
                    'log_dir' => OMEKA_PATH . DIRECTORY_SEPARATOR . 'logs',
                    'log_file' => 'videothumbnail.log',
                    'max_size' => 10485760, // 10MB
                    'max_files' => 5
                ];
                
                // Initialize debug with minimum logging
                \VideoThumbnail\Stdlib\Debug::init($config);
            }
        } catch (\Exception $e) {
            // Fail silently - don't let debug initialization crash the site
        }
    }
    
    /**
     * Register only essential event listeners to prevent performance issues
     */
    protected function registerMinimalListeners($eventManager)
    {
        $sharedEventManager = $eventManager->getSharedManager();
        
        // Only attach the essential listeners for thumbnail generation
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.update.post',
            [$this, 'handleUpdateMedia']
        );
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
                    
                    \VideoThumbnail\Stdlib\Debug::log('Loading block admin JS for page edit', __METHOD__);
                    $headScript->appendFile($assetUrl('js/video-thumbnail-block-admin.js', 'VideoThumbnail'), 'text/javascript', ['defer' => false]);
                }
            }
        );

        // Specifically for video thumbnail admin pages
        $sharedEvents->attach(
            'VideoThumbnail\Controller\Admin\VideoThumbnailController',
            'view.layout',
            function ($event) use ($viewHelperManager) {
                \VideoThumbnail\Stdlib\Debug::log('Controller-specific asset loading triggered', __METHOD__);
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
        
        // IMPORTANT: Controller events for modules are now attached here
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'view.details',
            [$this, 'handleViewDetails']
        );
    }
    
    /**
     * Handle view.details event for the module's config page in admin
     */
    public function handleViewDetails(Event $event): void
    {
        $view = $event->getTarget();
        $module = $event->getParam('module');
        
        if ($module->getId() != __NAMESPACE__) {
            return;
        }
        
        $view->moduleName = 'Video Thumbnail';
        $view->configForm = $this->getConfigForm($view->getHelperPluginManager()->get('Laminas\View\Renderer\PhpRenderer'));
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

        \VideoThumbnail\Stdlib\Debug::log('Media ingestion detected for media ID: ' . $media->id(), __METHOD__);
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

    public function addAdminWarning($event): void
    {
        $view = $event->getTarget();
        $serviceLocator = $this->getServiceLocator();
        $viewHelpers = $serviceLocator->get('ViewHelperManager');
        $url = $viewHelpers->get('url');

        $message = sprintf(
            'You can %s to regenerate all video thumbnails.',
            sprintf(
                '<a href="%s">add a new job</a>',
                $url('admin/id', ['controller' => 'job', 'action' => 'add', 'id' => 'VideoThumbnail\\Job\\ExtractFrames'])
            )
        );

        $flashMessenger = $viewHelpers->get('flashMessenger');
        $flashMessenger->addSuccess($message);
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
            $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path');

            $extractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
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
            \VideoThumbnail\Stdlib\Debug::logError('Error updating thumbnail: ' . $e->getMessage(), __METHOD__, $e);
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

