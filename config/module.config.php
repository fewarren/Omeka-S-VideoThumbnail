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
            'VideoThumbnail\Controller\Admin\VideoThumbnailController' => 'VideoThumbnail\Service\Controller\VideoThumbnailControllerFactory',
        ],
        'aliases' => [
            'VideoThumbnail\Controller\Admin\VideoThumbnail' => 'VideoThumbnail\Controller\Admin\VideoThumbnailController',
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
                                'controller' => 'VideoThumbnail\Controller\Admin\VideoThumbnailController',
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
            'VideoThumbnail\Service\VideoFrameExtractor' => 'VideoThumbnail\Service\VideoFrameExtractorFactory',
            'VideoThumbnail\Thumbnail\ThumbnailSynchronizer' => 'VideoThumbnail\Service\Thumbnail\ThumbnailSynchronizerFactory',
        ],
        'delegators' => [
            'Omeka\File\Store\Manager' => [
                \VideoThumbnail\Service\FileManagerDelegatorFactory::class,
            ],
            'Omeka\File\Store\Local' => [
                \VideoThumbnail\Service\FileManagerDelegatorFactory::class,
            ],
        ],
    ],
    'videothumbnail' => [
        'debug' => [
            'enabled' => true,  // Changed default to true
            'log_dir' => OMEKA_PATH . '/logs',
            'log_file' => 'videothumbnail.log',
            'max_size' => 10485760, // 10MB
            'max_files' => 5
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
            'Omeka\Entity\User' => [
                'VideoThumbnail\Controller\Admin\VideoThumbnailController' => [
                    'allow' => true
                ]
            ],
            'Omeka\Entity\Site' => [
                'VideoThumbnail\Site\BlockLayout\VideoThumbnailBlock' => [
                    'allow' => true
                ]
            ]
        ]
    ],
];
