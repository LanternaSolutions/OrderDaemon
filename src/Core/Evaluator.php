<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

use OrderDaemon\CompletionManager\Core\RuleComponents\RuleComponentRegistry;
use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ConditionInterface;
use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ActionInterface;
use OrderDaemon\CompletionManager\Core\Events\EvaluationContext;
use WC_Order;

/**
 * Evaluator: JSON-only rule evaluation engine.
 *
 * Consumes the _odcm_rule_data JSON structure at runtime and evaluates conditions
 * using the component registry. It sanitizes settings by schema, enforces
 * capabilities, and returns a per-condition trace with a final matched flag.
 *
 * @package OrderDaemon\CompletionManager\Core
 */
final class Evaluator
{
    /**
     * Optional process logger for user-visible narrative logging.
     *
     * @var \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger|null
     */
    private ?\OrderDaemon\CompletionManager\Core\Logging\ProcessLogger $process_logger = null;

    /**
     * Inject a ProcessLogger instance for recording evaluation details.
     *
     * @param \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger $process_logger Process logger instance.
     * @return void
     */
    public function set_process_logger(\OrderDaemon\CompletionManager\Core\Logging\ProcessLogger $process_logger): void
    {
        $this->process_logger = $process_logger;
    }

    /**
     * Evaluate a single rule structure against an order.
     *
     * @param WC_Order               $order    WooCommerce order.
     * @param array                  $rule     Decoded rule array from _odcm_rule_data.
     * @param RuleComponentRegistry  $registry Component registry.
     * @return array{matched:bool, conditions:array<int, array{id:string,label:string,result:string,reason?:string}>, errors?:array}
     */
    public function evaluateRuleAgainstOrder(WC_Order $order, array $rule, RuleComponentRegistry $registry): array
    {
        $trace = [
            'matched' => false,
            'conditions' => [],
        ];

        $conditions = $rule['conditions'] ?? [];
        if (!is_array($conditions) || count($conditions) === 0) {
            // No conditions means match-all
            $trace['matched'] = true;
            return $trace;
        }

        $registered = $registry->get_conditions();

        foreach ($conditions as $idx => $cond) {
            $id = is_array($cond) ? ($cond['id'] ?? '') : '';
            if (!is_string($id) || $id === '' || !isset($registered[$id])) {
                $trace['conditions'][] = [
                    'id' => (string)$id,
                    'label' => 'Unknown condition',
                    'result' => 'fail',
                    'reason' => 'unknown_component',
                ];
                $trace['matched'] = false;
                return $trace; // Fail closed
            }

            $component = $registered[$id];

            $schema = $component->get_settings_schema();
            $rawSettings = is_array($cond['settings'] ?? null) ? $cond['settings'] : [];
            $clean = $this->sanitize_by_schema($rawSettings, $schema);

            try {
                $passed = $component->evaluate($order, $clean);
            } catch (\Throwable $e) {
                $trace['conditions'][] = [
                    'id' => $id,
                    'label' => $component->get_label(),
                    'result' => 'fail',
                    'reason' => 'exception',
                ];
                $trace['matched'] = false;
                return $trace;
            }

            // User-visible condition evaluation logging (Glass Box)
            $expected_value = $this->extractExpectedValue($clean);
            $actual_value   = $this->extractActualValue($order, $component, $clean);
            $operator       = $this->extractComparisonOperator($clean);
            $this->logConditionEvaluation($order, $component, (bool)$passed, $expected_value, $actual_value, $operator);

            $trace['conditions'][] = [
                'id' => $id,
                'label' => $component->get_label(),
                'result' => $passed ? 'pass' : 'fail',
            ];

            if (!$passed) {
                $trace['matched'] = false;
                return $trace; // Short-circuit on first failure
            }
        }

        $trace['matched'] = true;
        return $trace;
    }

