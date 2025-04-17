namespace VideoThumbnail;

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
    ],
    'form_elements' => [
        'factories' => [
            Form\ConfigForm::class => Service\Form\ConfigFormFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'VideoThumbnail\Controller\Admin\VideoThumbnail' => Service\Controller\VideoThumbnailControllerFactory::class,
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
            ],
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            '](#)

