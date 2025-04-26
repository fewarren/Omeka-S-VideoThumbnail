<?php
namespace VideoThumbnail\Service\Job\DispatchStrategy;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Job\DispatchStrategy\VideoThumbnailStrategy;
use Laminas\ServiceManager\Exception\ServiceNotFoundException;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;

class VideoThumbnailStrategyFactory implements FactoryInterface
{
    /**
     * Creates and returns a configured VideoThumbnailStrategy instance, or falls back to the default strategy if an error occurs.
     *
     * Attempts to instantiate and configure a VideoThumbnailStrategy using required services from the container. If creation fails, logs the error and returns the default PHP CLI dispatch strategy as a fallback.
     *
     * @param ContainerInterface $container Service container providing dependencies.
     * @param string $requestedName Name of the requested service.
     * @param array|null $options Optional configuration options.
     * @return mixed Configured VideoThumbnailStrategy instance or the default strategy on failure.
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        try {
            return $this->createStrategy($container);
        } catch (\Exception $e) {
            // Log the error
            error_log(sprintf(
                'VideoThumbnailStrategyFactory error: %s. Falling back to default strategy.',
                $e->getMessage()
            ));

            // Return the default PhpCli strategy as fallback
            return $this->getFallbackStrategy($container);
        }
    }

    /**
     * Creates and configures a VideoThumbnailStrategy instance with required dependencies.
     *
     * Validates the presence of the PHP CLI dispatch strategy service, injects necessary services,
     * applies configuration settings, and returns the fully configured strategy.
     *
     * @param ContainerInterface $container Service container providing dependencies.
     * @return VideoThumbnailStrategy Configured strategy instance.
     * @throws ServiceNotFoundException If the PHP CLI strategy service is missing.
     * @throws ServiceNotCreatedException If required dependencies cannot be retrieved.
     */
    protected function createStrategy(ContainerInterface $container)
    {
        // Validate required services
        if (!$container->has('Omeka\Job\DispatchStrategy\PhpCli')) {
            throw new ServiceNotFoundException('PhpCli strategy service not found');
        }

        // Create strategy with required dependencies
        $strategy = new VideoThumbnailStrategy(
            $this->getRequiredServices($container)
        );

        // Configure the strategy
        $this->configureStrategy($strategy, $container);

        return $strategy;
    }

    /**
     * Retrieves required core services from the container, ensuring their availability.
     *
     * Throws a ServiceNotFoundException if any required service is missing, or a ServiceNotCreatedException if a service cannot be instantiated.
     *
     * @param ContainerInterface $container The service container.
     * @return array Associative array of required services keyed by service name.
     * @throws ServiceNotFoundException If a required service is not found in the container.
     * @throws ServiceNotCreatedException If a required service cannot be created.
     */
    protected function getRequiredServices(ContainerInterface $container)
    {
        $required = [
            'Omeka\Logger',
            'Omeka\Settings',
            'Omeka\Job\Dispatcher'
        ];

        $services = [];
        foreach ($required as $serviceName) {
            if (!$container->has($serviceName)) {
                throw new ServiceNotFoundException(
                    sprintf('Required service %s not found', $serviceName)
                );
            }

            try {
                $services[$serviceName] = $container->get($serviceName);
            } catch (\Exception $e) {
                throw new ServiceNotCreatedException(
                    sprintf('Failed to create service %s: %s', $serviceName, $e->getMessage())
                );
            }
        }

        return $services;
    }

    /**
     * Applies configuration settings from Omeka to the given video thumbnail strategy.
     *
     * Sets memory limit, process timeout, and enables debug mode based on application settings.
     *
     * @param object $strategy The strategy instance to configure.
     * @param ContainerInterface $container Service container providing configuration.
     */
    protected function configureStrategy($strategy, ContainerInterface $container)
    {
        // Get settings
        $settings = $container->get('Omeka\Settings');

        // Configure memory limit
        $memoryLimit = $settings->get('videothumbnail_memory_limit', 512);
        $strategy->setMemoryLimit($memoryLimit . 'M');

        // Configure timeout
        $timeout = $settings->get('videothumbnail_process_timeout', 3600);
        $strategy->setTimeout($timeout);

        // Configure logging
        if ($settings->get('videothumbnail_debug_mode', false)) {
            $strategy->enableDebug();
        }
    }

    /**
     * Retrieves the default PHP CLI dispatch strategy as a fallback.
     *
     * Attempts to obtain the 'Omeka\Job\DispatchStrategy\PhpCli' service from the container.
     * Logs the use of the fallback strategy. Throws a ServiceNotCreatedException if retrieval fails.
     *
     * @return mixed The default PHP CLI dispatch strategy.
     * @throws ServiceNotCreatedException If the fallback strategy cannot be created.
     */
    protected function getFallbackStrategy(ContainerInterface $container)
    {
        try {
            $defaultStrategy = $container->get('Omeka\Job\DispatchStrategy\PhpCli');

            // Log fallback
            error_log('Using PhpCli fallback strategy for video thumbnail processing');

            return $defaultStrategy;
        } catch (\Exception $e) {
            throw new ServiceNotCreatedException(
                'Failed to create fallback strategy: ' . $e->getMessage()
            );
        }
    }
}