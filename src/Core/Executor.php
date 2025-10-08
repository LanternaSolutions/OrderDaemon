<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core;

use OrderDaemon\CompletionManager\Includes\AuditTrailLogger;
use OrderDaemon\CompletionManager\Core\Logging\ProcessLogger;
use OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer;
use OrderDaemon\CompletionManager\Core\RuleComponents\RuleComponentRegistry;
use OrderDaemon\CompletionManager\Core\Events\EvaluationContext;
use WC_Order;
use WP_Query;

class Executor
{
    /**
     * Singleton instance for universal event processing
     *
     * @var Executor|null
     */
    private static ?Executor $instance = null;

    /**
     * Component registry for conditions/actions/triggers.
     *
     * @var RuleComponentRegistry
     */
    private RuleComponentRegistry $registry;

    /**
     * JSON evaluator.
     *
     * @var Evaluator
     */
    private Evaluator $evaluator;

    /**
     * AuditTrailLogger instance.
     *
     * @var AuditTrailLogger
     */
    private AuditTrailLogger $logger;

    /**
     * Current process ID for correlation across log entries.
     * Generated at the start of each automation run.
     *
     * @var string|null
     */
    private ?string $current_process_id = null;

    /**
     * Performance threshold in milliseconds for slow execution warnings.
     * TODO: Make this configurable via admin developer tools page.
     *
     * @var int
     */
    private const SLOW_EXECUTION_THRESHOLD_MS = 2000;

    /**
     * Constructor.
     *
     * @param AuditTrailLogger $logger The audit trail logger instance.
     */
    public function __construct(AuditTrailLogger $logger)
    {
        $this->registry = new RuleComponentRegistry();
        $this->evaluator = new Evaluator();
        $this->logger = $logger;
    }

    /**
     * Get singleton instance for universal event processing
     *
     * @return Executor
     */
    public static function instance(): Executor
    {
        if (self::$instance === null) {
            // Create instance with default logger
            $logger = new AuditTrailLogger();
            self::$instance = new self($logger);
        }
        return self::$instance;
    }

    /**
     * Validate and retrieve the WooCommerce order.
     *
     * @param  integer $order_id The ID of the order to process.
     * @return WC_Order|false The order object or false if invalid.
     */
    private function validate_and_get_order(int $order_id)
    {
        $order = \wc_get_order($order_id);
        if (!$order) {
            // Invalid order is handled by ProcessLogger in caller; avoid extra log rows here
            return false;
        }

        return $order;

    }//end validate_and_get_order()


    /**
     * Check if the order contains conflicting product types that should prevent processing.
     *
     * @param  WC_Order $order The WooCommerce order object.
     * @return boolean True if the order should be skipped, false otherwise.
     */
    private function should_skip_order_due_to_conflicts(WC_Order $order): bool
    {
        // Use the odcm_order_has_conflicts filter to allow other code to determine
        // if an order has conflicts that should prevent processing
        return apply_filters('odcm_order_has_conflicts', false, $order->get_id());

    }//end should_skip_order_due_to_conflicts()


