<?php
namespace VideoThumbnail\Service\Form;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Form\ConfigForm;
use VideoThumbnail\Form\ConfigBatchForm;

class ConfigFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Create the appropriate form based on the requested name
        return new $requestedName();
    }
}