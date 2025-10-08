<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Events;

/**
 * Gateway Event Adapter Interface
 * 
 * Defines the contract for normalizing gateway-specific events (PayPal IPN, 
 * Stripe webhooks, etc.) into universal events. Each gateway adapter implements
 * this interface to handle the specific payload formats and authentication
 * requirements of their respective payment gateways.
 * 
 * @package OrderDaemon\CompletionManager\Core\Events
 * @since   1.1.1
 */
interface GatewayEventAdapter
{
    /**
     * Determine if this adapter can handle the given input
     * 
     * Examines headers, payload structure, and other indicators to determine
     * if this adapter is capable of processing the incoming event data.
     * 
     * @param array $input Raw input data including headers and payload
     * @return bool True if this adapter can handle the input
     */
    public function canHandle(array $input): bool;

    /**
     * Normalize gateway-specific event data into UniversalEvent objects
     * 
     * Converts the raw gateway payload into one or more UniversalEvent objects.
     * Some gateway events may represent multiple logical events (e.g., a single
     * webhook containing both payment and subscription updates).
     * 
     * @param array $input Raw input data including headers and payload
     * @return UniversalEvent[] Array of normalized universal events
     * @throws \InvalidArgumentException If input cannot be normalized
     */
    public function normalize(array $input): array;

    /**
     * Compute a stable idempotency key for deduplication
     * 
     * Generates a unique, stable key that can be used to prevent duplicate
     * processing of the same gateway event. The key should be deterministic
     * based on the event content.
     * 
     * @param array $input Raw input data including headers and payload
     * @return string Stable idempotency key
     */
    public function computeIdempotencyKey(array $input): string;

    /**
     * Identify and resolve related entities (orders, subscriptions, customers)
     * 
     * Enhances the UniversalEvent with resolved entity IDs by mapping gateway
     * transaction IDs, customer IDs, and other identifiers to WooCommerce entities.
     * 
     * @param UniversalEvent $event Event to enhance with entity information
     * @return UniversalEvent Enhanced event with resolved entity IDs
     */
    public function identifyEntities(UniversalEvent $event): UniversalEvent;

    /**
     * Validate the authenticity of the incoming event
     * 
     * Performs cryptographic verification of webhook signatures, IPN validation,
     * or other authentication mechanisms specific to the gateway.
     * 
     * @param array $input Raw input data including headers and payload
     * @return bool True if the event is authentic
     */
    public function validateAuthenticity(array $input): bool;

    /**
     * Get the gateway name this adapter handles
     * 
     * @return string Gateway identifier (e.g., 'paypal', 'stripe')
     */
    public function getGatewayName(): string;

    /**
     * Get supported event types for this gateway
     * 
     * @return array Array of event types this adapter can process
     */
    public function getSupportedEventTypes(): array;

    /**
     * Extract gateway-specific metadata from the event
     * 
     * Extracts additional context and metadata that may be useful for
     * rule evaluation or audit logging.
     * 
     * @param array $input Raw input data
     * @return array Gateway-specific metadata
     */
    public function extractMetadata(array $input): array;
}