    /**
     * Sanitize settings array according to provided JSON-like schema.
     *
     * @param array      $settings
     * @param array|null $schema JSON schema-like definition from component.
     * @return array Sanitized settings.
     */
    public function sanitize_by_schema(array $settings, ?array $schema): array
    {
        if (!$schema || !isset($schema['properties']) || !is_array($schema['properties'])) {
            // No schema, fallback sanitize scalars
            return $this->shallow_sanitize($settings);
        }

        $props = $schema['properties'];
        $out = [];
        foreach ($props as $key => $prop) {
            $type = $prop['type'] ?? null;
            if (!array_key_exists($key, $settings)) {
                // Use default if provided
                if (array_key_exists('default', $prop)) {
                    $out[$key] = $prop['default'];
                }
                continue;
            }
            $val = $settings[$key];

            switch ($type) {
                case 'string':
                    $out[$key] = is_string($val) ? sanitize_text_field($val) : '';
                    break;
                case 'integer':
                    $out[$key] = absint(is_numeric($val) ? (int)$val : 0);
                    break;
                case 'number':
                    $num = is_numeric($val) ? (float)$val : 0.0;
                    // Round monetary-like numbers using WooCommerce decimals when appropriate
                    $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
                    $out[$key] = round($num, $decimals);
                    break;
                case 'boolean':
                    $out[$key] = (bool)$val;
                    break;
                case 'array':
                    $items = $prop['items'] ?? [];
                    $itemType = $items['type'] ?? 'string';
                    $arr = is_array($val) ? $val : [];
                    $cleanArr = [];
                    foreach ($arr as $item) {
                        switch ($itemType) {
                            case 'integer':
                                $cleanArr[] = absint(is_numeric($item) ? (int)$item : 0);
                                break;
                            case 'number':
                                $n = is_numeric($item) ? (float)$item : 0.0;
                                $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
                                $cleanArr[] = round($n, $decimals);
                                break;
                            case 'boolean':
                                $cleanArr[] = (bool)$item;
                                break;
                            default:
                                $cleanArr[] = is_string($item) ? sanitize_text_field($item) : '';
                        }
                    }
                    // Remove empties, uniques, reindex
                    $cleanArr = array_values(array_unique(array_filter($cleanArr, static function($v) {
                        return $v !== '' && $v !== null;
                    })));
                    $out[$key] = $cleanArr;
                    break;
                default:
                    // Unknown type: best-effort sanitize
                    $out[$key] = is_scalar($val) ? sanitize_text_field((string)$val) : $val;
            }
        }

        return $out;
    }

    /**
     * Shallow sanitize for settings without schema.
     *
     * @param array $settings
     * @return array
     */
    private function shallow_sanitize(array $settings): array
    {
        $out = [];
        foreach ($settings as $k => $v) {
            if (is_string($v)) {
                $out[$k] = sanitize_text_field($v);
            } elseif (is_bool($v)) {
                $out[$k] = (bool)$v;
            } elseif (is_int($v)) {
                $out[$k] = absint($v);
            } elseif (is_float($v)) {
                $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
                $out[$k] = round($v, $decimals);
            } elseif (is_array($v)) {
                $out[$k] = $this->shallow_sanitize($v);
            }
        }
        return $out;
    }

    /**
     * Extract expected value for logging from condition settings.
     * Attempts to locate a primary 'value' or 'values' field.
     *
     * @param array $settings Sanitized condition settings.
     * @return mixed
     */
    private function extractExpectedValue(array $settings)
    {
        if (array_key_exists('value', $settings)) {
            return $settings['value'];
        }
        if (array_key_exists('values', $settings)) {
            return $settings['values'];
        }
        if (array_key_exists('amount', $settings)) {
            return $settings['amount'];
        }
        if (array_key_exists('status', $settings)) {
            return $settings['status'];
        }
        return null;
    }

    /**
     * Attempt to extract the actual value from the order based on the condition.
     * This is a best-effort approach that avoids re-evaluation and heavy logic.
     *
     * @param WC_Order          $order
     * @param ConditionInterface $component
     * @param array             $settings
     * @return mixed
     */
    private function extractActualValue(WC_Order $order, ConditionInterface $component, array $settings)
    {
        // Best-effort heuristics based on common condition patterns
        $id = method_exists($component, 'get_id') ? (string) $component->get_id() : '';
        if ($id === 'order_status' || isset($settings['status'])) {
            return $order->get_status();
        }
        if ($id === 'order_total' || isset($settings['amount'])) {
            return (float) $order->get_total();
        }
        // Unknown condition type: return null to avoid misleading data
        return null;
    }

    /**
     * Extract a human-readable comparison operator from settings.
     *
     * @param array $settings
     * @return string|null
     */
    private function extractComparisonOperator(array $settings): ?string
    {
        $op = null;
        if (isset($settings['operator'])) {
            $op = (string) $settings['operator'];
        } elseif (isset($settings['comparison'])) {
            $op = (string) $settings['comparison'];
        }
        if ($op === null) {
            return null;
        }
        $map = [
            'eq' => '=', 'equals' => '=', '==' => '=',
            'ne' => '≠', '!=' => '≠', 'not_equals' => '≠',
            'gt' => '>', '>' => '>',
            'gte' => '≥', '>=' => '≥',
            'lt' => '<', '<' => '<',
            'lte' => '≤', '<=' => '≤',
            'in' => 'in', 'not_in' => 'not in',
            'contains' => 'contains', 'matches' => 'matches'
        ];
        $key = strtolower($op);
        return $map[$key] ?? $op;
    }

