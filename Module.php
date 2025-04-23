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
            Debug::log('Starting config form generation', __METHOD__);
            $services = $this->getServiceLocator();
            $config = $services->get('Config');
            
            Debug::log('Getting ConfigForm from FormElementManager', __METHOD__);
            try {
                $form = $services->get('FormElementManager')->get(ConfigForm::class);
                Debug::log('Successfully retrieved ConfigForm instance', __METHOD__);
            } catch (\Exception $e) {
                Debug::logError('Failed to get ConfigForm from FormElementManager: ' . $e->getMessage(), __METHOD__, $e);
                throw $e;
            }

            $settings = $services->get('Omeka\Settings');
            Debug::log('Initializing form and setting data', __METHOD__);
            $form->init();
            
            // Get current settings values with detailed logging
            $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
            $framesCount = $settings->get('videothumbnail_frames_count', 5);
            $defaultFrame = $settings->get('videothumbnail_default_frame', 10);
            $debugMode = $settings->get('videothumbnail_debug_mode', false);
            $memoryLimit = $settings->get('videothumbnail_memory_limit', 512);
            $logLevel = $settings->get('videothumbnail_log_level', 'error');
            $timestampProperty = $settings->get('video_thumbnail_timestamp_property', '');
            
            Debug::log(sprintf(
                'Current settings values: ffmpeg_path="%s", frames_count=%d, default_frame=%d, debug_mode=%s, memory_limit=%d, log_level="%s"',
                $ffmpegPath,
                $framesCount,
                $defaultFrame,
                $debugMode ? 'true' : 'false',
                $memoryLimit,
                $logLevel
            ), __METHOD__);

            // Get supported formats from settings or fallback to defaults
            $supportedFormats = $settings->get('videothumbnail_supported_formats', [
                'video/mp4',
                'video/webm',
                'video/quicktime',
                'video/x-msvideo',
            ]);
            
            Debug::log('Setting form data', __METHOD__);
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
            Debug::log('Form data set successfully', __METHOD__);

            Debug::log('Rendering config form template', __METHOD__);
            try {
                $html = $renderer->render('video-thumbnail/admin/config-form', [
                    'form' => $form,
                ]);
                Debug::log('Config form rendered successfully', __METHOD__);
                return $html;
            } catch (\Exception $e) {
                Debug::logError('Error rendering config form template: ' . $e->getMessage(), __METHOD__, $e);
                throw $e;
            }
        } catch (\Exception $e) {
            Debug::logError('Unhandled exception in getConfigForm: ' . $e->getMessage(), __METHOD__, $e);
            return '<p class="error">An error occurred while loading the configuration form. Check logs for details.</p>';
        }
    }

    public function handleConfigForm(AbstractController $controller): bool
    {
        Debug::log('Starting config form handling', __METHOD__);
        try {
            $services = $this->getServiceLocator();
            $settings = $services->get('Omeka\Settings');
            
            Debug::log('Getting ConfigForm from FormElementManager', __METHOD__);
            try {
                $form = $services->get('FormElementManager')->get(ConfigForm::class);
                Debug::log('Successfully retrieved ConfigForm instance', __METHOD__);
            } catch (\Exception $e) {
                Debug::logError('Failed to get ConfigForm from FormElementManager: ' . $e->getMessage(), __METHOD__, $e);
                $controller->flashMessenger()->addError('Error instantiating configuration form. Check logs for details.');
                return false;
            }

            $form->init();
            $postData = $controller->params()->fromPost();
            Debug::log('Form POST data received: ' . json_encode(array_keys($postData)), __METHOD__);
            
            $form->setData($postData);
            
            if (!$form->isValid()) {
                $messages = $form->getMessages();
                Debug::logError('Form validation failed: ' . json_encode($messages), __METHOD__);
                $controller->flashMessenger()->addFormErrors($form);
                return false;
            }
            Debug::log('Form validation succeeded', __METHOD__);

            $formData = $form->getData();
            Debug::log('Processing form data', __METHOD__);

            // Validate frame count and default frame
            if ((int)$formData['videothumbnail_frames_count'] <= 0 || (int)$formData['videothumbnail_default_frame'] < 0) {
                $error = 'Frame count and default frame must be non-negative integers.';
                Debug::logError($error, __METHOD__);
                $controller->flashMessenger()->addError($error);
                return false;
            }

            // Save all form settings with debug logging
            Debug::log('Saving settings to database', __METHOD__);
            
            // Handle FFmpeg path
            $ffmpegPath = $formData['videothumbnail_ffmpeg_path'];
            Debug::log('Setting FFmpeg path: ' . $ffmpegPath, __METHOD__);
            $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
            
            // Handle frames count
            $framesCount = (int)$formData['videothumbnail_frames_count'];
            Debug::log('Setting frames count: ' . $framesCount, __METHOD__);
            $settings->set('videothumbnail_frames_count', $framesCount);
            
            // Handle default frame
            $defaultFrame = (int)$formData['videothumbnail_default_frame'];
            Debug::log('Setting default frame: ' . $defaultFrame, __METHOD__);
            $settings->set('videothumbnail_default_frame', $defaultFrame);
            
            // Handle debug mode with proper boolean conversion
            $debugMode = isset($formData['videothumbnail_debug_mode']) ? (bool)$formData['videothumbnail_debug_mode'] : false;
            Debug::log('Setting debug mode: ' . ($debugMode ? 'enabled' : 'disabled'), __METHOD__);
            $settings->set('videothumbnail_debug_mode', $debugMode);
            
            // Handle memory limit
            if (isset($formData['videothumbnail_memory_limit'])) {
                $memoryLimit = (int)$formData['videothumbnail_memory_limit'];
                Debug::log('Setting memory limit: ' . $memoryLimit . 'MB', __METHOD__);
                $settings->set('videothumbnail_memory_limit', $memoryLimit);
            }
            
            // Handle log level
            if (isset($formData['videothumbnail_log_level'])) {
                $logLevel = $formData['videothumbnail_log_level'];
                Debug::log('Setting log level: ' . $logLevel, __METHOD__);
                $settings->set('videothumbnail_log_level', $logLevel);
            }
            
            // Handle timestamp property
            if (isset($formData['video_thumbnail_timestamp_property'])) {
                $timestampProperty = $formData['video_thumbnail_timestamp_property'];
                Debug::log('Setting timestamp property: ' . $timestampProperty, __METHOD__);
                $settings->set('video_thumbnail_timestamp_property', $timestampProperty);
            }
            
            // Handle supported formats 
            if (isset($formData['videothumbnail_supported_formats']) && is_array($formData['videothumbnail_supported_formats'])) {
                $supportedFormats = $formData['videothumbnail_supported_formats'];
                Debug::log('Setting supported formats: ' . json_encode($supportedFormats), __METHOD__);
                $settings->set('videothumbnail_supported_formats', $supportedFormats);
            }

            // Validate FFmpeg path
            if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
                $error = 'FFmpeg path is not executable or not found.';
                Debug::logError($error . ' Path: ' . $ffmpegPath, __METHOD__);
                $controller->flashMessenger()->addError($error);
                return false;
            }

            // Test FFmpeg execution
            try {
                Debug::log('Testing FFmpeg execution', __METHOD__);
                $output = [];
                $returnVar = 0;
                $command = escapeshellcmd($ffmpegPath) . ' -version';
                Debug::log('Executing command: ' . $command, __METHOD__);
                exec($command, $output, $returnVar);
                
                if ($returnVar !== 0) {
                    $error = 'FFmpeg could not be executed at the specified path.';
                    Debug::logError($error . ' Return code: ' . $returnVar, __METHOD__);
                    $controller->flashMessenger()->addError($error);
                    return false;
                }
                
                Debug::log('FFmpeg execution successful. Version info: ' . substr(implode("\n", $output), 0, 100) . '...', __METHOD__);
            } catch (\Exception $e) {
                Debug::logError('Exception testing FFmpeg: ' . $e->getMessage(), __METHOD__, $e);
                $controller->flashMessenger()->addError('Error testing FFmpeg: ' . $e->getMessage());
                return false;
            }

            // Update debug configuration in the Debug class
            try {
                Debug::log('Updating Debug configuration with new settings', __METHOD__);
                $debugConfig = [
                    'enabled' => $debugMode,
                    'log_dir' => OMEKA_PATH . DIRECTORY_SEPARATOR . 'logs',
                    'log_file' => 'videothumbnail.log',
                    'max_size' => 10485760,
                    'max_files' => 5
                ];
                Debug::init($debugConfig);
                Debug::log('Debug configuration updated successfully', __METHOD__);
            } catch (\Exception $e) {
                Debug::logError('Failed to update Debug configuration: ' . $e->getMessage(), __METHOD__, $e);
            }

            Debug::log('Config form handled successfully', __METHOD__);
            return true;
        } catch (\Exception $e) {
            Debug::logError('Unhandled exception in handleConfigForm: ' . $e->getMessage(), __METHOD__, $e);
            $controller->flashMessenger()->addError('An unexpected error occurred while saving settings. Check logs for details.');
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
            // Start with emergency error logging in case Debug system is not available yet
            error_log('VideoThumbnail: Starting module bootstrap');
            
            parent::onBootstrap($event);
            $application = $event->getApplication();
            $serviceManager = $application->getServiceManager();
            
            // Initialize debug mode first so we can use it for logging
            $this->initializeDebugMode($serviceManager);
            
            \VideoThumbnail\Stdlib\Debug::log('Entering onBootstrap...', __METHOD__);
            \VideoThumbnail\Stdlib\Debug::log('PHP version: ' . phpversion(), __METHOD__);
            \VideoThumbnail\Stdlib\Debug::log('Module version: ' . $this->getModuleVersion(), __METHOD__);
            
            try {
                $viewHelperManager = $serviceManager->get('ViewHelperManager');
                $viewHelperManager->setAlias('videoThumbnailSelector', 'VideoThumbnail\View\Helper\VideoThumbnailSelector');
                \VideoThumbnail\Stdlib\Debug::log('ViewHelper alias registered successfully', __METHOD__);
            } catch (\Exception $e) {
                \VideoThumbnail\Stdlib\Debug::logError('Failed to register ViewHelper alias: ' . $e->getMessage(), __METHOD__, $e);
            }
            
            // Register CSS and JS assets
            try {
                $this->attachListenersForAssets($event);
                \VideoThumbnail\Stdlib\Debug::log('Asset listeners attached successfully', __METHOD__);
            } catch (\Exception $e) {
                \VideoThumbnail\Stdlib\Debug::logError('Failed to attach asset listeners: ' . $e->getMessage(), __METHOD__, $e);
            }
            
            // Add ACL rules
            try {
                $this->addAclRules($serviceManager);
                \VideoThumbnail\Stdlib\Debug::log('ACL rules added successfully', __METHOD__);
            } catch (\Exception $e) {
                \VideoThumbnail\Stdlib\Debug::logError('Failed to add ACL rules: ' . $e->getMessage(), __METHOD__, $e);
            }
            
            // Register event listeners
            try {
                $this->registerListeners($serviceManager->get('EventManager'));
                \VideoThumbnail\Stdlib\Debug::log('Event listeners registered successfully', __METHOD__);
            } catch (\Exception $e) {
                \VideoThumbnail\Stdlib\Debug::logError('Failed to register event listeners: ' . $e->getMessage(), __METHOD__, $e);
            }
            
            \VideoThumbnail\Stdlib\Debug::log('Exiting onBootstrap.', __METHOD__);
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Critical bootstrap failure: ' . $e->getMessage());
            error_log('VideoThumbnail: ' . $e->getTraceAsString());
        }
    }

    protected function initializeDebugMode($serviceManager)
    {
        $settings = $serviceManager->get('Omeka\\Settings');
        $config = $serviceManager->get('Config');
        
        // Use the module config debug setting as default (true)
        $debugEnabled = $settings->get('videothumbnail_debug_mode', $config['videothumbnail']['debug']['enabled']);

        $config = [
            'enabled' => $debugEnabled,
            'log_dir' => OMEKA_PATH . DIRECTORY_SEPARATOR . 'logs',
            'log_file' => 'videothumbnail.log',
            'max_size' => 10485760, // 10MB
            'max_files' => 5
        ];

        \VideoThumbnail\Stdlib\Debug::init($config);
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
        
        // Set default settings with debug mode enabled by default
        $defaults = [
            'videothumbnail_ffmpeg_path' => '', // Set blank default, require user config
            'videothumbnail_default_frame' => 10,
            'videothumbnail_frames_count' => 5,
            'videothumbnail_memory_limit' => 512,
            'videothumbnail_process_timeout' => 3600,
            'videothumbnail_debug_mode' => true,  // Default to true
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
        
        // Remove Debug initialization to prevent hanging
        // $serviceLocator = $this->getServiceLocator();
        // $settings = $serviceLocator->get('Omeka\Settings');
        // \VideoThumbnail\Stdlib\Debug::init($settings); // Keep this commented out
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
            $moduleAdapterResource = 'Omeka\\Api\\Adapter\\ModuleAdapter';

            // Ensure controller resource exists
            if (!$acl->hasResource($controllerResource)) {
                $acl->addResource(new GenericResource($controllerResource));
                \VideoThumbnail\Stdlib\Debug::log("Added ACL resource: $controllerResource", __METHOD__);
            }
            
            // Grant broad access to the controller resource using null role
            $acl->allow(null, $controllerResource);
            \VideoThumbnail\Stdlib\Debug::log("Granted broad ACL access (null role) to resource: $controllerResource", __METHOD__);

            // Ensure ModuleAdapter resource exists (should always be true)
            if (!$acl->hasResource($moduleAdapterResource)) {
                \VideoThumbnail\Stdlib\Debug::logWarning("WARNING - ACL resource $moduleAdapterResource not found. Adding it.", __METHOD__);
                $acl->addResource(new GenericResource($moduleAdapterResource));
            }

            // Grant broad access to the ModuleAdapter resource using null role
            $acl->allow(null, $moduleAdapterResource);
            \VideoThumbnail\Stdlib\Debug::log("Granted broad ACL access (null role) to resource: $moduleAdapterResource", __METHOD__);

            \VideoThumbnail\Stdlib\Debug::log('ACL rules processing completed using broad access.', __METHOD__);

        } catch (\Laminas\Permissions\Acl\Exception\ExceptionInterface $e) {
            \VideoThumbnail\Stdlib\Debug::logError('ACL Configuration Error: ' . $e->getMessage(), __METHOD__, $e);
        } catch (\Exception $e) {
            \VideoThumbnail\Stdlib\Debug::logError('General Error during ACL setup: ' . $e->getMessage(), __METHOD__, $e);
        }
    }
}

