<?php
namespace VideoThumbnail\Service\Thumbnail;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Service\Thumbnail\ThumbnailSynchronizer;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;
use Laminas\ServiceManager\Exception\ServiceNotFoundException;

class ThumbnailSynchronizerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        try {
            return new ThumbnailSynchronizer(
                $this->getRequiredServices($container)
            );
        } catch (\Exception $e) {
            // Log error and throw service not found exception
            error_log(sprintf(
                'ThumbnailSynchronizerFactory error: %s',
                $e->getMessage()
            ));
            throw new ServiceNotFoundException(
                'ThumbnailSynchronizer',
                $e->getMessage()
            );
        }
    }

    protected function getRequiredServices(ContainerInterface $container)
    {
        $required = [
            'Omeka\File\Store' => 'fileManager',
            'Omeka\EntityManager' => 'entityManager',
            'Omeka\Logger' => 'logger',
            'Omeka\Settings' => 'settings'
        ];

        $services = [];
        foreach ($required as $serviceName => $key) {
            if (!$container->has($serviceName)) {
                throw new ServiceNotFoundException(
                    sprintf('Required service %s not found', $serviceName)
                );
            }

            try {
                $services[$key] = $container->get($serviceName);
            } catch (\Exception $e) {
                throw new ServiceNotCreatedException(
                    sprintf('Failed to create service %s: %s', $serviceName, $e->getMessage())
                );
            }
        }

        return $services;
    }

    protected function validateServices(array $services)
    {
        $required = ['fileManager', 'entityManager', 'logger', 'settings'];
        foreach ($required as $key) {
            if (!isset($services[$key])) {
                throw new ServiceNotCreatedException(
                    sprintf('Missing required service: %s', $key)
                );
            }
        }

        // Validate specific service types
        if (!$services['entityManager'] instanceof \Doctrine\ORM\EntityManager) {
            throw new ServiceNotCreatedException('Invalid entity manager service');
        }

        if (!$services['logger'] instanceof \Laminas\Log\LoggerInterface) {
            throw new ServiceNotCreatedException('Invalid logger service');
        }

        return true;
    }
}