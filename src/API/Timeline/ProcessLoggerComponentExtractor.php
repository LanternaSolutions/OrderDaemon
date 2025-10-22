<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Component extractor for ProcessLogger format payloads
 * 
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.0.0
 */
final class ProcessLoggerComponentExtractor implements ComponentExtractorInterface
{
    /**
     * Extract normalized components from raw payload data
     */
    public function extractComponents(array $rawPayload, bool $includeDebug): array
    {
        if ($this->isProcessLoggerFormat($rawPayload)) {
            return $this->extractProcessLoggerComponents($rawPayload, $includeDebug);
        }
        
        // For non-ProcessLogger format, we'll handle this in the synthetic component creation
        return [];
    }
    
    /**
     * Check if payload contains ProcessLogger format data
     */
    public function isProcessLoggerFormat(array $rawPayload): bool
    {
        return isset($rawPayload['components']) && 
               is_array($rawPayload['components']) && 
               !empty($rawPayload['components']);
    }
    
    /**
     * Create synthetic component from legacy or empty payload
     * Creates renderer-compatible data structure using event_type directly
     */
    public function createSyntheticComponent(array $logEntry): array
    {
        $summary = $logEntry['summary'] ?? 'Log Entry';
        $timestamp = $logEntry['timestamp'] ?? current_time('mysql');
        $status = $logEntry['status'] ?? 'info';
        $eventType = $logEntry['event_type'] ?? 'info';
        $source = $logEntry['source'] ?? 'system';
        
        // Build renderer-compatible data structure
        $data = [
            'summary' => $summary,
            'event_type' => $eventType,
            'source' => $source,
            'log_id' => $logEntry['id'] ?? 0,
            'status' => $status,
        ];
        
        // Add order context in renderer-expected format
        if (!empty($logEntry['order_id'])) {
            $data['order_id'] = (int) $logEntry['order_id'];
            
            // Add additional WooCommerce-compatible keys for renderer detection
            if ($this->isWooCommerceRelated($summary, $eventType)) {
                $data['order'] = ['id' => (int) $logEntry['order_id']];
                $data['woocommerce'] = true;
            }
        }
        
        // Add rule-related data for rule renderers
        if ($this->isRuleRelated($summary, $eventType)) {
            $data['rule_evaluation'] = true;
            if (strpos($summary, 'rule') !== false) {
                $data['rule_id'] = $this->extractRuleIdFromSummary($summary);
            }
        }
        
        // Add error context for error renderers
        if ($status === 'error' || strpos($summary, 'error') !== false) {
            $data['error'] = $summary;
            $data['error_type'] = 'system_error';
        }
        
        // Add payment gateway context for gateway renderers
        if (strpos($summary, 'stripe') !== false) {
            $data['stripe_event'] = true;
            $data['gateway'] = 'stripe';
        } elseif (strpos($summary, 'paypal') !== false) {
            $data['paypal_event'] = true;
            $data['gateway'] = 'paypal';
        }
        
        // Include raw payload if it exists but isn't ProcessLogger format
        $payloadRaw = $logEntry['payload'] ?? '';
        if (!empty($payloadRaw)) {
            $payloadData = json_decode($payloadRaw, true);
            if (is_array($payloadData) && !$this->isProcessLoggerFormat($payloadData)) {
                $data['legacy_payload'] = $payloadData;
            }
        }
        
        return [
            'event_type' => $eventType,
            'label' => $summary,
            'ts' => $timestamp,
            'level' => $status,
            'data' => $data,
        ];
    }
    
    /**
     * Check if event is WooCommerce-related
     */
    private function isWooCommerceRelated(string $summary, string $eventType): bool
    {
        $wooKeywords = ['order', 'product', 'customer', 'woocommerce', 'status', 'payment', 'shipping'];
        $text = strtolower($summary . ' ' . $eventType);
        
        foreach ($wooKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if event is rule-related
     */
    private function isRuleRelated(string $summary, string $eventType): bool
    {
        $ruleKeywords = ['rule', 'condition', 'evaluation', 'match', 'decision'];
        $text = strtolower($summary . ' ' . $eventType);
        
        foreach ($ruleKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract rule ID from summary text
     */
    private function extractRuleIdFromSummary(string $summary): ?int
    {
        if (preg_match('/rule\s+#?(\d+)/i', $summary, $matches)) {
            return (int) $matches[1];
        }
        
        return null;
    }
    
    /**
     * Extract components from ProcessLogger format payload
     */
    private function extractProcessLoggerComponents(array $rawPayload, bool $includeDebug): array
    {
        $components = $rawPayload['components'];
        $extractedComponents = [];
        
        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }
            
            // Apply debug filtering
            if (!$includeDebug && $this->isDebugComponent($component)) {
                continue;
            }
            
            // Validate component structure
            if (!$this->isValidComponent($component)) {
                continue;
            }
            
            // Normalize component structure
            $normalizedComponent = $this->normalizeComponent($component);
            $extractedComponents[] = $normalizedComponent;
        }
        
        return $extractedComponents;
    }
    
    /**
     * Check if component is debug-level
     */
    private function isDebugComponent(array $component): bool
    {
        $level = $component['level'] ?? '';
        $event_type = $component['event_type'] ?? '';
        
        return ($level === 'debug') || ($event_type === 'process_started');
    }
    
    /**
     * Validate component has required structure
     */
    private function isValidComponent(array $component): bool
    {
        return isset($component['event_type']) && 
               isset($component['data']) && 
               is_array($component['data']) &&
               !empty($component['data']);
    }
    
    /**
     * Normalize component structure to ensure consistency
     */
    private function normalizeComponent(array $component): array
    {
        $ts = $component['ts'] ?? current_time('mysql');
        
        // Normalize timestamp to MySQL format if it's a Unix timestamp
        if (is_numeric($ts)) {
            $ts = date('Y-m-d H:i:s', (int)$ts);
        }
        
        return [
            'event_type' => $component['event_type'],
            'label' => $component['label'] ?? ucfirst($component['event_type']),
            'ts' => $ts,
            'level' => $component['level'] ?? 'info',
            'data' => $component['data'],
        ];
    }
    
}
