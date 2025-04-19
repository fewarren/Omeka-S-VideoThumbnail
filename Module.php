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

class Module extends AbstractModule
{
    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getConfigForm(PhpRenderer $renderer): string
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $form = $services->get('FormElementManager')->get(ConfigForm::class);

        $settings = $services->get('Omeka\Settings');
        $form->init();
        $form->setData([
            'videothumbnail_ffmpeg_path' => $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg'),
            'videothumbnail_frames_count' => $settings->get('videothumbnail_frames_count', 5),
            'videothumbnail_default_frame' => $settings->get('videothumbnail_default_frame', 10),
            'videothumbnail_debug_mode' => $settings->get('videothumbnail_debug_mode', false),
        ]);

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
        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->flashMessenger()->addErrors($form->getMessages());
            return false;
        }

        $formData = $form->getData();

        if ((int)$formData['videothumbnail_frames_count'] <= 0 || (int)$formData['videothumbnail_default_frame'] < 0) {
            $controller->flashMessenger()->addError('Frame count and default frame must be non-negative integers.');
            return false;
        }

        $settings->set('videothumbnail_ffmpeg_path', $formData['videothumbnail_ffmpeg_path']);
        $settings->set('videothumbnail_frames_count', $formData['videothumbnail_frames_count']);
        $settings->set('videothumbnail_default_frame', $formData['videothumbnail_default_frame']);
        $settings->set('videothumbnail_debug_mode', isset($formData['videothumbnail_debug_mode']) ? $formData['videothumbnail_debug_mode'] : false);

        $ffmpegPath = $formData['videothumbnail_ffmpeg_path'];
        if (!is_executable($ffmpegPath)) {
            $controller->flashMessenger()->addError('FFmpeg path is not executable or not found.');
            return false;
        }

        $output = [];
        $returnVar = 0;
        exec(escapeshellcmd($ffmpegPath) . ' -version', $output, $returnVar);
        if ($returnVar !== 0) {
            $controller->flashMessenger()->addError('FFmpeg could not be found at the specified path.');
            return false;
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

    /**
     * Initializes the module during application bootstrap.
     *
     * Sets up view helper aliases, registers CSS and JS assets for the admin interface, and adds ACL rules for module access.
     *
     * @param MvcEvent $event The MVC bootstrap event.
     */
    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        $application = $event->getApplication();
        $serviceManager = $application->getServiceManager();
        $viewHelperManager = $serviceManager->get('ViewHelperManager');
        $viewHelperManager->setAlias('videoThumbnailSelector', 'VideoThumbnail\View\Helper\VideoThumbnailSelector');
        
        // Register CSS and JS assets
        $this->attachListenersForAssets($event);
        
        // Add ACL rules
        $this->addAclRules($serviceManager);
    }
    
    /**
     * Register CSS and JS assets
     */
    protected function attachListenersForAssets(MvcEvent $event): void
    {
        $serviceManager = $event->getApplication()->getServiceManager();
        $viewManager = $serviceManager->get('ViewManager');
        $viewHelperManager = $serviceManager->get('ViewHelperManager');
        
        // Register the asset only on admin routes
        $sharedEvents = $serviceManager->get('SharedEventManager');
        $sharedEvents->attach(
            'Omeka\Controller\Admin',
            'view.layout',
            function ($event) use ($viewHelperManager) {
                $view = $event->getTarget();
                $assetUrl = $viewHelperManager->get('assetUrl');
                $headLink = $viewHelperManager->get('headLink');
                $headScript = $viewHelperManager->get('headScript');
                
                $headLink->appendStylesheet($assetUrl('css/video-thumbnail.css', 'VideoThumbnail'));
                $headScript->appendFile($assetUrl('js/video-thumbnail.js', 'VideoThumbnail'));
            }
        );
    }

    public function install(ServiceLocatorInterface $serviceLocator): void
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->set('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
        $settings->set('videothumbnail_frames_count', 5);
        $settings->set('videothumbnail_default_frame', 10);
        $settings->set('videothumbnail_debug_mode', false);
        $settings->set('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']);

        $tempDir = OMEKA_PATH . '/files/video-thumbnails';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator): void
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->delete('videothumbnail_ffmpeg_path');
        $settings->delete('videothumbnail_frames_count');
        $settings->delete('videothumbnail_default_frame');
        $settings->delete('videothumbnail_debug_mode');
        $settings->delete('videothumbnail_supported_formats');

        $tempDir = OMEKA_PATH . '/files/video-thumbnails';
        if (is_dir($tempDir)) {
            $this->recursiveRemoveDirectory($tempDir);
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach('Omeka\Controller\Admin\Media', 'view.edit.form.after', [$this, 'handleViewEditFormAfter']);
        $sharedEventManager->attach('Omeka\Api\Adapter\MediaAdapter', 'api.update.post', [$this, 'handleMediaUpdatePost']);
        
        // Initialize debug system when attaching listeners
        $serviceLocator = $this->getServiceLocator();
        $settings = $serviceLocator->get('Omeka\Settings');
        \VideoThumbnail\Stdlib\Debug::init($settings);
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

    /**
     * Extracts a video frame at a specified percentage and updates the media's thumbnail.
     *
     * Calculates the frame time based on the selected percentage of the video's duration, extracts the frame using ffmpeg, and stores it as the media's thumbnail. Cleans up temporary files after processing. Exceptions are logged but do not interrupt execution.
     *
     * @param mixed $media Media entity or representation to update.
     * @param int $selectedFrame Percentage (0–100) indicating the point in the video to extract the frame.
     */
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
            error_log($e->getMessage());
        }
    }

    /**
     * Recursively deletes all files and subdirectories within the specified directory, then removes the directory itself.
     *
     * @param string $directory Absolute path to the directory to remove.
     */
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
    
    /****
     * Grants all roles access to the module's admin controller and the module API adapter.
     *
     * @param mixed $serviceManager Service manager used to retrieve the ACL service.
     */
    protected function addAclRules($serviceManager): void
    {
        $acl = $serviceManager->get('Omeka\Acl');
        $acl->allow(
            null,
            ['VideoThumbnail\Controller\Admin\VideoThumbnail']
        );
        
        // Add ACL rule for navigation
        $acl->allow(
            null,
            'Omeka\Api\Adapter\ModuleAdapter'
        );
    }
}

