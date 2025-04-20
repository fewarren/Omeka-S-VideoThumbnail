<?php
namespace VideoThumbnail;

use Laminas\ServiceManager\Factory\InvokableFactory;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'VideoThumbnail\View\Helper\VideoThumbnailSelector' => Service\ViewHelper\VideoThumbnailSelectorFactory::class,
        ],
        'aliases' => [
            'videoThumbnailSelector' => 'VideoThumbnail\View\Helper\VideoThumbnailSelector',
        ],
    ],
    'form_elements' => [
        'factories' => [
            Form\ConfigForm::class => Service\Form\ConfigFormFactory::class,
            'VideoThumbnail\Form\ConfigBatchForm' => 'VideoThumbnail\Service\Form\ConfigBatchFormFactory',
            Form\VideoThumbnailBlockForm::class => InvokableFactory::class, // Keep block form registered
        ],
        'aliases' => [
            'videothumbnailconfigform' => Form\ConfigForm::class,
            'videothumbnailconfigbatchform' => Form\ConfigBatchForm::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'VideoThumbnail\Controller\Admin\VideoThumbnail' => Service\Controller\VideoThumbnailControllerFactory::class,
            Controller\Admin\IndexController::class => Service\Controller\Admin\IndexControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
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
                                'controller' => 'VideoThumbnail',
                                'action' => 'index',
                            ],
                        ],
                    ],
                    'video-thumbnail-select-frame' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/video-thumbnail/select-frame/:id',
                            'defaults' => [
                                '__NAMESPACE__' => 'VideoThumbnail\Controller\Admin',
                                'controller' => 'VideoThumbnail',
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
                                'controller' => 'VideoThumbnail',
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
                                'controller' => 'VideoThumbnail',
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
                'label' => 'Video Thumbnail', 
                'route' => 'admin/video-thumbnail',
                'resource' => 'VideoThumbnail\Controller\Admin\VideoThumbnail',
                'pages' => [
                    [
                        'label' => 'Configuration',
                        'route' => 'admin/video-thumbnail',
                        'resource' => 'VideoThumbnail\Controller\Admin\VideoThumbnail',
                    ],
                ],
            ],
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            'videothumbnail' => Service\Media\IngesterFactory::class,
        ],
    ],
    'media_renderers' => [
        'factories' => [
            'videothumbnail' => Service\Media\RendererFactory::class,
        ],
        // Aliases are now registered dynamically based on the configured formats
    ],
    'thumbnails' => [
        'fallbacks' => [
            'video' => ['videothumbnail', 'default'],
        ],
    ],
    'js_translate_strings' => [
        'Select Frame', 
        'Generating thumbnails...', 
        'Error loading video frames', 
        'Select this frame as thumbnail',
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
                Job\DispatchStrategy\VideoThumbnailStrategy::class => Service\Job\DispatchStrategy\VideoThumbnailStrategyFactory::class,
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            'VideoThumbnail\VideoFrameExtractor' => Service\VideoFrameExtractorFactory::class,
            'VideoThumbnail\ThumbnailSynchronizer' => Service\Thumbnail\ThumbnailSynchronizerFactory::class,
            // Temporarily comment out the core service factory
            // Stdlib\VideoFrameExtractor::class => Service\Stdlib\VideoFrameExtractorFactory::class,
        ],
        'delegators' => [
            'Omeka\File\Store\Manager' => [
                Service\FileManagerDelegatorFactory::class,
            ],
        ],
    ],
    'listeners' => [
        'factories' => [
            // Keep listeners commented out for now
            // Listener\MediaIngestListener::class => Service\Listener\MediaIngestListenerFactory::class,
            // Listener\MediaUpdateListener::class => Service\Listener\MediaUpdateListenerFactory::class,
        ],
    ],
    'videothumbnail' => [
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
    'block_layouts' => [
        'invokables' => [
            'videoThumbnail' => Site\BlockLayout\VideoThumbnail::class,
        ],
    ],
    'site' => [
        'block_layouts' => [
            'factories' => [
                Site\BlockLayout\VideoThumbnailBlock::class => Service\Factory\VideoThumbnailBlockFactory::class, // Keep block registered
            ],
            'aliases' => [
                'videoThumbnail' => Site\BlockLayout\VideoThumbnailBlock::class,
            ]
        ],
    ],
];
