<?php
namespace VideoThumbnail;

use Omeka\Module\AbstractModule;
use Laminas\Mvc\MvcEvent;
use Laminas\Permissions\Acl\Resource\GenericResource;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;

/**
 * VideoThumbnail module for Omeka S
 * Minimal, bootstrap-safe version
 */
class Module extends AbstractModule
{
    /**
     * Flag to prevent multiple bootstrap calls
     */
    private static $bootstrapComplete = false;

    /**
     * Get module configuration with caching
     */
    public function getConfig()
    {
        static $config = null;
        
        // Return cached config if we've already loaded it
        if ($config !== null) {
            return $config;
        }
        
        // Load minimal configuration - avoid file operations if possible
        $config = [
            'controllers' => [
                'factories' => [
                    'VideoThumbnail\Controller\Admin\VideoThumbnailController' => 'VideoThumbnail\Service\Controller\VideoThumbnailControllerFactory',
                ],
            ],
            'router' => [
                'routes' => [
                    'admin' => [
                        'child_routes' => [
                            'video-thumbnail' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/video-thumbnail',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'VideoThumbnail\Controller\Admin',
                                        'controller' => 'VideoThumbnailController',
                                        'action' => 'index',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'navigation' => [
                'AdminModule' => [
                    [
                        'label' => 'Video Thumbnail',
                        'route' => 'admin/video-thumbnail',
                        'resource' => 'VideoThumbnail\Controller\Admin\VideoThumbnailController',
                    ],
                ],
            ],
            'view_manager' => [
                'template_path_stack' => [
                    __DIR__ . '/view',
                ],
            ],
            'block_layouts' => [
                'factories' => [
                    'videoThumbnail' => 'VideoThumbnail\Service\BlockLayout\VideoThumbnailBlockFactory',
                ],
            ],
            'service_manager' => [
                'factories' => [
                    'VideoThumbnail\VideoFrameExtractor' => 'VideoThumbnail\Service\VideoFrameExtractorFactory',
                ],
            ],
            // Add view helpers
            'view_helpers' => [
                'aliases' => [
                    // Provide access to messenger for our templates
                    'messenger' => 'Omeka\View\Helper\Messages',
                ],
            ],
            
            // Register forms
            'form_elements' => [
                'factories' => [
                    'VideoThumbnail\Form\ConfigBatchForm' => 'Laminas\Form\FormElementManagerFactory',
                ],
                'invokables' => [
                    'VideoThumbnail\Form\ConfigBatchForm' => 'VideoThumbnail\Form\ConfigBatchForm',
                ],
            ],
        ];
        
        return $config;
    }

    /**
     * Get translation file patterns for this module
     */
    public function getTranslations()
    {
        return [
            'module' => [
                'VideoThumbnail' => [
                    'name' => 'VideoThumbnail',
                    'base_dir' => __DIR__ . '/language',
                    'pattern' => '%s.mo',
                ],
            ],
        ];
    }

    /**
     * Get autoloader configuration
     */
    public function getAutoloaderConfig()
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
     * Safe module initialization that prevents execution conflicts
     */
    public function onBootstrap(MvcEvent $event)
    {
        // Skip if bootstrap has already been completed
        if (self::$bootstrapComplete) {
            return;
        }
        
        // Mark bootstrap as complete to prevent multiple executions
        self::$bootstrapComplete = true;
        
        // Call parent bootstrap first
        parent::onBootstrap($event);
        
        try {
            // Get the ACL
            $serviceManager = $event->getApplication()->getServiceManager();
            $acl = $serviceManager->get('Omeka\Acl');
            
            // Define controller and block resources
            $controllerResource = 'VideoThumbnail\Controller\Admin\VideoThumbnailController';
            $blockResource = 'VideoThumbnail\Site\BlockLayout\VideoThumbnailBlock';
            
            // Safe resource addition
            if (!$acl->hasResource($controllerResource)) {
                $acl->addResource($controllerResource);
            }
            
            if (!$acl->hasResource($blockResource)) {
                $acl->addResource($blockResource);
            }
            
            // Allow access to resources
            if ($acl->hasResource($controllerResource)) {
                $acl->allow(null, $controllerResource);
            }
            
            if ($acl->hasResource($blockResource)) {
                $acl->allow(null, $blockResource);
            }
        } catch (\Exception $e) {
            // Fail silently - Omeka will log the error
        }
    }
    
    /**
     * Attach listeners for user interface functionality
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Only attach the minimum necessary listeners
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Page',
            'view.layout',
            function ($event) {
                $view = $event->getTarget();
                $view->headScript()->appendFile($view->assetUrl('js/video-thumbnail.js', 'VideoThumbnail'));
            }
        );
    }
    
    /**
     * Install the module
     */
    public function install(ServiceLocatorInterface $serviceLocator)
    {
        parent::install($serviceLocator);
        
        // Set default settings
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->set('videothumbnail_default_frame', 10);
        $settings->set('videothumbnail_supported_formats', [
            'video/mp4',
            'video/quicktime',
            'video/x-msvideo',
            'video/x-ms-wmv',
            'video/x-matroska',
            'video/webm'
        ]);
    }
    
    /**
     * Uninstall the module
     */
    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        // Remove settings
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->delete('videothumbnail_default_frame');
        $settings->delete('videothumbnail_supported_formats');
        $settings->delete('videothumbnail_debug_mode');
        
        parent::uninstall($serviceLocator);
    }
}