    /**
     * Get the query for active completion rules, ordered by priority.
     *
     * RACE CONDITION PROTECTION: This method includes defensive checks to ensure
     * the 'odcm_completion_rule' post type exists before querying it. This prevents
     * "invalid post type" errors in edge cases where initialization timing issues occur.
     *
     * The post type should be registered on 'init' priority 5 by Plugin.php, but
     * this defensive check ensures graceful handling if there are any remaining
     * timing issues in different execution contexts (CLI, background processing, etc.).
     *
     * @return WP_Query The query object for active rules.
     * @since 1.0.0
     */
    private function get_active_rules(): WP_Query
    {
        // DEFENSIVE CHECK: Verify post type exists before querying
        if (!post_type_exists('odcm_order_rule')) {
            // Return empty query to prevent fatal errors
            return new WP_Query(['post_type' => 'post', 'posts_per_page' => 0]);
        }

        try {
            return new WP_Query(
                [
                    'post_type'      => 'odcm_order_rule',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'orderby'        => 'menu_order',
                    'order'          => 'ASC',
                ]
            );
        } catch (\Throwable $e) {
            // Log any query errors for debugging
            $this->logger->record(
                'Error',
                'query_error',
                "Error querying order rules: " . $e->getMessage(),
                [
                    'context' => 'get_active_rules',
                    'error_message' => $e->getMessage(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine()
                ]
            );

            // Return empty query to prevent fatal errors
            return new WP_Query(['post_type' => 'post', 'posts_per_page' => 0]);
        }

    }//end get_active_rules()




    /**
     * Log a notice in the audit trail for a specific order.
     *
     * @param  string $message The notice message.
     * @param  integer $order_id The ID of the related order.
     * @return void
     */
    private function log_notice(string $message, int $order_id): void
    {
        // Get the order object to include in the payload
        $order = wc_get_order($order_id);
        $order_data = null;

        // Prepare full payload data if the order exists
        if ($order) {
            $order_data = $order->get_data();
        }

        odcm_log_event([
            'status'       => 'notice',
            'summary'      => $message,
            'order_id'     => $order_id,
            'event_type'   => 'system',
            'payload'      => [
                'process_id'   => 'N/A',
                'rule_id'      => 'N/A',
                'trigger_type' => 'system',
                'message'      => $message,
                'order_id'     => $order_id,
                'order_data'   => $order_data,
                'timestamp'    => current_time('mysql', 1),
                'source'       => 'log_notice',
            ],
        ]);

    }//end log_notice()


    /**
     * Process all active rules against the given order using the JSON-only pipeline.
     *
     * Evaluates each rule's _odcm_rule_data JSON via Evaluator with the component
     * registry. Implements First Match Wins and executes primaryAction then
     * secondaryActions for the first matching rule.
     *
     * @param  WC_Order $order The WooCommerce order object.
     * @param  array $rules Array of rule post objects.
     * @return array{summary:string,status:string,matched:bool}
     */
    private function process_rules_against_order(WC_Order $order, array $rules, ProcessLogger $processLogger): array
    {
        $order_id = $order->get_id();

        // Initialize payload collector
        $payload = [
            'rule_evaluation' => [
                'rule_id'   => null,
                'rule_name' => null,
                'trigger'   => [
                    'type'  => 'payment_complete',
                    'label' => 'Order Payment Complete',
                ],
                'conditions' => [],
                'actions'    => [],
            ],
            'woocommerce_data' => [
                'order_id'      => $order_id,
                'status_before' => $order->get_status(),
            ],
            'performance_metrics' => [],
        ];

        $summary = null;
        $status  = 'info';
        $matched = false;

        // Narrative logging: record order context
        $processLogger->add_component('order_loaded', 'Order loaded', [
            'id' => $order_id,
            'status' => $order->get_status(),
        ]);

        foreach ($rules as $rule) {
            // Update current rule context in payload
            $payload['rule_evaluation']['rule_id']   = (int) $rule->ID;
            $payload['rule_evaluation']['rule_name'] = $rule->post_title;

            // Load JSON rule data
            $json = get_post_meta((int)$rule->ID, '_odcm_rule_data', true);
            $rule_data = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($rule_data)) {
                // Narrative logging: invalid or missing rule JSON
                $processLogger->add_component('rule_evaluated', 'Rule evaluated', [
                    'rule_id' => (int) $rule->ID,
                    'matched' => false,
                    'reason' => 'invalid_rule_json',
                ], 'warning');
                continue;
            }

            // Evaluate via JSON evaluator
            $this->evaluator->set_process_logger($processLogger);
            $trace = $this->evaluator->evaluateRuleAgainstOrder($order, $rule_data, $this->registry);

            // Append simplified trace to payload
            foreach ($trace['conditions'] as $cond) {
                $payload['rule_evaluation']['conditions'][] = [
                    'label'  => $cond['label'],
                    'result' => $cond['result'],
                ];
            }

            if ($trace['matched']) {
                $matched = true;
                $processLogger->add_component('rule_evaluated', 'Rule evaluated', [
                    'rule_id' => (int) $rule->ID,
                    'matched' => true,
                    'reason' => 'first_match',
                ]);

                // Execute primary action then secondary actions
                $status_before = $order->get_status();

                // Primary action
                if (isset($rule_data['primaryAction']['id'])) {
                    $this->execute_action_component($order, $rule_data['primaryAction'], $processLogger, $payload);
                }
                // Secondary actions
                if (!empty($rule_data['secondaryActions']) && is_array($rule_data['secondaryActions'])) {
                    foreach ($rule_data['secondaryActions'] as $actionDef) {
                        if (isset($actionDef['id'])) {
                            $this->execute_action_component($order, $actionDef, $processLogger, $payload);
                        }
                    }
                }

                $status_after = $order->get_status();
                $payload['woocommerce_data']['status_before'] = $status_before;
                $payload['woocommerce_data']['status_after']  = $status_after;

                // Narrative logging: record the status change and decision
                if ($status_before !== $status_after) {
                    $processLogger->add_component('status_changed', 'Status changed', [
                        'from' => $status_before,
                        'to'   => $status_after,
                    ]);
                }
                $processLogger->add_component('decision', 'Decision made', [
                    'subject' => 'order_completion',
                    'outcome' => 'complete',
                    'reason'  => 'First matching rule executed',
                ]);

                $summary = sprintf(
                    'Rule "%s" executed for Order #%d.',
                    $rule->post_title,
                    $order_id
                );
                $status = 'success';

                // First-Match-Wins: stop further processing
                break;
            } else {
                $processLogger->add_component('rule_evaluated', 'Rule evaluated', [
                    'rule_id' => (int) $rule->ID,
                    'matched' => false,
                    'reason' => 'conditions_failed',
                ]);
                // Continue to next rule
            }
        }

        if (!$matched) {
            // Narrative logging: decision to skip completion (debug-only)
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $processLogger->add_component('decision', 'Decision made', [
                    'subject' => 'order_completion',
                    'outcome' => 'skip',
                    'reason'  => 'no matching rule',
                ], 'debug');
                $summary = sprintf('No matching rule found for Order #%d.', $order_id);
                $status  = 'debug';
            } else {
                // When not in debug mode, do not add any component and return debug status to caller
                $summary = '';
                $status  = 'debug';
            }
        }

