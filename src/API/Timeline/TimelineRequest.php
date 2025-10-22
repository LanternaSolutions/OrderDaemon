<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Immutable value object representing a timeline rendering request
 * 
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.0.0
 */
final class TimelineRequest
{
    public function __construct(
        public readonly int $logId,
        public readonly bool $includeDebug = false
    ) {
        if ($logId <= 0) {
            throw new \InvalidArgumentException('Log ID must be positive integer');
        }
    }
    
    /**
     * Create from REST request parameters
     */
    public static function fromRestRequest(\WP_REST_Request $request): self
    {
        $logId = $request->get_param('log_id');
        $includeDebug = $request->get_param('include_debug') ?? false;
        
        // Normalize include_debug parameter
        if (!is_bool($includeDebug)) {
            if (is_string($includeDebug)) {
                $includeDebug = in_array(strtolower($includeDebug), ['1','true','yes'], true);
            } else {
                $includeDebug = (bool) $includeDebug;
            }
        }
        
        return new self((int) $logId, $includeDebug);
    }
}
