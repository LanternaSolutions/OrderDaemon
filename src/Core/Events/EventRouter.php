<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Events;

use OrderDaemon\CompletionManager\Core\Events\Adapters\PayPalAdapter;
use OrderDaemon\CompletionManager\Core\Events\Adapters\GenericAdapter;
use OrderDaemon\CompletionManager\Core\ProcessIdManager;

/**
 * Event Router
 * 
 * Routes incoming webhook events to appropriate gateway adapters for
 * normalization and processing. Manages adapter registration and provides
 * fallback handling for unknown gateways.
 * 
 * @package OrderDaemon\CompletionManager\Core\Events
 * @since   1.1.1
 */
class EventRouter
{
    /**
     * Registered gateway adapters
     * 
     * @var GatewayEventAdapter[]
     */
    private array $adapters = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->registerDefaultAdapters();
    }

    /**
     * Process webhook through appropriate adapter
     * 
     * @param string $gateway Gateway name
     * @param array $input_data Raw webhook data
     * @return UniversalEvent[] Array of processed events
     * @throws \InvalidArgumentException If no suitable adapter found
     */
    public function processWebhook(string $gateway, array $input_data): array
    {
        $start_time = microtime(true);
        
        try {
            // Find appropriate adapter
            $adapter = $this->selectAdapter($gateway, $input_data);
            if (!$adapter) {
                $this->logRouterError('No suitable adapter found', $gateway, $input_data);
                throw new \InvalidArgumentException("No adapter available for gateway: {$gateway}");
            }


            // Validate authenticity
            if (!$adapter->validateAuthenticity($input_data)) {
                $this->logRouterError('Authentication failed', $gateway, $input_data);
                throw new \InvalidArgumentException("Authentication failed for gateway: {$gateway}");
            }

            // Normalize events
            $events = $adapter->normalize($input_data);
            
            // Enhance with entity resolution
            $enhanced_events = [];
            foreach ($events as $event) {
                $enhanced_event = $adapter->identifyEntities($event);
                $enhanced_events[] = $enhanced_event;
            }

            // Dispatch to universal event processor
            $this->dispatchEvents($enhanced_events);

            $execution_time = microtime(true) - $start_time;
            $this->logRouterSuccess($gateway, count($enhanced_events), $execution_time);

            return $enhanced_events;

        } catch (\Throwable $e) {
            $execution_time = microtime(true) - $start_time;
            $this->logRouterError($e->getMessage(), $gateway, $input_data, $execution_time);
            throw $e;
        }
    }

    /**
     * Register a gateway adapter
     * 
     * @param GatewayEventAdapter $adapter Adapter to register
     * @return void
     */
    public function registerAdapter(GatewayEventAdapter $adapter): void
    {
        $gateway_name = $adapter->getGatewayName();
        $this->adapters[$gateway_name] = $adapter;
        
    }

    /**
     * Get available adapters
     * 
     * @return array Array of adapter information
     */
    public function getAvailableAdapters(): array
    {
        $adapters = [];
        
        foreach ($this->adapters as $gateway => $adapter) {
            $adapters[$gateway] = [
                'gateway' => $gateway,
                'class' => get_class($adapter),
                'supported_events' => $adapter->getSupportedEventTypes(),
                'status' => 'active',
            ];
        }

        return $adapters;
    }

    /**
     * Check if adapter exists for gateway
     * 
     * @param string $gateway Gateway name
     * @return bool True if adapter exists
     */
    public function hasAdapter(string $gateway): bool
    {
        return isset($this->adapters[$gateway]);
    }

    /**
     * Get adapter for specific gateway
     * 
     * @param string $gateway Gateway name
     * @return GatewayEventAdapter|null Adapter or null if not found
     */
    public function getAdapter(string $gateway): ?GatewayEventAdapter
    {
        return $this->adapters[$gateway] ?? null;
    }

    /**
     * Test adapter with sample data
     * 
     * @param string $gateway Gateway name
     * @param array $test_data Test data
     * @return array Test results
     */
    public function testAdapter(string $gateway, array $test_data): array
    {
        $adapter = $this->getAdapter($gateway);
        if (!$adapter) {
            return [
                'success' => false,
                'error' => "No adapter found for gateway: {$gateway}",
            ];
        }

        try {
            $can_handle = $adapter->canHandle($test_data);
            $idempotency_key = $adapter->computeIdempotencyKey($test_data);
            
            $result = [
                'success' => true,
                'gateway' => $gateway,
                'adapter_class' => get_class($adapter),
                'can_handle' => $can_handle,
                'idempotency_key' => $idempotency_key,
                'supported_events' => $adapter->getSupportedEventTypes(),
            ];

            if ($can_handle) {
                try {
                    $events = $adapter->normalize($test_data);
                    $result['normalized_events'] = count($events);
                    $result['events'] = array_map(function($event) {
                        return $event->toArray();
                    }, $events);
                } catch (\Throwable $e) {
                    $result['normalization_error'] = $e->getMessage();
                }
            }

            return $result;

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'gateway' => $gateway,
            ];
        }
    }

    /**
     * Select appropriate adapter for the input
     * 
     * @param string $gateway Requested gateway
     * @param array $input_data Input data
     * @return GatewayEventAdapter|null Selected adapter
     */
    private function selectAdapter(string $gateway, array $input_data): ?GatewayEventAdapter
    {
        // First, try the explicitly requested gateway
        if (isset($this->adapters[$gateway])) {
            $adapter = $this->adapters[$gateway];
            if ($adapter->canHandle($input_data)) {
                return $adapter;
            }
        }

        // If explicit gateway doesn't work, try all adapters
        foreach ($this->adapters as $adapter) {
            if ($adapter->canHandle($input_data)) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * Dispatch events to universal event processor
     * 
     * @param UniversalEvent[] $events Events to dispatch
     * @return void
     */
    private function dispatchEvents(array $events): void
    {
        if (!function_exists('as_enqueue_async_action')) {
            $this->logRouterError('Action Scheduler not available');
            return;
        }

        foreach ($events as $event) {
            // Add process ID for tracking - use shared process_id for order events
            $event_data = $event->toArray();
            
            // Use shared process_id for order lifecycle events, random for others
            if ($event->primaryObjectType === 'order' && !empty($event->primaryObjectID)) {
                $order_id = (int) $event->primaryObjectID;
                $event_data['process_id'] = ProcessIdManager::instance()->get_or_create_process_id($order_id);
            } else {
                $event_data['process_id'] = 'odcm_webhook_' . uniqid();
            }

            // Enqueue for async processing
            as_enqueue_async_action(
                'odcm_process_lifecycle_event',
                ['event' => $event_data],
                'odcm-webhooks'
            );

        }
    }

    /**
     * Register default adapters
     * 
     * @return void
     */
    private function registerDefaultAdapters(): void
    {
        // Register PayPal adapter
        $this->registerAdapter(new PayPalAdapter());
        
        // Register Generic adapter (fallback for unknown gateways)
        $this->registerAdapter(new GenericAdapter());

        // Future adapters can be registered here
        // $this->registerAdapter(new StripeAdapter());
        
        // Allow plugins to register custom adapters
        do_action('odcm_register_gateway_adapters', $this);
    }


    /**
     * Log router success
     * 
     * @param string $gateway Gateway name
     * @param int $events_count Number of events processed
     * @param float $execution_time Execution time
     * @return void
     */
    private function logRouterSuccess(string $gateway, int $events_count, float $execution_time): void
    {
        if (function_exists('odcm_log_event')) {
            odcm_log_event(
                sprintf('Event router processed %s events from %s gateway', $events_count, $gateway),
                [
                    'gateway' => $gateway,
                    'events_processed' => $events_count,
                    'execution_time' => $execution_time,
                    'component' => 'event_router',
                ],
                null,
                'success',
                'event_routing'
            );
        }

    }

    /**
     * Log router error
     * 
     * @param string $message Error message
     * @param string|null $gateway Gateway context
     * @param array $input_data Input data context
     * @param float|null $execution_time Execution time
     * @return void
     */
    private function logRouterError(string $message, ?string $gateway = null, array $input_data = [], ?float $execution_time = null): void
    {
        if (function_exists('odcm_log_event')) {
            $context = [
                'error_message' => $message,
                'component' => 'event_router',
            ];
            
            if ($gateway) {
                $context['gateway'] = $gateway;
            }
            
            if ($execution_time !== null) {
                $context['execution_time'] = $execution_time;
            }
            
            if (!empty($input_data)) {
                $context['input_summary'] = [
                    'has_headers' => !empty($input_data['headers']),
                    'has_payload' => !empty($input_data['payload']),
                    'user_agent' => $input_data['user_agent'] ?? 'unknown',
                    'ip_address' => $input_data['ip_address'] ?? 'unknown',
                ];
            }

            odcm_log_event(
                sprintf('Event router error: %s', $message),
                $context,
                null,
                'error',
                'event_routing'
            );
        }

        // Only log to debug log when debugging is enabled
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage(sprintf(
                'Event Router Error: %s%s',
                $message,
                $gateway ? " (Gateway: {$gateway})" : ''
            ));
        }
    }

    /**
     * Log debug messages using WordPress-friendly logging methods
     *
     * @param string $message The message to log
     * @return void
     */
    private function logDebugMessage(string $message): void
    {
        // Only log if debug is enabled
        if (!defined('ODCM_DEBUG') || !ODCM_DEBUG) {
            return;
        }
        
        // Prefix for all debug messages from this class
        $prefix = "ODCM_DEBUG: ";
        
        // Use WordPress logging function if available
        if (function_exists('odcm_log_message')) {
            odcm_log_message($prefix . $message, 'debug');
            return;
        }
        
        // Use WordPress debug log function if available
        if (function_exists('wp_debug_log')) {
            wp_debug_log($prefix . $message);
            return;
        }
        
        // Use WordPress action hook if available for centralized error handling
        if (function_exists('do_action')) {
            do_action('odcm_log_debug', $prefix . $message);
            return;
        }
        
        // If WP_DEBUG_LOG is enabled, write directly to the debug.log file using safe file operation
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $debug_file = odcm_get_safe_debug_file_path();
            odcm_safe_file_put_contents($debug_file, '[' . gmdate('Y-m-d H:i:s') . '] ' . $prefix . $message . PHP_EOL, FILE_APPEND);
        }
    }
}
