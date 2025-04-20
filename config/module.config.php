<?php
namespace VideoThumbnail;

// Add use statements for clarity and consistency
use Laminas\ServiceManager\Factory\InvokableFactory;
use VideoThumbnail\Form;
use VideoThumbnail\Service;
use VideoThumbnail\Controller;
use VideoThumbnail\Site;
use VideoThumbnail\Listener;
use VideoThumbnail\Job;
use VideoThumbnail\Stdlib; // Added for VideoFrameExtractor
use VideoThumbnail\View\Helper as VideoThumbnailViewHelper; // Alias for View Helper
use VideoThumbnail\Thumbnail; // Added for ThumbnailSynchronizer

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            VideoThumbnailViewHelper\VideoThumbnailSelector::class => Service\ViewHelper\VideoThumbnailSelectorFactory::class,
        ],
        'aliases' => [
            'videoThumbnailSelector' => VideoThumbnailViewHelper\VideoThumbnailSelector::class,
        ],
    ],
    'form_elements' => [
        'factories' => [
            Form\ConfigForm::class => Service\Form\ConfigFormFactory::class,
            Form\ConfigBatchForm::class => Service\Form\ConfigBatchFormFactory::class, // Use ::class consistently
            Form\VideoThumbnailBlockForm::class => InvokableFactory::class,
        ], // <-- Added missing comma here if other sections follow
        'aliases' => [ // <-- Ensure 'aliases' section is correctly defined
            'videothumbnailconfigform' => Form\ConfigForm::class,
            'videothumbnailconfigbatchform' => Form\ConfigBatchForm::class,
        ], // <-- Added missing comma here if other sections follow
    ], // <-- Closing bracket for 'form_elements'
    'controllers' => [
        'factories' => [
            // Assuming Controller\Admin\VideoThumbnail exists and is correct
            Controller\Admin\VideoThumbnail::class => Service\Controller\VideoThumbnailControllerFactory::class,
            Controller\Admin\IndexController::class => Service\Controller\Admin\IndexControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            // Assuming Service\ControllerPlugin\ExtractVideoFramesFactory exists
            'extractVideoFrames' => Service\ControllerPlugin\ExtractVideoFramesFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'video-thumbnail' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/video-thumbnail[/:action]',
                            'defaults' => [
                                '__NAMESPACE__' => 'VideoThumbnail\Controller\Admin',
                                // Ensure this controller alias matches a registered controller
                                'controller' => Controller\Admin\VideoThumbnail::class, // Use class name if registered that way
                                'action' => 'index',
                            ],
                        ],
                    ],
                    // ... other routes seem okay, ensure controller names match registration ...
                     'video-thumbnail-select-frame' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/video-thumbnail/select-frame/:id',
                            'defaults' => [
                                '__NAMESPACE__' => 'VideoThumbnail\Controller\Admin',
                                'controller' => Controller\Admin\VideoThumbnail::class, // Use class name
                                'action' => 'select-frame',
                            ],
                            'constraints' => [
                                'id' => '\d+',
                            ],
                        ],
                    ],
                    'video-thumbnail-extract-frame' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/video-thumbnail/extract-frame',
                            'defaults' => [
                                '__NAMESPACE__' => 'VideoThumbnail\Controller\Admin',
                                'controller' => Controller\Admin\VideoThumbnail::class, // Use class name
                                'action' => 'extract-frame',
                            ],
                        ],
                    ],
                    'video-thumbnail-media' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/video-thumbnail/media/:id/:action',
                            'defaults' => [
                                '__NAMESPACE__' => 'VideoThumbnail\Controller\Admin',
                                'controller' => Controller\Admin\VideoThumbnail::class, // Use class name
                            ],
                            'constraints' => [
                                'id' => '\d+',
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
                'label' => 'Video Thumbnail', // @translate
                'route' => 'admin/video-thumbnail',
                'resource' => Controller\Admin\VideoThumbnail::class, // Use class name
                'pages' => [
                    [
                        'label' => 'Configuration', // @translate
                        'route' => 'admin/video-thumbnail',
                        'resource' => Controller\Admin\VideoThumbnail::class, // Use class name
                    ],
                ],
            ],
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            // Assuming Service\Media\IngesterFactory exists
            'videothumbnail' => Service\Media\IngesterFactory::class,
        ],
    ],
    'media_renderers' => [
        'factories' => [
             // Assuming Service\Media\RendererFactory exists
            'videothumbnail' => Service\Media\RendererFactory::class,
        ],
    ],
    'thumbnails' => [
        'fallbacks' => [
            'video' => ['videothumbnail', 'default'],
        ],
    ],
    'js_translate_strings' => [
        'Select Frame', // @translate
        'Generating thumbnails...', // @translate
        'Error loading video frames', // @translate
        'Select this frame as thumbnail', // @translate
    ],
    'assets' => [
        'module_paths' => [
            'VideoThumbnail' => [
                'css' => [
                    'css/video-thumbnail.css',
                    'css/video-thumbnail-monitor.css'
                ],
                'js' => [
                    'js/video-thumbnail.js',
                    'js/video-thumbnail-monitor.js'
                ],
            ],
        ],
    ],
    'job' => [
        'dispatcher_strategies' => [
            'factories' => [
                // Assuming Service\Job\DispatchStrategy\VideoThumbnailStrategyFactory exists
                Job\DispatchStrategy\VideoThumbnailStrategy::class => Service\Job\DispatchStrategy\VideoThumbnailStrategyFactory::class,
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            // Assuming Service\VideoFrameExtractorFactory exists
            'VideoThumbnail\VideoFrameExtractor' => Service\VideoFrameExtractorFactory::class,
            // Assuming Service\Thumbnail\ThumbnailSynchronizerFactory exists
            'VideoThumbnail\ThumbnailSynchronizer' => Service\Thumbnail\ThumbnailSynchronizerFactory::class,
        ],
        'delegators' => [
            'Omeka\File\Store\Manager' => [
                 // Assuming Service\FileManagerDelegatorFactory exists
                Service\FileManagerDelegatorFactory::class,
            ],
        ],
    ],
    'listeners' => [
        'factories' => [
            // Assuming listener factories exist
            Listener\MediaIngestListener::class => Service\Listener\MediaIngestListenerFactory::class,
            Listener\MediaUpdateListener::class => Service\Listener\MediaUpdateListenerFactory::class,
        ],
    ],
    // Module specific config section
    'videothumbnail' => [
        // ... debug, job_dispatch, supported_formats, thumbnail_options, settings, job_settings ...
        // Ensure this section is correctly structured internally
         'debug' => [
            'enabled' => false,
            'log_dir' => OMEKA_PATH . '/logs',
            'log_file' => 'videothumbnail.log',
            'max_size' => 10485760, // 10MB
            'max_files' => 5,
            'levels' => [
                'error' => true,
                'warning' => true,
                'info' => true,
                'debug' => false
            ]
        ],
        'job_dispatch' => [
            'memory_limit' => '512M',
            'timeout' => 3600,
            'status_file' => OMEKA_PATH . '/logs/video_thumbnail_jobs.json'
        ],
        'supported_formats' => [
            'video/mp4' => ['mp4'],
            'video/quicktime' => ['mov'],
            'video/x-msvideo' => ['avi'],
            'video/x-ms-wmv' => ['wmv'],
            'video/x-matroska' => ['mkv'],
            'video/webm' => ['webm'],
            'video/3gpp' => ['3gp'],
            'video/3gpp2' => ['3g2'],
            'video/x-flv' => ['flv']
        ],
        'thumbnail_options' => [
            'sizes' => [
                'large' => ['width' => 800, 'height' => 450],
                'medium' => ['width' => 400, 'height' => 225],
                'square' => ['width' => 200, 'height' => 200]
            ],
            'default_frame_position' => 10,
            'frames_to_extract' => 5
        ],
        'settings' => [
            'ffmpeg_path' => '',
            'frame_count' => 5,
            'default_position' => 50,
            'memory_limit' => 512,
            'debug_mode' => false,
            'log_level' => 'error',
            'supported_formats' => [
                'video/mp4',
                'video/webm',
                'video/quicktime',
                'video/x-msvideo',
                'video/x-ms-wmv',
                'video/x-matroska',
                'video/3gpp',
                'video/3gpp2',
                'video/x-flv',
            ],
        ],
        'job_settings' => [
            'max_retries' => 3,
            'retry_delay' => 5,
            'poll_interval' => 5000,
            'cleanup_interval' => 3600,
            'job_timeout' => 300,
        ],
    ],
    // Temporarily comment out the entire 'site' section for debugging the config structure error
    /*
    'site' => [
        'block_layouts' => [
            'factories' => [
                // Assuming Service\Factory\VideoThumbnailBlockFactory exists
                Site\BlockLayout\VideoThumbnailBlock::class => Service\Factory\VideoThumbnailBlockFactory::class,
            ],
            'aliases' => [
                'videoThumbnail' => Site\BlockLayout\VideoThumbnailBlock::class,
            ]
        ],
    ],
    */
]; // <-- Final closing bracket for the main return array
