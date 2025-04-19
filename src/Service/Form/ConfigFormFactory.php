<?php
namespace VideoThumbnail\Service\Form;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Form\ConfigForm;
use VideoThumbnail\Form\ConfigBatchForm;

class ConfigFormFactory implements FactoryInterface
{
    /****
     * Instantiates and returns a new form object of the specified class.
     *
     * @param string $requestedName Fully qualified class name of the form to instantiate.
     * @return object New instance of the requested form class.
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Create the appropriate form based on the requested name
        return new $requestedName();
    }
}