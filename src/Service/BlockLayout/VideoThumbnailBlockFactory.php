<?php
namespace VideoThumbnail\Service\BlockLayout;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Site\BlockLayout\VideoThumbnailBlock;

class VideoThumbnailBlockFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new VideoThumbnailBlock();
    }
}
