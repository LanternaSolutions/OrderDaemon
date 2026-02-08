<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Events;

/**
 * Universal Event Object (UEO)
 * 
 * Normalizes all lifecycle events (orders, subscriptions, payment gateways) into a 
 * consistent structure for rule processing and audit logging. This enables unified
 * handling of events from different sources while maintaining rich contextual data.
 * 
 * Event Type Taxonomy Examples:
 * - payment_created, payment_completed, payment_denied, payment_refunded, payment_reversed
 * - subscription_created, subscription_approved, subscription_reactivated, subscription_suspended, subscription_cancelled, subscription_completed
 * - renewal_payment_processing, renewal_payment_pending, renewal_payment_failed, renewal_payment_completed
 * - trial_started, trial_ended
 * - dispute_opened, dispute_won, dispute_lost
 * 
 * @package OrderDaemon\CompletionManager\Core\Events
 * @since   1.1.0
 */
final class UniversalEvent
{
    /**
     * Normalized event type from taxonomy
     * 
     * @var string
     */
    public $eventType;

    /**
     * Source gateway identifier (paypal, stripe, etc.) or null for system events
     * 
     * @var string|null
     */
    public $sourceGateway;

    /**
     * Channel through which the event was received
     * 
     * @var string
     */
    public $channel;

    /**
     * Primary entity type this event is about
     * 
     * @var string
     */
    public $primaryObjectType;

    /**
     * Primary entity ID
     * 
     * @var int|string|null
     */
    public $primaryObjectID;

    /**
     * Secondary/related entity type (optional)
     * 
     * @var string|null
     */
    public $secondaryObjectType;

    /**
     * Secondary/related entity ID (optional)
     * 
     * @var int|string|null
     */
    public $secondaryObjectID;

    /**
     * Gateway transaction/reference ID
     * 
     * @var string|null
     */
    public $transactionID;

    /**
     * Gateway/accounting status (COMPLETED, DENIED, etc.)
     * 
     * @var string|null
     */
    public $status;

    /**
     * Reason code or failure description
     * 
     * @var string|null
     */
    public $reason;

    /**
     * Transaction amount
     * 
     * @var float|null
     */
    public $amount;

    /**
     * Currency code (USD, EUR, etc.)
     * 
     * @var string|null
     */
    public $currency;

    /**
     * When the event occurred at the source (ISO8601)
     * 
     * @var string
     */
    public $occurredAt;

    /**
     * When the plugin received/ingested the event (ISO8601)
     * 
     * @var string
     */
    public $receivedAt;

    /**
     * Stable key for deduplication
     * 
     * @var string
     */
    public $idempotencyKey;

    /**
     * Unmodified source payload (sanitized for storage)
     * 
     * @var array
     */
    public $rawData;

    /**
     * UI components for timeline rendering.
     *
     * @var array
     */
    public $components;

    /**
     * Valid event channels
     */
    private const VALID_CHANNELS = ['webhook', 'ipn', 'sdk', 'manual', 'system', 'scheduled'];

    /**
     * Valid primary object types
     */
    private const VALID_OBJECT_TYPES = ['order', 'subscription', 'refund', 'authorization', 'membership', 'customer', 'product'];

    /**
     * Constructor with comprehensive validation
     * 
     * @param array $data Event data array
     * @throws \InvalidArgumentException If required fields are missing or invalid
     */
    public function __construct(array $data)
    {
        // DEBUG: Log constructor entry
        if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
            $this->logDebugMessage("UniversalEvent constructor started");
            $this->logDebugMessage("Input data keys: " . implode(', ', array_keys($data)));
        }

