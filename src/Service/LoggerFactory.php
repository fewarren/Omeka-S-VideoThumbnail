<?php
namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Stream;
use Laminas\Log\PsrLoggerAdapter;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating PSR-3 compatible loggers for VideoThumbnail module
 */
class LoggerFactory implements FactoryInterface
{
    /**
     * Create PSR-3 compatible logger service
     * 
     * @param ContainerInterface $container
     * @param string $requestedName
     * @param array|null $options
     * @return LoggerInterface
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        // First try to get the Omeka core logger if available
        if ($container->has('Omeka\Logger')) {
            try {
                $omekaLogger = $container->get('Omeka\Logger');
                // Wrap Laminas logger in PSR adapter if needed
                if ($omekaLogger instanceof Logger) {
                    return new PsrLoggerAdapter($omekaLogger);
                }
                // If it's already PSR-compatible, return it
                if ($omekaLogger instanceof LoggerInterface) {
                    return $omekaLogger;
                }
            } catch (\Exception $e) {
                // Fall through to create our own logger
            }
        }
        
        // Get settings
        $settings = $container->has('Omeka\Settings') 
            ? $container->get('Omeka\Settings')
            : null;
            
        // Get debug mode setting
        $debugMode = $settings ? $settings->get('videothumbnail_debug_mode', false) : false;
        
        // Create a new logger
        $logger = new Logger();
        
        // Determine log path
        $logFile = null;
        if (defined('OMEKA_PATH')) {
            $logDir = OMEKA_PATH . '/logs';
            // Ensure log directory exists
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            
            if (is_dir($logDir) && is_writable($logDir)) {
                $logFile = $logDir . '/videothumbnail.log';
            }
        }
        
        // Add file writer if path available
        if ($logFile) {
            $fileWriter = new Stream($logFile);
            $logger->addWriter($fileWriter);
        }
        
        // Configure log level based on debug mode
        if (!$debugMode) {
            // Only log errors and warnings in production mode
            $filter = new Logger\Filter\Priority(Logger::WARN);
            foreach ($logger->getWriters() as $writer) {
                $writer->addFilter($filter);
            }
        }
        
        // Wrap Laminas logger in PSR adapter for compatibility
        return new PsrLoggerAdapter($logger);
    }
}