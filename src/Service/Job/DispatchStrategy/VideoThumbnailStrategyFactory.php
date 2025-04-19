<?php
namespace VideoThumbnail\Service\Job\DispatchStrategy;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Job\DispatchStrategy\VideoThumbnailStrategy;

class VideoThumbnailStrategyFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Get the PHP CLI strategy from the service manager to delegate job execution
        $phpCliStrategy = $services->get('Omeka\Job\DispatchStrategy\PhpCli');
        
        return new VideoThumbnailStrategy($phpCliStrategy);
    }
}