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
            Form\ConfigBatchForm::class => Service\Form\ConfigFormFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'VideoThumbnail\Controller\Admin\VideoThumbnailController' => Service\Controller\VideoThumbnailControllerFactory::class,
            // Add alias for short name
            'VideoThumbnailController' => Service\Controller\VideoThumbnailControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'extractVideoFrames' => Service\ControllerPlugin\ExtractVideoFramesFactory::class](#)

