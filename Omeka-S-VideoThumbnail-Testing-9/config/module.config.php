<?php

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'template_map' => [
            'video-thumbnail/admin/video-thumbnail/index' => dirname(__DIR__) . '/view/video-thumbnail/admin/video-thumbnail/index.phtml',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'VideoThumbnail\View\Helper\VideoThumbnailSelector' => 'VideoThumbnail\Service\ViewHelper\VideoThumbnailSelectorFactory',
        ],
        'aliases' => [
            'videoThumbnailSelector' => 'VideoThumbnail\View\Helper\VideoThumbnailSelector',
        ],
    ],
    'form_elements' => [
        'factories' => [
            'VideoThumbnail\Form\ConfigForm' => 'VideoThumbnail\Service\Form\ConfigFormFactory',
            'VideoThumbnail\Form\ConfigBatchForm' => 'VideoThumbnail\Service\Form\ConfigBatchFormFactory',
            'VideoThumbnail\Form\VideoThumbnailBlockForm' => 'Laminas\ServiceManager\Factory\InvokableFactory',
        ],
        'aliases' => [
            'videothumbnailconfigform' => 'VideoThumbnail\Form\ConfigForm',
            'videothumbnailconfigbatchform' => 'VideoThumbnail\Form\ConfigBatchForm',
        ],
    ],
    'controllers' => [
        'factories' => [
            // Ensure controller is registered with its FQCN
            'VideoThumbnail\Controller\Admin\VideoThumbnailController' => 'VideoThumbnail\Service\Controller\VideoThumbnailControllerFactory',
        ],
        'aliases' => [
            // Keep the alias used in the router defaults
            'VideoThumbnail\Controller\Admin\VideoThumbnail' => 'VideoThumbnail\Controller\Admin\VideoThumbnailController',
            // Remove potentially confusing/redundant aliases
            // 'VideoThumbnail\Controller\AdminVideoThumbnailController' => 'VideoThumbnail\Controller\Admin\VideoThumbnailController',
            // 'VideoThumbnail\Controller\Admin\VideoThumbnail-Controller' => 'VideoThumbnail\Controller\Admin\VideoThumbnailController',
            // 'VideoThumbnail-Controller-Admin-VideoThumbnailController' => 'VideoThumbnail\Controller\Admin\VideoThumbnailController',
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'extractVideoFrames' => 'VideoThumbnail\Service\ControllerPlugin\ExtractVideoFramesFactory',
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
                                '__CONTROLLER__' => 'VideoThumbnail',
                                'controller' => 'VideoThumbnail\Controller\Admin\VideoThumbnailController',
                                'action' => 'index',
                            ],
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                        ],
                    ],
                    'video-thumbnail-select-frame' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/video-thumbnail/select-frame/:id',
                            'defaults' => [
                                '__NAMESPACE__' => 'VideoThumbnail\Controller\Admin',
                                '__CONTROLLER__' => 'VideoThumbnail',
                                'controller' => 'VideoThumbnail\Controller\Admin\VideoThumbnailController',
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
                                '__CONTROLLER__' => 'VideoThumbnail',
                                'controller' => 'VideoThumbnail\Controller\Admin\VideoThumbnailController',
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
                                '__CONTROLLER__' => 'VideoThumbnail',
                                'controller' => 'VideoThumbnail\Controller\Admin\VideoThumbnailController',
                                'action' => 'index',
                            ],
                            'constraints' => [
                                'id' => '\d+',
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
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
                'resource' => 'VideoThumbnail\Controller\Admin\VideoThumbnailController',
            ],
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            'videothumbnail' => 'VideoThumbnail\Service\Media\IngesterFactory',
        ],
    ],
    'media_renderers' => [
        'factories' => [
            'videothumbnail' => 'VideoThumbnail\Service\Media\RendererFactory',
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
                    'js/video-thumbnail-monitor.js',
                    'js/video-thumbnail-block-admin.js',
                    'js/video-thumbnail-form.js',
                ],
            ],
        ],
    ],
    'job' => [
        'dispatcher_strategies' => [
            'factories' => [
                'VideoThumbnail\Job\DispatchStrategy\VideoThumbnailStrategy' => 'VideoThumbnail\Service\Job\DispatchStrategy\VideoThumbnailStrategyFactory',
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            // Core services first
            'VideoThumbnail\Stdlib\VideoFrameExtractor' => 'VideoThumbnail\Service\VideoFrameExtractorFactory',
            
            // Then dependent services
            'VideoThumbnail\Service\Thumbnail\ThumbnailSynchronizer' => 'VideoThumbnail\Service\Thumbnail\ThumbnailSynchronizerFactory',
            'VideoThumbnail\Media\Ingester\VideoThumbnail' => 'VideoThumbnail\Service\Media\IngesterFactory',
            'VideoThumbnail\Media\Renderer\VideoThumbnail' => 'VideoThumbnail\Service\Media\RendererFactory',
            'VideoThumbnail\Controller\Admin\VideoThumbnailController' => 'VideoThumbnail\Service\Controller\VideoThumbnailControllerFactory',
        ],
        'delegators' => [
            'Omeka\File\Store\Manager' => [
                \VideoThumbnail\Service\FileManagerDelegatorFactory::class,
            ],
            'Omeka\File\Store\Local' => [
                \VideoThumbnail\Service\FileManagerDelegatorFactory::class,
            ],
        ],
        'shared' => [
            // Mark stateless services as not shared to save memory
            'VideoThumbnail\Media\Renderer\VideoThumbnail' => false,
            'VideoThumbnail\View\Helper\VideoThumbnailSelector' => false,
        ],
    ],
    'videothumbnail' => [
        'job_dispatch' => [
            'memory_limit' => '1024M', // Increased from 512M
            'timeout' => 3600,
            'status_file' => OMEKA_PATH . '/logs/video_thumbnail_jobs.json'
        ],
        'memory_management' => [
            'min_free_memory' => '128M', // Increased from 64M
            'gc_probability' => 100,
            'memory_reset_threshold' => '768M' // Increased from 384M
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
            // Debug settings moved here for consolidation
            'debug_log_dir' => OMEKA_PATH . '/logs',
            'debug_log_file' => 'videothumbnail.log',
            'debug_max_size' => 10485760, // 10MB
            'debug_max_files' => 5,
            'allowed_formats' => [
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
            'videoThumbnail' => 'VideoThumbnail\Site\BlockLayout\VideoThumbnailBlock',
        ],
    ],
    'acl' => [
        'rules' => [
            // Grant access to the admin controller for administrative roles
            'editor' => [
                'VideoThumbnail\Controller\Admin\VideoThumbnailController' => null, // null grants all privileges
            ],
            'site_admin' => [
                'VideoThumbnail\Controller\Admin\VideoThumbnailController' => null,
            ],
            'global_admin' => [
                'VideoThumbnail\Controller\Admin\VideoThumbnailController' => null,
            ],
            // Grant access to the site block for all roles (including anonymous)
            null => [
                'VideoThumbnail\Site\BlockLayout\VideoThumbnailBlock' => null,
            ],
        ]
    ],
];