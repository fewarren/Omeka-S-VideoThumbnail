<?php
namespace VideoThumbnail\Service\Form;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Form\ConfigForm;
use VideoThumbnail\Form\ConfigBatchForm;
use VideoThumbnail\Stdlib\Debug;

class ConfigFormFactory implements FactoryInterface
{
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