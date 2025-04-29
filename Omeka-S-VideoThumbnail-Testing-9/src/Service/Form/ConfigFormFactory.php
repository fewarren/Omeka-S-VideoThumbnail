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
        try {
            // Create the appropriate form based on the requested name
            if ($requestedName === ConfigBatchForm::class || $requestedName === 'VideoThumbnail\Form\ConfigBatchForm') {
                return new ConfigBatchForm(null, $options ?? []);
            }
            
            // Default to regular ConfigForm
            $form = new ConfigForm(null, $options ?? []);
            
            // Initialize form with settings data
            $form->init();
            
            // No Debug calls to prevent circular dependencies
            return $form;
        } catch (\Exception $e) {
            // Log error but don't rely on Debug class
            error_log('VideoThumbnail ConfigFormFactory: Error creating form: ' . $e->getMessage());
            
            // Return basic form to avoid breaking things
            return new ConfigForm();
        }
    }
}