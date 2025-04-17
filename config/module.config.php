<?php
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
                    // Add valid child routes here if needed
                ],
            ],
        ],
    ],
];
