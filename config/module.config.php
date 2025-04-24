<?php
namespace VideoThumbnail;

use Laminas\ServiceManager\Factory\InvokableFactory;

return [
    'controllers' => [
        'factories' => [
            Controller\Admin\ConfigController::class => InvokableFactory::class,
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
                                '__NAMESPACE__' => 'VideoThumbnail\\Controller\\Admin',
                                'controller' => Controller\Admin\ConfigController::class,
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'config' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/config',
                                    'defaults' => [
                                        'controller' => Controller\Admin\ConfigController::class,
                                        'action' => 'index',
                                    ],
                                ],
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
                'route' => 'admin/video-thumbnail/config',
                'resource' => Controller\Admin\ConfigController::class,
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],
    'block_layouts' => [
        'factories' => [
            'videoThumbnail' => Service\BlockLayout\VideoThumbnailBlockFactory::class,
        ],
    ],
    'form_elements' => [
        'factories' => [
            Form\ConfigForm::class => InvokableFactory::class,
        ],
    ],
];
