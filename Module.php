<?php
namespace VideoThumbnail;

use Omeka\Module\AbstractModule;
use Laminas\Mvc\MvcEvent;
use Laminas\EventManager\SharedEventManagerInterface;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        // Add ACL, listeners, etc. as needed
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Admin config page CSS
        $sharedEventManager->attach(
            'Omeka\\Controller\\Admin\\VideoThumbnail\\Config',
            'view.layout',
            function ($event) {
                $view = $event->getTarget();
                $view->headLink()->appendStylesheet($view->assetUrl('css/admin.css', 'VideoThumbnail'));
            }
        );
        // Block CSS for site
        $sharedEventManager->attach(
            'Omeka\\Controller\\Site\\Page',
            'view.layout',
            function ($event) {
                $view = $event->getTarget();
                $view->headLink()->appendStylesheet($view->assetUrl('css/block.css', 'VideoThumbnail'));
            }
        );
    }
}
