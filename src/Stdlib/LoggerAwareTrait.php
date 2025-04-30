<?php
namespace VideoThumbnail\Stdlib;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PSR-3 compatible logger trait for VideoThumbnail module
 */
trait LoggerAwareTrait
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Set the logger instance
     *
     * @param LoggerInterface $logger
     * @return self
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Get the logger instance
     *
     * @return LoggerInterface
     */
    public function getLogger()
    {
        if (null === $this->logger) {
            $this->logger = new NullLogger();
        }
        return $this->logger;
    }

    /**
     * Log with context for VideoThumbnail module
     *
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function log($level, $message, array $context = [])
    {
        // Add module name prefix
        if (!str_starts_with($message, 'VideoThumbnail:')) {
            $message = 'VideoThumbnail: ' . $message;
        }
        
        // Auto-add calling method to context if not set
        if (!isset($context['method'])) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $trace[1] ?? null;
            if ($caller && isset($caller['class'], $caller['function'])) {
                $context['method'] = $caller['class'] . '::' . $caller['function'];
            }
        }
        
        $this->getLogger()->log($level, $message, $context);
    }
}