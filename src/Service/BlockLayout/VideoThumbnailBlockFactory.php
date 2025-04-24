<?php
namespace VideoThumbnail\Service\BlockLayout;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Site\BlockLayout\VideoThumbnailBlock;

/**
 * Factory for VideoThumbnailBlock
 */
class VideoThumbnailBlockFactory implements FactoryInterface
{
    /**
     * Create an instance of the VideoThumbnailBlock
     *
     * @param ContainerInterface $container
     * @param string $requestedName
     * @param array|null $options
     * @return VideoThumbnailBlock
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $services = [];
        
        // Get required services safely
        try {
            // Get settings service
            if ($container->has('Omeka\Settings')) {
                $services['settings'] = $container->get('Omeka\Settings');
            }
            
            // Get other services that might be needed
            if ($container->has('Omeka\ApiManager')) {
                $services['api'] = $container->get('Omeka\ApiManager');
            }
            
            if ($container->has('ViewHelperManager')) {
                $services['viewHelpers'] = $container->get('ViewHelperManager');
            }
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error getting services in block factory: ' . $e->getMessage());
        }
        
        // Create block with available services
        return new VideoThumbnailBlock($services['settings'] ?? null);
    }
}