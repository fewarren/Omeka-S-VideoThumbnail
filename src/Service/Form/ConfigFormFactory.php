<?php
namespace VideoThumbnail\Service\Form;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Form\ConfigForm;
use VideoThumbnail\Form\ConfigBatchForm;
use VideoThumbnail\Stdlib\Debug;

class ConfigFormFactory implements FactoryInterface
{
    /****
     * Instantiates and returns a form object based on the requested class name.
     *
     * Attempts to create a new instance of the form class specified by $requestedName. If instantiation fails, the exception is logged and rethrown.
     *
     * @param string $requestedName Fully qualified class name of the form to instantiate.
     * @param array|null $options Optional configuration options for form creation.
     * @return object Instance of the requested form class.
     * @throws \Exception If the form class cannot be instantiated.
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        Debug::log('Creating form instance: ' . $requestedName, __METHOD__);
        try {
            // Create the appropriate form based on the requested name
            $form = new $requestedName();
            Debug::log('Successfully created form instance', __METHOD__);
            return $form;
        } catch (\Exception $e) {
            Debug::logError('Failed to create form instance: ' . $e->getMessage(), __METHOD__, $e);
            throw $e;
        }
    }
}