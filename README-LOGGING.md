# VideoThumbnail Module Logging System

This document explains how to use the VideoThumbnail module's PSR-3 compatible logging system for consistent and configurable logging throughout the module.

## Overview

The VideoThumbnail module implements a PSR-3 compliant logging system to:

1. Replace direct `error_log()` calls
2. Integrate with Omeka S's logging system
3. Provide configurable log levels
4. Enable contextual logging
5. Standardize log formats

## Using the Logger

### In Classes with LoggerAwareTrait

The module provides a `LoggerAwareTrait` that you can use in your classes:

```php
namespace VideoThumbnail\YourNamespace;

use Psr\Log\LoggerInterface;
use VideoThumbnail\Stdlib\LoggerAwareTrait;

class YourClass
{
    use LoggerAwareTrait;
    
    public function __construct(LoggerInterface $logger = null)
    {
        if ($logger) {
            $this->setLogger($logger);
        }
    }
    
    public function someMethod()
    {
        // Use PSR-3 log levels
        $this->log('debug', 'This is a debug message');
        $this->log('info', 'This is an info message');
        $this->log('warning', 'This is a warning message', ['context' => 'value']);
        $this->log('error', 'This is an error message', ['exception' => $exception]);
    }
}
```

### Factory Pattern

Use the factory pattern to inject the logger:

```php
namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class YourClassFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Get the PSR logger
        $logger = $services->has('VideoThumbnail\Logger') 
            ? $services->get('VideoThumbnail\Logger') 
            : null;
        
        return new \VideoThumbnail\YourNamespace\YourClass($logger);
    }
}
```

## Log Levels

The module follows PSR-3 log levels:

- `debug`: Detailed debug information
- `info`: Interesting events (user logs in, SQL logs)
- `notice`: Normal but significant events
- `warning`: Exceptional occurrences that are not errors
- `error`: Runtime errors that don't require immediate action
- `critical`: Critical conditions (application component unavailable, unexpected exception)
- `alert`: Action must be taken immediately (entire website down, database unavailable)
- `emergency`: System is unusable

In production mode, only `warning` and higher levels are logged by default.

## Configuration

The logging system uses the following configuration settings:

- `videothumbnail_debug_mode`: Controls log level filtering (true = debug and higher, false = warning and higher)
- Default log location: `OMEKA_PATH/logs/videothumbnail.log`

## Best Practices

1. **Always use the logging system** instead of direct `error_log()` calls
2. **Add context** to log messages for better debugging:
   ```php
   $this->log('error', 'Error processing video', [
       'media_id' => $media->getId(),
       'file_path' => $filePath,
       'exception' => $e->getMessage()
   ]);
   ```
3. **Use appropriate log levels**:
   - `debug`: For detailed troubleshooting information
   - `info`: For tracking normal operation
   - `warning`: For non-critical issues
   - `error`: For runtime errors
   - Higher levels for more serious issues

4. **Structure log messages** with specific information:
   - Bad: "Error occurred"
   - Good: "Failed to extract frame from video at position 45s"