        try {
            // Validate and set required fields
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("Validating eventType: " . $this->formatDebugValue($data['eventType'] ?? ''));
            }
            $this->eventType = $this->validateEventType($data['eventType'] ?? '');

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("Validating channel: " . $this->formatDebugValue($data['channel'] ?? ''));
            }
            $this->channel = $this->validateChannel($data['channel'] ?? '');

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("Validating primaryObjectType: " . $this->formatDebugValue($data['primaryObjectType'] ?? ''));
            }
            $this->primaryObjectType = $this->validateObjectType($data['primaryObjectType'] ?? '');
            
            // Set optional fields with validation
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("Processing sourceGateway: " . $this->formatDebugValue($data['sourceGateway'] ?? null));
            }
            $this->sourceGateway = $this->sanitizeString($data['sourceGateway'] ?? null);

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("Validating primaryObjectID: " . $this->formatDebugValue($data['primaryObjectID'] ?? null));
            }
            $this->primaryObjectID = $this->validateObjectID($data['primaryObjectID'] ?? null);

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("Processing optional fields...");
            }
            $this->secondaryObjectType = $this->validateOptionalObjectType($data['secondaryObjectType'] ?? null);
            $this->secondaryObjectID = $this->validateObjectID($data['secondaryObjectID'] ?? null);
            $this->transactionID = $this->sanitizeString($data['transactionID'] ?? null);
            $this->status = $this->sanitizeString($data['status'] ?? null);
            $this->reason = $this->sanitizeString($data['reason'] ?? null);

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("Validating amount: " . $this->formatDebugValue($data['amount'] ?? null));
            }
            $this->amount = $this->validateAmount($data['amount'] ?? null);

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("Validating currency: " . $this->formatDebugValue($data['currency'] ?? null));
            }
            $this->currency = $this->validateCurrency($data['currency'] ?? null);
            
            // Set timestamps
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("Validating occurredAt timestamp: " . $this->formatDebugValue($data['occurredAt'] ?? ''));
            }
            $this->occurredAt = $this->validateTimestamp($data['occurredAt'] ?? '');

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("Validating receivedAt timestamp: " . $this->formatDebugValue($data['receivedAt'] ?? current_time('c')));
            }
            $this->receivedAt = $this->validateTimestamp($data['receivedAt'] ?? current_time('c'));
            
            // Set or generate idempotency key
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("Processing idempotencyKey: " . $this->formatDebugValue($data['idempotencyKey'] ?? ''));
            }
            $this->idempotencyKey = $this->validateIdempotencyKey($data['idempotencyKey'] ?? null);
                
            // Sanitize raw data
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("Processing rawData...");
            }
            $this->rawData = $this->sanitizeRawData($data['rawData'] ?? []);
            
            // Set UI components
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("Processing components...");
            }
            $this->components = isset($data['components']) && is_array($data['components']) ? $data['components'] : [];

            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("UniversalEvent constructor completed successfully");
            }

        } catch (\Throwable $e) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("CONSTRUCTOR FAILED - " . get_class($e) . ": " . $e->getMessage());
                $this->logDebugMessage("Error in file: " . $e->getFile() . " at line: " . $e->getLine());
                $this->logDebugMessage("Stack trace: " . $e->getTraceAsString());
            }
            throw $e; // Re-throw the original exception
        }
    }

    /**
     * Convert to array for serialization
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'eventType' => $this->eventType,
            'sourceGateway' => $this->sourceGateway,
            'channel' => $this->channel,
            'primaryObjectType' => $this->primaryObjectType,
            'primaryObjectID' => $this->primaryObjectID,
            'secondaryObjectType' => $this->secondaryObjectType,
            'secondaryObjectID' => $this->secondaryObjectID,
            'transactionID' => $this->transactionID,
            'status' => $this->status,
            'reason' => $this->reason,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'occurredAt' => $this->occurredAt,
            'receivedAt' => $this->receivedAt,
            'idempotencyKey' => $this->idempotencyKey,
            'rawData' => $this->rawData,
            'components' => $this->components,
        ];
    }

    /**
     * Create from array (for deserialization)
     * 
     * @param array $data Event data array
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Validate the event is properly formed
     * 
     * @return bool
     */
    public function isValid(): bool
    {
        try {
            // Check required fields are present and valid
            if (empty($this->eventType) || empty($this->channel) || empty($this->primaryObjectType)) {
                return false;
            }
            
            // Validate timestamps are proper ISO8601
            if (!$this->isValidISO8601($this->occurredAt) || !$this->isValidISO8601($this->receivedAt)) {
                return false;
            }
            
            // Validate idempotency key is present
            if (empty($this->idempotencyKey)) {
                return false;
            }
            
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Generate a stable idempotency key for deduplication
     * 
     * @return string
     */
    public function generateIdempotencyKey(): string
    {
        $components = [
            $this->sourceGateway ?? 'system',
            $this->eventType,
            $this->primaryObjectType,
            (string) $this->primaryObjectID,
            $this->transactionID ?? '',
            $this->occurredAt,
        ];
        
        return 'odcm_event_' . substr(md5(implode('|', $components)), 0, 16);
    }

    /**
     * Get a human-readable summary of this event
     * 
     * @return string
     */
    public function getSummary(): string
    {
        // Create business-focused summary based on event type and context
        $gateway = $this->sourceGateway ? ucfirst($this->sourceGateway) : '';
        $humanEvent = $this->humanizeEventType($this->eventType);
        $amount = ($this->amount !== null && $this->currency) 
            ? "{$this->currency} " . number_format($this->amount, 2) 
            : '';
        
        // Build context-aware summary
        if ($this->primaryObjectType === 'order' && $this->primaryObjectID) {
            $orderRef = "Order #{$this->primaryObjectID}";
            
            // Payment-related events
            if (strpos($this->eventType, 'payment') !== false) {
                if ($amount) {
                    return trim("{$gateway} payment of {$amount} {$this->getStatusDescription()} for {$orderRef}");
                } else {
                    return trim("{$gateway} payment {$this->getStatusDescription()} for {$orderRef}");
                }
            }
            
            // Subscription events
            if (strpos($this->eventType, 'subscription') !== false) {
                if ($amount) {
                    return trim("{$gateway} subscription ({$amount}) {$this->getStatusDescription()} for {$orderRef}");
                } else {
                    return trim("{$gateway} subscription {$this->getStatusDescription()} for {$orderRef}");
                }
            }
            
            // Refund events
            if (strpos($this->eventType, 'refund') !== false) {
                if ($amount) {
                    return trim("{$gateway} refund of {$amount} {$this->getStatusDescription()} for {$orderRef}");
                } else {
                    return trim("{$gateway} refund {$this->getStatusDescription()} for {$orderRef}");
                }
            }
            
            // Generic order events with amount
            if ($amount) {
                return trim("{$gateway} {$humanEvent} ({$amount}) for {$orderRef}");
            } else {
                return trim("{$gateway} {$humanEvent} for {$orderRef}");
            }
        }
        
        // Non-order events or events without order context
        $parts = [];
        
        if ($gateway) {
            $parts[] = $gateway;
        }
        
        $parts[] = $humanEvent;
        
        if ($this->primaryObjectID) {
            $parts[] = "#{$this->primaryObjectID}";
        }
        
        if ($amount) {
            $parts[] = "({$amount})";
        }
        
        $summary = implode(' ', $parts);
        
        // Add status context if available
        if ($this->status && $this->status !== 'COMPLETED') {
            $summary .= " - " . $this->getStatusDescription();
        }
        
        return $summary;
    }

    /**
     * Validate event type
     * 
     * @param string $eventType
     * @return string
     * @throws \InvalidArgumentException
     */
    private function validateEventType(string $eventType): string
    {
        $eventType = sanitize_key($eventType);
        if (empty($eventType)) {
            throw new \InvalidArgumentException('Event type is required');
        }
        return $eventType;
    }

    /**
     * Validate source gateway
     * 
     * @param string|null $sourceGateway
     * @return string|null
     * @throws \InvalidArgumentException
     */
    private function validateSourceGateway(?string $sourceGateway): ?string
    {
        if ($sourceGateway === null) {
            return null;
        }

        $sanitized = sanitize_text_field($sourceGateway);
        if ($sanitized === '') {
            throw new \InvalidArgumentException('Source gateway cannot be empty');
        }
        return $sanitized;
    }

    /**
     * Validate idempotency key
     * 
     * @param string|null $idempotencyKey
     * @return string
     * @throws \InvalidArgumentException
     */
    private function validateIdempotencyKey(?string $idempotencyKey): string
    {
        if (empty($idempotencyKey)) {
            throw new \InvalidArgumentException('Idempotency key is required');
        }

        return sanitize_text_field((string) $idempotencyKey);
    }

    /**
     * Validate channel
     * 
     * @param string $channel
     * @return string
     * @throws \InvalidArgumentException
     */
    private function validateChannel(string $channel): string
    {
        $channel = sanitize_key($channel);
        if (!in_array($channel, self::VALID_CHANNELS, true)) {
            throw new \InvalidArgumentException("Invalid channel: " . esc_html($channel) . ". Must be one of: " . esc_html(implode(', ', self::VALID_CHANNELS)));
        }
        return $channel;
    }

    /**
     * Validate object type
     * 
     * @param string $objectType
     * @return string
     * @throws \InvalidArgumentException
     */
    private function validateObjectType(string $objectType): string
    {
        $objectType = sanitize_key($objectType);
        if (!in_array($objectType, self::VALID_OBJECT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid object type: " . esc_html($objectType) . ". Must be one of: " . esc_html(implode(', ', self::VALID_OBJECT_TYPES)));
        }
        return $objectType;
    }

    /**
     * Validate optional object type
     * 
     * @param string|null $objectType
     * @return string|null
     */
    private function validateOptionalObjectType(?string $objectType): ?string
    {
        if ($objectType === null) {
            return null;
        }
        return $this->validateObjectType($objectType);
    }

    /**
     * Validate object ID
     * 
     * @param mixed $objectID
     * @return int|string|null
     */
    /**
     * Validates object ID and returns validated value.
     *
     * @param mixed $objectID The object ID to validate
     * @return int|string|null The validated object ID
     */
    private function validateObjectID($objectID)
    {
        if ($objectID === null || $objectID === '') {
            return null;
        }
        
        if (is_numeric($objectID)) {
            return (int) $objectID;
        }
        
        if (is_string($objectID)) {
            return sanitize_text_field($objectID);
        }
        
        return null;
    }

    /**
     * Validate amount
     * 
     * @param mixed $amount
     * @return float|null
     */
    private function validateAmount($amount): ?float
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        
        if (is_numeric($amount)) {
            return (float) $amount;
        }
        
        return null;
    }

    /**
     * Validate currency code
     * 
     * @param string|null $currency
     * @return string|null
     */
    private function validateCurrency(?string $currency): ?string
    {
        if ($currency === null) {
            return null;
        }
        
        $currency = strtoupper(sanitize_text_field($currency));
        
        // Basic validation - 3 character currency codes
        if (strlen($currency) === 3 && ctype_alpha($currency)) {
            return $currency;
        }
        
        return null;
    }

    /**
     * Validate timestamp
     * 
     * @param string $timestamp
     * @return string
     * @throws \InvalidArgumentException
     */
    private function validateTimestamp(string $timestamp): string
    {
        if (empty($timestamp)) {
            throw new \InvalidArgumentException('Timestamp is required');
        }
        
        // Try to parse as ISO8601
        if ($this->isValidISO8601($timestamp)) {
            return $timestamp;
        }
        
        // Try to convert from Unix timestamp
        if (is_numeric($timestamp)) {
            return gmdate('c', (int) $timestamp);
        }
        
        throw new \InvalidArgumentException("Invalid timestamp format: " . esc_html($timestamp));
    }

    /**
     * Check if string is valid ISO8601 timestamp
     * 
     * @param string $timestamp
     * @return bool
     */
    private function isValidISO8601(string $timestamp): bool
    {
        try {
            $date = new \DateTime($timestamp);
            return $date->format('c') !== false;
        } catch (\Exception $e) {
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $this->logDebugMessage("Invalid ISO8601 timestamp: " . $timestamp);
            }
            return false;
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
        $prefix = "ODCM_CONSTRUCTOR_DEBUG: ";
        
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
        
        // If WP_DEBUG_LOG is enabled, write directly to the debug.log file
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $debug_file = odcm_get_uploads_dir() . '/debug.log';
            @file_put_contents(
                $debug_file,
                '[' . gmdate('Y-m-d H:i:s') . '] ' . $prefix . $message . PHP_EOL,
                FILE_APPEND
            );
        }
    }
    
    /**
     * Format a value for debug output without using var_export
     *
     * @param mixed $value The value to format
     * @return string Formatted value suitable for debug logs
     */
    private function formatDebugValue($value): string
    {
        if (is_null($value)) {
            return 'null';
        }
        
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        
        if (is_string($value)) {
            return '"' . $value . '"';
        }
        
        if (is_numeric($value)) {
            return (string)$value;
        }
        
        if (is_array($value)) {
            return '[array with ' . count($value) . ' elements]';
        }
        
        if (is_object($value)) {
            return '[object of class ' . get_class($value) . ']';
        }
        
        return '[' . gettype($value) . ']';
    }

    /**
     * Sanitize string field
     * 
     * @param string|null $value
     * @return string|null
     */
    private function sanitizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        
        $sanitized = sanitize_text_field($value);
        return $sanitized !== '' ? $sanitized : null;
    }

    /**
     * Sanitize raw data array
     * 
     * @param array $rawData
     * @return array
     */
    private function sanitizeRawData(array $rawData): array
    {
        // Recursively sanitize array data
        return $this->sanitizeArrayRecursive($rawData);
    }

    /**
     * Recursively sanitize array data
     * 
     * @param array $data
     * @return array
     */
    private function sanitizeArrayRecursive(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            $key = sanitize_key((string) $key);
            
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArrayRecursive($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = sanitize_text_field($value);
            } elseif (is_numeric($value)) {
                $sanitized[$key] = $value;
            } elseif (is_bool($value)) {
                $sanitized[$key] = $value;
            } else {
                // Convert other types to string and sanitize
                $sanitized[$key] = sanitize_text_field((string) $value);
            }
        }
        
        return $sanitized;
    }

    /**
     * Get human-readable status description
     * 
     * @return string
     */
    private function getStatusDescription(): string
    {
        if (!$this->status) {
            return 'processed';
        }
        
        // Map common gateway statuses to user-friendly descriptions
        $statusMap = [
            'COMPLETED' => 'completed',
            'SUCCESS' => 'completed',
            'APPROVED' => 'approved',
            'PENDING' => 'pending',
            'PROCESSING' => 'processing',
            'DENIED' => 'denied',
            'FAILED' => 'failed',
            'CANCELLED' => 'cancelled',
            'CANCELED' => 'cancelled',
            'REFUNDED' => 'refunded',
            'PARTIALLY_REFUNDED' => 'partially refunded',
            'DISPUTED' => 'disputed',
            'REVERSED' => 'reversed',
            'EXPIRED' => 'expired',
        ];
        
        $upperStatus = strtoupper($this->status);
        
        if (isset($statusMap[$upperStatus])) {
            return $statusMap[$upperStatus];
        }
        
        // Convert other statuses to lowercase and replace underscores
        return strtolower(str_replace('_', ' ', $this->status));
    }

    /**
     * Convert event type to human readable format
     * 
     * @param string $eventType
     * @return string
     */
    private function humanizeEventType(string $eventType): string
    {
        // Convert snake_case to Title Case
        $words = explode('_', $eventType);
        $words = array_map('ucfirst', $words);
        return implode(' ', $words);
    }
}