        // Return outcome to caller; ProcessLogger persistence is handled at finish()
        return [
            'summary' => $summary,
            'status'  => $status,
            'matched' => $matched,
        ];

    }//end process_rules_against_order()

    /**
     * Execute a single action component with capability and schema sanitization.
     *
     * @param WC_Order $order
     * @param array    $actionDef
     * @param ProcessLogger $processLogger
     * @param array    $payload Reference to payload to append action result
     * @return void
     */
    private function execute_action_component(WC_Order $order, array $actionDef, ProcessLogger $processLogger, array &$payload): void
    {
        $id = isset($actionDef['id']) && is_string($actionDef['id']) ? $actionDef['id'] : '';
        if ($id === '') {
            return;
        }
        $actions = $this->registry->get_actions();
        if (!isset($actions[$id])) {
            $processLogger->add_component('action_skipped', 'Action skipped', [
                'action_id' => $id,
                'reason' => 'unknown_component',
            ], 'warning');
            $payload['rule_evaluation']['actions'][] = [
                'type' => $id,
                'label' => 'Unknown action',
                'result' => 'skipped',
            ];
            return;
        }

        $component = $actions[$id];

        // Pre-commit Revalidation Gate
        // Use the previously captured status as expected state if available
        $expected_status = isset($payload['woocommerce_data']['status_before']) && is_string($payload['woocommerce_data']['status_before'])
            ? $payload['woocommerce_data']['status_before']
            : null;
        $revalidation_context = [ 'expected_status' => $expected_status ];
        $revalidation_result = $this->revalidateOrderState((int)$order->get_id(), $revalidation_context);
        if (!$revalidation_result['is_valid']) {
            // Log via audit system and narrative process logger, then abort this action
            $this->logRevalidationFailure((int)$order->get_id(), $revalidation_result);
            $processLogger->add_component('revalidation_failed', 'Pre-commit revalidation failed', [
                'reason' => $revalidation_result['reason'],
                'details' => $revalidation_result['details'],
                'action_id' => $id,
            ], 'warning');
            $payload['rule_evaluation']['actions'][] = [
                'type' => $id,
                'label' => $component->get_label(),
                'result' => 'aborted_due_to_revalidation_failure',
            ];
            return;
        }

        $schema = $component->get_settings_schema();
        $rawSettings = is_array($actionDef['settings'] ?? null) ? $actionDef['settings'] : [];
        $clean = $this->evaluator->sanitize_by_schema($rawSettings, $schema);

        try {
            $component->execute($order, $clean);
            $processLogger->add_component('action_executed', 'Action executed', [
                'action_id' => $id,
                'label' => $component->get_label(),
            ], 'info');
            $payload['rule_evaluation']['actions'][] = [
                'type' => $id,
                'label' => $component->get_label(),
                'result' => 'executed',
            ];
        } catch (\Throwable $e) {
            $processLogger->add_component('action_error', 'Action error', [
                'action_id' => $id,
                'message' => $e->getMessage(),
            ], 'error');
            $payload['rule_evaluation']['actions'][] = [
                'type' => $id,
                'label' => $component->get_label(),
                'result' => 'error',
            ];
        }
    }

    /**
     * Complete the WooCommerce order and log the action.
     *
     * Enhanced Action Logging Implementation
     * - Logs completion action at STANDARD level for both audit log and order notes
     * - Uses registry-based logging system for structured data
     * - Includes comprehensive payload with rule and order context
     * - Adds human-readable order note for store owner visibility
     * - Tracks initial and final status for complete audit trail
     *
     * @param  WC_Order $order The order object.
     * @param  integer $rule_id The ID of the rule that triggered completion.
     * @param  string $trigger_type The type of trigger that initiated the check.
     * @return void
     */
    /**
     * Complete the WooCommerce order and return action details (no logging here).
     *
     * @param  WC_Order $order        The order object.
     * @param  int      $rule_id      The ID of the rule that triggered completion.
     * @param  string   $trigger_type The trigger that initiated the check.
     * @return array{initial_status:string, final_status:string, rule_name:string}
     */
    private function complete_order(WC_Order $order, int $rule_id, string $trigger_type): array
    {
        $initial_status = $order->get_status();

        // Perform the action
        $order->update_status('completed', 'Daemon: Order marked as complete.');
        $final_status = 'completed';

        $rule      = get_post($rule_id);
        $rule_name = $rule ? $rule->post_title : "Rule #{$rule_id}";

        // Human-visible order note (not an audit log write)
        $order->add_order_note(
            sprintf('Order status automatically changed to "Completed" by rule "%s".', $rule_name),
            false,
            true
        );

        return [
            'initial_status' => $initial_status,
            'final_status'   => $final_status,
            'rule_name'      => $rule_name,
        ];

    }//end complete_order()


    /**
     * Main entry point for processing an order check.
     *
     * RACE CONDITION PROTECTION: This method includes comprehensive error handling
     * and defensive checks to ensure graceful operation even if post type registration
     * timing issues occur. All errors are logged to the audit trail for debugging.
     *
     * Process & Performance Tracking
     * - Generates unique process ID for correlation across all log entries
     * - Tracks execution time and logs slow performance warnings
     * - Logs process start/end events for complete audit trail
     * - Designed for JSON-only evaluation; legacy meta evaluation removed
     *
     * The method validates that:
     * 1. The order exists and is valid
     * 2. The post type is registered before querying rules
     * 3. Any query errors are caught and logged
     * 4. Processing continues gracefully even if individual steps fail
     * 5. This method relies on idempotent operations.
     *
     * @param  integer $order_id The ID of the order to check.
     * @param  string|null $trigger_hook The hook that triggered this process (for logging).
     * @return void
     * @since 1.0.0
     */
    public function process_order_check(int $order_id, ?string $trigger_hook = null): void
    {
        // Generate unique process ID for correlation (Phase 1 implementation)
        $this->current_process_id = uniqid('proc_', true);

        // Process ID available for any downstream logging components

        // Start performance tracking
        $start_time = microtime(true);

        // Start unified narrative process logger
        $processLogger = new ProcessLogger(new ComponentSanitizer());
        $processLogger->start('rule_execution', [ 'order_id' => $order_id ]);

        try {
            // Log process start at DEBUG level
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_registered_event('process_started', [
                    'order_id' => $order_id,
                    'trigger_hook' => $trigger_hook ?: 'unknown',
                    'payload' => [
                        'order_id' => $order_id,
                        'trigger_hook' => $trigger_hook ?: 'unknown',
                        'process_id' => $this->current_process_id,
                        'timestamp' => current_time('mysql', true),
                        'context' => 'process_order_check_start'
                    ]
                ]);
            }

            // DEFENSIVE CHECK: Verify post type exists before processing
            if (!post_type_exists('odcm_order_rule')) {
                $this->logger->record(
                    'Error',
                    'post_type_not_registered',
                    "Cannot process order #{$order_id}: Post type 'odcm_order_rule' not registered. This indicates a plugin initialization race condition.",
                    [
                        'order_id' => $order_id,
                        'context' => 'process_order_check',
                        'post_type' => 'odcm_order_rule',
                        'process_id' => $this->current_process_id
                    ]
                );
                // Narrative finish
                $processLogger->add_component('error', 'Initialization error', [
                    'code' => 'post_type_not_registered',
                    'message' => 'Completion rule post type missing',
                ], 'error');
                $processLogger->finish('failed', sprintf(
                    "Initialization error: cannot process order #%d (rule post type not registered)",
                    $order_id
                ));
                return;
            }

            $order = $this->validate_and_get_order($order_id);
            if (!$order) {
                // Log an error for invalid order object
                $this->logger->record(
                    'Error',
                    'invalid_order',
                    "Failed to process check: Invalid WC_Order object for ID: {$order_id}.",
                    [
                        'order_id' => $order_id,
                        'process_id' => $this->current_process_id
                    ]
                );
                $processLogger->add_component('error', 'Invalid order', [
                    'code' => 'invalid_order',
                    'message' => 'WC_Order not found',
                ], 'error');
                $processLogger->finish('failed', sprintf('Invalid order object for ID: %d', $order_id));
                return;
            }

            if ($this->should_skip_order_due_to_conflicts($order)) {
                // Log a notice for orders skipped due to conflicts
                $this->logger->record(
                    'Notice',
                    'check_skipped_conflict',
                    "Order processing was skipped due to a conflict.",
                    [
                        'order_id' => $order_id,
                        'details' => 'The odcm_order_has_conflicts filter returned true.',
                        'process_id' => $this->current_process_id
                    ]
                );
                $processLogger->add_component('decision', 'Decision made', [
                    'subject' => 'order_completion',
                    'outcome' => 'skip',
                    'reason'  => 'conflict detected',
                ], 'warning');
                $processLogger->finish('warning', 'Order processing skipped due to conflict.');
                return;
            }

            $rules_query = $this->get_active_rules();
            if (!$rules_query->have_posts()) {
                // Narrative: no rules to evaluate
                $processLogger->add_component('decision', 'Decision made', [
                    'subject' => 'order_completion',
                    'outcome' => 'skip',
                    'reason'  => 'no active rules',
                ], 'info');
                $processLogger->finish('warning', sprintf('No matching rule found for Order #%d.', $order_id));
                return;
            }

            $outcome = $this->process_rules_against_order($order, $rules_query->posts, $processLogger);
            // Finish narrative entry with outcome summary
            if ($outcome['status'] === 'debug' && (!defined('ODCM_DEBUG') || !ODCM_DEBUG)) {
                // Do not persist no-match debug-only entries when debug is disabled
                return;
            }
            $final_status = $outcome['status'] === 'success' ? 'success' : ($outcome['status'] === 'failed' ? 'failed' : 'warning');
            $processLogger->finish($final_status, $outcome['summary'] !== '' ? $outcome['summary'] : '');

        } catch (\Throwable $e) {
            // Narrative: record error and finish
            if (isset($processLogger)) {
                $processLogger->add_component('error', 'Unhandled exception', [
                    'code' => 'exception',
                    'message' => $e->getMessage(),
                    'context' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                ], 'error');
                $processLogger->finish('failed', sprintf('Error processing order #%d', $order_id));
            }
        } finally {
            // Calculate execution time and log performance warnings
            $end_time = microtime(true);
            $execution_time_ms = round(($end_time - $start_time) * 1000);

            if ($execution_time_ms > self::SLOW_EXECUTION_THRESHOLD_MS) {
                // Record slow execution as a component on the process log would have already finished; optionally write a separate admin_action process
                $perfLogger = new \OrderDaemon\CompletionManager\Core\Logging\ProcessLogger(new \OrderDaemon\CompletionManager\Core\Logging\ComponentSanitizer());
                $perfLogger->start('admin_action', ['order_id' => $order_id, 'summary' => 'Performance warning']);
                $perfLogger->add_component('metrics', 'Execution time', [
                    'name' => 'rule_execution_time_ms',
                    'value' => (float)$execution_time_ms,
                    'unit' => 'ms'
                ], 'warning');
                $perfLogger->finish('warning', sprintf('Slow execution: %d ms (threshold %d ms) for Order #%d', $execution_time_ms, self::SLOW_EXECUTION_THRESHOLD_MS, $order_id));
            }

            // Log detailed timing at DEBUG level
            // TEMPORARILY DISABLED: This was causing infinite loops in the logging system
            // TODO: Re-enable with proper recursion protection
            /*
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_registered_event('step_timing', [
                    'step_name' => 'complete_order_processing',
                    'execution_ms' => $execution_time_ms,
                    'payload' => [
                        'process_id' => $this->current_process_id,
                        'step_name' => 'complete_order_processing',
                        'execution_ms' => $execution_time_ms,
                        'order_id' => $order_id,
                        'timestamp' => current_time('mysql', true),
                        'context' => 'process_order_check_timing'
                    ]
                ]);
            }
            */

            // Reset process ID after completion
            $this->current_process_id = null;
        }
    }//end process_order_check()

    /**
     * Basic order state revalidation.
     *
     * Lightweight checks to prevent executing actions on orders that are no longer
     * safe to modify. This is called immediately before executing an action component.
     *
     * Checks performed:
     * - Order exists
     * - Order is accessible (not trashed)
     * - Order not in terminal status (completed, refunded, cancelled, failed)
     *
     * @param int   $order_id           Order ID to validate.
     * @param array $evaluation_context Context from original evaluation. May include 'expected_status'.
     * @return array{is_valid:bool,reason:string,details:array}
     */
    private function revalidateOrderState(int $order_id, array $evaluation_context): array
    {
        // Ensure order exists
        $order = wc_get_order($order_id);
        if (!$order) {
            return [
                'is_valid' => false,
                'reason'   => 'Order no longer exists',
                'details'  => [
                    'order_id' => $order_id,
                ],
            ];
        }

        // Ensure not trashed/deleted
        $post_status = get_post_status($order_id);
        if ($post_status === 'trash') {
            return [
                'is_valid' => false,
                'reason'   => 'Order moved to trash',
                'details'  => [
                    'current_post_status' => 'trash',
                ],
            ];
        }

        // Ensure not terminal status
        $terminal_statuses = ['completed', 'refunded', 'cancelled', 'failed'];
        $current_status    = $order->get_status();
        if (in_array($current_status, $terminal_statuses, true)) {
            $expected_status = isset($evaluation_context['expected_status']) && is_string($evaluation_context['expected_status'])
                ? $evaluation_context['expected_status']
                : null;

            return [
                'is_valid' => false,
                'reason'   => 'Order status is terminal',
                'details'  => [
                    'current_status'  => $current_status,
                    'expected_status' => $expected_status,
                    'terminal'        => $terminal_statuses,
                ],
            ];
        }

        // All checks passed
        return [
            'is_valid' => true,
            'reason'   => '',
            'details'  => [],
        ];
    }

    /**
     * Log revalidation failure using existing logging system.
     *
     * Writes an audit log entry with clear summary and details so operators can
     * diagnose race conditions where the order changed between evaluation and execution.
     *
     * @param int   $order_id       Order ID.
     * @param array $failure_result Result array from revalidateOrderState().
     * @return void
     */
    private function logRevalidationFailure(int $order_id, array $failure_result): void
    {
        // Build payload respecting the expected schema for odcm_log_custom_event()
        $payload = [
            'reason'         => $failure_result['reason'] ?? 'unknown',
            'details'        => isset($failure_result['details']) && is_array($failure_result['details']) ? $failure_result['details'] : [],
            'action_aborted' => true,
            'order_id'       => $order_id,
            'source'         => 'executor_precommit_gate',
        ];

        // Use ODCM logging API (global function)
        odcm_log_custom_event(
            'Pre-commit revalidation failed for order #' . $order_id,
            $payload,
            $order_id,
            'warning',
            'revalidation_failure'
        );
    }

    /**
     * Process universal event through rule engine
     *
     * This method extends the existing rule processing to handle universal events
     * from the gateway adapters. It maintains the same "First Match Wins" logic
     * and action execution framework while adding support for event-driven conditions.
     *
     * @param EvaluationContext $context Universal event context with entities
     * @param string $process_id Process ID for audit correlation
     * @return bool Success status
     */
    public function process_universal_event(EvaluationContext $context, string $process_id): bool
    {
        // Set process ID for correlation
        $this->current_process_id = $process_id;

        // Start performance tracking
        $start_time = microtime(true);

        // Start unified narrative process logger
        $processLogger = new ProcessLogger(new ComponentSanitizer());
        $processLogger->start('universal_event_processing', [
            'event_type' => $context->event->eventType,
            'source_gateway' => $context->event->sourceGateway,
            'primary_object_type' => $context->event->primaryObjectType,
            'primary_object_id' => $context->event->primaryObjectID,
        ]);

        try {
            // Log process start at DEBUG level
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                odcm_log_registered_event('universal_event_process_started', [
                    'event_type' => $context->event->eventType,
                    'source_gateway' => $context->event->sourceGateway,
                    'payload' => [
                        'event_type' => $context->event->eventType,
                        'source_gateway' => $context->event->sourceGateway,
                        'process_id' => $this->current_process_id,
                        'timestamp' => current_time('mysql', true),
                        'context' => 'process_universal_event_start'
                    ]
                ]);
            }

            // DEFENSIVE CHECK: Verify post type exists before processing
            if (!post_type_exists('odcm_order_rule')) {
                $this->logger->record(
                    'Error',
                    'post_type_not_registered',
                    "Cannot process universal event: Post type 'odcm_order_rule' not registered. This indicates a plugin initialization race condition.",
                    [
                        'event_type' => $context->event->eventType,
                        'context' => 'process_universal_event',
                        'post_type' => 'odcm_order_rule',
                        'process_id' => $this->current_process_id
                    ]
                );
                // Narrative finish
                $processLogger->add_component('error', 'Initialization error', [
                    'code' => 'post_type_not_registered',
                    'message' => 'Completion rule post type missing',
                ], 'error');
                $processLogger->finish('failed', 'Initialization error: rule post type not registered');
                return false;
            }

            // Load active rules
            $rules_query = $this->get_active_rules();
            if (!$rules_query->have_posts()) {
                // Narrative: no rules to evaluate
                $processLogger->add_component('decision', 'Decision made', [
                    'subject' => 'universal_event_processing',
                    'outcome' => 'skip',
                    'reason'  => 'no active rules',
                ], 'info');
                $processLogger->finish('warning', 'No active rules found for universal event processing');
                return true; // Not an error, just no rules to process
            }

            // Process rules against universal event context
            $outcome = $this->process_rules_against_universal_event($context, $rules_query->posts, $processLogger);

            // Finish narrative entry with outcome summary
            if ($outcome['status'] === 'debug' && (!defined('ODCM_DEBUG') || !ODCM_DEBUG)) {
                // Do not persist no-match debug-only entries when debug is disabled
                return true;
            }

            $final_status = $outcome['status'] === 'success' ? 'success' : ($outcome['status'] === 'failed' ? 'failed' : 'warning');
            $processLogger->finish($final_status, $outcome['summary'] !== '' ? $outcome['summary'] : '');

            return $outcome['matched'];

        } catch (\Throwable $e) {
            // Narrative: record error and finish
            if (isset($processLogger)) {
                $processLogger->add_component('error', 'Unhandled exception', [
                    'code' => 'exception',
                    'message' => $e->getMessage(),
                    'context' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                ], 'error');
                $processLogger->finish('failed', 'Error processing universal event');
            }
            return false;
        } finally {
            // Calculate execution time and log performance warnings
            $end_time = microtime(true);
            $execution_time_ms = round(($end_time - $start_time) * 1000);

            if ($execution_time_ms > self::SLOW_EXECUTION_THRESHOLD_MS) {
                // Record slow execution
                $perfLogger = new ProcessLogger(new ComponentSanitizer());
                $perfLogger->start('admin_action', ['summary' => 'Performance warning']);
                $perfLogger->add_component('metrics', 'Execution time', [
                    'name' => 'universal_event_processing_time_ms',
                    'value' => (float)$execution_time_ms,
                    'unit' => 'ms'
                ], 'warning');
                $perfLogger->finish('warning', sprintf('Slow universal event processing: %d ms (threshold %d ms)', $execution_time_ms, self::SLOW_EXECUTION_THRESHOLD_MS));
            }

            // Reset process ID after completion
            $this->current_process_id = null;
        }
    }

    /**
     * Process all active rules against universal event context
     *
     * Similar to process_rules_against_order but handles EvaluationContext
     * with universal event data, order, subscription, and customer context.
     *
     * @param EvaluationContext $context Universal event context
     * @param array $rules Array of rule post objects
     * @param ProcessLogger $processLogger Process logger instance
     * @return array{summary:string,status:string,matched:bool}
     */
    private function process_rules_against_universal_event(EvaluationContext $context, array $rules, ProcessLogger $processLogger): array
    {
        $event = $context->event;
        $order_id = $context->getOrderId();

        // Initialize payload collector
        $payload = [
            'rule_evaluation' => [
                'rule_id'   => null,
                'rule_name' => null,
                'trigger'   => [
                    'type'  => 'universal_event',
                    'label' => 'Universal Event: ' . $event->eventType,
                ],
                'conditions' => [],
                'actions'    => [],
            ],
            'universal_event_data' => [
                'event_type' => $event->eventType,
                'source_gateway' => $event->sourceGateway,
                'channel' => $event->channel,
                'primary_object_type' => $event->primaryObjectType,
                'primary_object_id' => $event->primaryObjectID,
                'transaction_id' => $event->transactionID,
                'amount' => $event->amount,
                'currency' => $event->currency,
            ],
            'woocommerce_data' => [
                'order_id' => $order_id,
                'status_before' => $context->order ? $context->order->get_status() : null,
            ],
            'performance_metrics' => [],
        ];

        $summary = null;
        $status  = 'info';
        $matched = false;

        // Narrative logging: record event context
        $processLogger->add_component('event_loaded', 'Universal event loaded', [
            'event_type' => $event->eventType,
            'source_gateway' => $event->sourceGateway,
            'primary_object_type' => $event->primaryObjectType,
            'primary_object_id' => $event->primaryObjectID,
        ]);

        foreach ($rules as $rule) {
            // Update current rule context in payload
            $payload['rule_evaluation']['rule_id']   = (int) $rule->ID;
            $payload['rule_evaluation']['rule_name'] = $rule->post_title;

            // Load JSON rule data
            $json = get_post_meta((int)$rule->ID, '_odcm_rule_data', true);
            $rule_data = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($rule_data)) {
                // Narrative logging: invalid or missing rule JSON
                $processLogger->add_component('rule_evaluated', 'Rule evaluated', [
                    'rule_id' => (int) $rule->ID,
                    'matched' => false,
                    'reason' => 'invalid_rule_json',
                ], 'warning');
                continue;
            }

            // Evaluate via JSON evaluator with universal event context
            $this->evaluator->set_process_logger($processLogger);
            $trace = $this->evaluator->evaluateRuleAgainstUniversalEvent($context, $rule_data, $this->registry);

            // Append simplified trace to payload
            foreach ($trace['conditions'] as $cond) {
                $payload['rule_evaluation']['conditions'][] = [
                    'label'  => $cond['label'],
                    'result' => $cond['result'],
                ];
            }

            if ($trace['matched']) {
                $matched = true;
                $processLogger->add_component('rule_evaluated', 'Rule evaluated', [
                    'rule_id' => (int) $rule->ID,
                    'matched' => true,
                    'reason' => 'first_match',
                ]);

                // Execute primary action then secondary actions
                $status_before = $context->order ? $context->order->get_status() : null;

                // Primary action
                if (isset($rule_data['primaryAction']['id'])) {
                    $this->execute_universal_event_action($context, $rule_data['primaryAction'], $processLogger, $payload);
                }
                // Secondary actions
                if (!empty($rule_data['secondaryActions']) && is_array($rule_data['secondaryActions'])) {
                    foreach ($rule_data['secondaryActions'] as $actionDef) {
                        if (isset($actionDef['id'])) {
                            $this->execute_universal_event_action($context, $actionDef, $processLogger, $payload);
                        }
                    }
                }

                $status_after = $context->order ? $context->order->get_status() : null;
                $payload['woocommerce_data']['status_before'] = $status_before;
                $payload['woocommerce_data']['status_after']  = $status_after;

                // Narrative logging: record the status change and decision
                if ($status_before !== $status_after && $status_before && $status_after) {
                    $processLogger->add_component('status_changed', 'Status changed', [
                        'from' => $status_before,
                        'to'   => $status_after,
                    ]);
                }
                $processLogger->add_component('decision', 'Decision made', [
                    'subject' => 'universal_event_processing',
                    'outcome' => 'complete',
                    'reason'  => 'First matching rule executed',
                ]);

                $summary = sprintf(
                    'Rule "%s" executed for %s event %s.',
                    $rule->post_title,
                    $event->sourceGateway ?? 'system',
                    $event->eventType
                );
                $status = 'success';

                // First-Match-Wins: stop further processing
                break;
            } else {
                $processLogger->add_component('rule_evaluated', 'Rule evaluated', [
                    'rule_id' => (int) $rule->ID,
                    'matched' => false,
                    'reason' => 'conditions_failed',
                ]);
                // Continue to next rule
            }
        }

        if (!$matched) {
            // Narrative logging: decision to skip processing (debug-only)
            if (defined('ODCM_DEBUG') && ODCM_DEBUG) {
                $processLogger->add_component('decision', 'Decision made', [
                    'subject' => 'universal_event_processing',
                    'outcome' => 'skip',
                    'reason'  => 'no matching rule',
                ], 'debug');
                $summary = sprintf('No matching rule found for %s event %s.', $event->sourceGateway ?? 'system', $event->eventType);
                $status  = 'debug';
            } else {
                // When not in debug mode, do not add any component and return debug status to caller
                $summary = '';
                $status  = 'debug';
            }
        }

        // Return outcome to caller; ProcessLogger persistence is handled at finish()
        return [
            'summary' => $summary,
            'status'  => $status,
            'matched' => $matched,
        ];
    }

    /**
     * Execute action component for universal event context
     *
     * Similar to execute_action_component but handles EvaluationContext
     * and can work with order, subscription, or customer entities.
     *
     * @param EvaluationContext $context Universal event context
     * @param array $actionDef Action definition
     * @param ProcessLogger $processLogger Process logger instance
     * @param array $payload Reference to payload to append action result
     * @return void
     */
    private function execute_universal_event_action(EvaluationContext $context, array $actionDef, ProcessLogger $processLogger, array &$payload): void
    {
        $id = isset($actionDef['id']) && is_string($actionDef['id']) ? $actionDef['id'] : '';
        if ($id === '') {
            return;
        }

        $actions = $this->registry->get_actions();
        if (!isset($actions[$id])) {
            $processLogger->add_component('action_skipped', 'Action skipped', [
                'action_id' => $id,
                'reason' => 'unknown_component',
            ], 'warning');
            $payload['rule_evaluation']['actions'][] = [
                'type' => $id,
                'label' => 'Unknown action',
                'result' => 'skipped',
            ];
            return;
        }

        $component = $actions[$id];

        // Pre-commit Revalidation Gate (only for order-based actions)
        if ($context->order) {
            $expected_status = isset($payload['woocommerce_data']['status_before']) && is_string($payload['woocommerce_data']['status_before'])
                ? $payload['woocommerce_data']['status_before']
                : null;
            $revalidation_context = [ 'expected_status' => $expected_status ];
            $revalidation_result = $this->revalidateOrderState((int)$context->order->get_id(), $revalidation_context);
            if (!$revalidation_result['is_valid']) {
                // Log via audit system and narrative process logger, then abort this action
                $this->logRevalidationFailure((int)$context->order->get_id(), $revalidation_result);
                $processLogger->add_component('revalidation_failed', 'Pre-commit revalidation failed', [
                    'reason' => $revalidation_result['reason'],
                    'details' => $revalidation_result['details'],
                    'action_id' => $id,
                ], 'warning');
                $payload['rule_evaluation']['actions'][] = [
                    'type' => $id,
                    'label' => $component->get_label(),
                    'result' => 'aborted_due_to_revalidation_failure',
                ];
                return;
            }
        }

        $schema = $component->get_settings_schema();
        $rawSettings = is_array($actionDef['settings'] ?? null) ? $actionDef['settings'] : [];
        $clean = $this->evaluator->sanitize_by_schema($rawSettings, $schema);

        try {
            // Execute action with appropriate entity
            if ($context->order && method_exists($component, 'execute')) {
                // Standard order-based action
                $component->execute($context->order, $clean);
            } elseif (method_exists($component, 'executeUniversalEvent')) {
                // Universal event-aware action
                $component->executeUniversalEvent($context, $clean);
            } else {
                // Fallback: try with order if available
                if ($context->order) {
                    $component->execute($context->order, $clean);
                } else {
                    throw new \Exception('Action component does not support universal event execution and no order available');
                }
            }

            $processLogger->add_component('action_executed', 'Action executed', [
                'action_id' => $id,
                'label' => $component->get_label(),
            ], 'info');
            $payload['rule_evaluation']['actions'][] = [
                'type' => $id,
                'label' => $component->get_label(),
                'result' => 'executed',
            ];
        } catch (\Throwable $e) {
            $processLogger->add_component('action_error', 'Action error', [
                'action_id' => $id,
                'message' => $e->getMessage(),
            ], 'error');
            $payload['rule_evaluation']['actions'][] = [
                'type' => $id,
                'label' => $component->get_label(),
                'result' => 'error',
            ];
        }
    }

}