    /**
     * Format values for logging, using WooCommerce formatting for monetary values when applicable.
     *
     * @param mixed $value
     * @return string
     */
    private function formatValueForLogging($value): string
    {
        if (is_null($value)) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value)) {
            // Heuristic: large numeric with decimals could be money; we avoid currency conversion and just return number.
            return (string) (float) $value;
        }
        if (is_array($value)) {
            // Join array into comma-separated list for readability
            $flat = array_map(static function($v) {
                return is_scalar($v) ? (string)$v : (string) wp_json_encode($v);
            }, $value);
            return implode(', ', $flat);
        }
        return (string)$value;
    }

    /**
     * Log a condition evaluation component via ProcessLogger when available.
     *
     * @param WC_Order           $order
     * @param ConditionInterface $component
     * @param bool               $passed
     * @param mixed              $expected
     * @param mixed              $actual
     * @param string|null        $operator
     * @return void
     */
    private function logConditionEvaluation(WC_Order $order, ConditionInterface $component, bool $passed, $expected, $actual, ?string $operator): void
    {
        if (!$this->process_logger) {
            return;
        }
        $order_id = $order->get_id();
        $kind  = $passed ? 'condition_passed' : 'condition_failed';
        $label = $component->get_label();
        $data  = [
            'order_id'         => (int) $order_id,
            'condition_label'  => (string) $label,
            'operator'         => $operator !== null ? (string) $operator : '',
            'expected_value'   => $this->formatValueForLogging($expected),
            'actual_value'     => $this->formatValueForLogging($actual),
            'result'           => $passed ? 'pass' : 'fail',
        ];
        $this->process_logger->add_component($kind, $label, $data, 'info');
    }

    /**
     * Evaluate a single rule structure against a universal event context.
     *
     * This method extends the existing rule evaluation to handle universal events
     * from payment gateways. It maintains the same "First Match Wins" logic and
     * condition evaluation framework while adding support for event-driven conditions.
     *
     * @param EvaluationContext     $context  Universal event context with entities
     * @param array                 $rule     Decoded rule array from _odcm_rule_data
     * @param RuleComponentRegistry $registry Component registry
     * @return array{matched:bool, conditions:array<int, array{id:string,label:string,result:string,reason?:string}>, errors?:array}
     */
    public function evaluateRuleAgainstUniversalEvent(EvaluationContext $context, array $rule, RuleComponentRegistry $registry): array
    {
        $trace = [
            'matched' => false,
            'conditions' => [],
        ];

        $conditions = $rule['conditions'] ?? [];
        if (!is_array($conditions) || count($conditions) === 0) {
            // No conditions means match-all
            $trace['matched'] = true;
            return $trace;
        }

        $registered = $registry->get_conditions();

        foreach ($conditions as $idx => $cond) {
            $id = is_array($cond) ? ($cond['id'] ?? '') : '';
            if (!is_string($id) || $id === '' || !isset($registered[$id])) {
                $trace['conditions'][] = [
                    'id' => (string)$id,
                    'label' => 'Unknown condition',
                    'result' => 'fail',
                    'reason' => 'unknown_component',
                ];
                $trace['matched'] = false;
                return $trace; // Fail closed
            }

            $component = $registered[$id];
            
            $schema = $component->get_settings_schema();
            $rawSettings = is_array($cond['settings'] ?? null) ? $cond['settings'] : [];
            $clean = $this->sanitize_by_schema($rawSettings, $schema);

            try {
                // Check if component supports universal events
                if (method_exists($component, 'evaluateUniversalEvent')) {
                    $passed = $component->evaluateUniversalEvent($context, $clean);
                } elseif ($context->order) {
                    // Fallback to legacy evaluation if order is available
                    $passed = $component->evaluate($context->order, $clean);
                } else {
                    // Component doesn't support universal events and no order available
                    $trace['conditions'][] = [
                        'id' => $id,
                        'label' => $component->get_label(),
                        'result' => 'fail',
                        'reason' => 'no_universal_event_support',
                    ];
                    $trace['matched'] = false;
                    return $trace;
                }
            } catch (\Throwable $e) {
                $trace['conditions'][] = [
                    'id' => $id,
                    'label' => $component->get_label(),
                    'result' => 'fail',
                    'reason' => 'exception',
                ];
                $trace['matched'] = false;
                return $trace;
            }

            // User-visible condition evaluation logging for universal events
            $expected_value = $this->extractExpectedValueFromUniversalEvent($clean, $context);
            $actual_value = $this->extractActualValueFromUniversalEvent($context, $component, $clean);
            $operator = $this->extractComparisonOperator($clean);
            $this->logUniversalEventConditionEvaluation($context, $component, (bool)$passed, $expected_value, $actual_value, $operator);

            $trace['conditions'][] = [
                'id' => $id,
                'label' => $component->get_label(),
                'result' => $passed ? 'pass' : 'fail',
            ];

            if (!$passed) {
                $trace['matched'] = false;
                return $trace; // Short-circuit on first failure
            }
        }

        $trace['matched'] = true;
        return $trace;
    }

    /**
     * Extract expected value for logging from universal event condition settings.
     *
     * @param array $settings Sanitized condition settings
     * @param EvaluationContext $context Universal event context
     * @return mixed
     */
    private function extractExpectedValueFromUniversalEvent(array $settings, EvaluationContext $context)
    {
        // Try standard value fields first
        $value = $this->extractExpectedValue($settings);
        if ($value !== null) {
            return $value;
        }

        // Try universal event specific fields
        if (array_key_exists('event_types', $settings)) {
            return $settings['event_types'];
        }
        if (array_key_exists('gateways', $settings)) {
            return $settings['gateways'];
        }
        if (array_key_exists('statuses', $settings)) {
            return $settings['statuses'];
        }

        return null;
    }

    /**
     * Extract actual value from universal event context for logging.
     *
     * @param EvaluationContext $context Universal event context
     * @param ConditionInterface $component Condition component
     * @param array $settings Sanitized condition settings
     * @return mixed
     */
    private function extractActualValueFromUniversalEvent(EvaluationContext $context, ConditionInterface $component, array $settings)
    {
        $id = method_exists($component, 'get_id') ? (string) $component->get_id() : '';

        // Universal event specific extractions
        switch ($id) {
            case 'event_type':
                return $context->event->eventType;
            
            case 'source_gateway':
            case 'payment_gateway':
                return $context->event->sourceGateway;
            
            case 'subscription_status':
                if ($context->subscription && method_exists($context->subscription, 'get_status')) {
                    return $context->subscription->get_status();
                }
                return null;
            
            case 'cross_gateway':
                $event_gateway = $context->event->sourceGateway;
                $order_gateway = null;
                if ($context->order) {
                    $payment_method = $context->order->get_payment_method();
                    // Map common payment methods to gateway names
                    $gateway_mapping = [
                        'paypal' => 'paypal',
                        'ppcp-gateway' => 'paypal',
                        'stripe' => 'stripe',
                        'stripe_cc' => 'stripe',
                    ];
                    $order_gateway = $gateway_mapping[$payment_method] ?? $payment_method;
                }
                return [
                    'event_gateway' => $event_gateway,
                    'order_gateway' => $order_gateway,
                ];
        }

        // Fallback to legacy extraction if order is available
        if ($context->order) {
            return $this->extractActualValue($context->order, $component, $settings);
        }

        return null;
    }

    /**
     * Log universal event condition evaluation via ProcessLogger when available.
     *
     * @param EvaluationContext $context Universal event context
     * @param ConditionInterface $component Condition component
     * @param bool $passed Whether condition passed
     * @param mixed $expected Expected value
     * @param mixed $actual Actual value
     * @param string|null $operator Comparison operator
     * @return void
     */
    private function logUniversalEventConditionEvaluation(EvaluationContext $context, ConditionInterface $component, bool $passed, $expected, $actual, ?string $operator): void
    {
        if (!$this->process_logger) {
            return;
        }

        $order_id = $context->getOrderId();
        $kind = $passed ? 'condition_passed' : 'condition_failed';
        $label = $component->get_label();
        
        $data = [
            'order_id' => $order_id,
            'condition_label' => (string) $label,
            'operator' => $operator !== null ? (string) $operator : '',
            'expected_value' => $this->formatValueForLogging($expected),
            'actual_value' => $this->formatValueForLogging($actual),
            'result' => $passed ? 'pass' : 'fail',
            'event_type' => $context->event->eventType,
            'source_gateway' => $context->event->sourceGateway,
            'context_type' => 'universal_event',
        ];

        $this->process_logger->add_component($kind, $label, $data, 'info');
    }
}
