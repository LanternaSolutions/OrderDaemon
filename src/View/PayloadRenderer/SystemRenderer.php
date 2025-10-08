<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\View\PayloadRenderer;

/**
 * System Renderer
 *
 * Consolidated renderer for operational/system events:
 * - info, note_added, action_scheduled, action_run, system_snapshot, dev_debug
 *
 * Uses PayloadComponentUIToolkit for clean, secure HTML.
 *
 * @package OrderDaemon\CompletionManager\View\PayloadRenderer
 */
final class SystemRenderer extends PayloadComponentRenderer
{
    /**
     * Default component ID (overridden in narrative via renderWithComponentId()).
     * We use 'info' as a neutral base.
     *
     * @return string
     */
    protected function getComponentId(): string
    {
        return 'info';
    }

    /**
     * Render Embedded Content
     *
     * Provides a compact, inline display for attribution context when this
     * component is embedded within another timeline item. Falls back to the
     * base implementation when no attribution data is detected.
     *
     * @param array $data Component data, possibly containing 'attribution'.
     * @return string HTML
     */
    public function renderEmbeddedContent(array $data): string
    {
        $attr = null;
        if (isset($data['attribution']) && is_array($data['attribution'])) {
            $attr = $data['attribution'];
        } elseif (isset($data['attribution_context']) && is_array($data['attribution_context'])) {
            $attr = $data['attribution_context'];
        }

        if (is_array($attr)) {
            return $this->renderAttributionBadges($attr);
        }

        return parent::renderEmbeddedContent($data);
    }

    /**
     * Render inner content for system-level events. Since the base class does not
     * expose the override kind, we render based on available data fields with
     * sensible fallbacks.
     *
     * Expected structures (best-effort):
     * - info: { message:string, context?:array }
     * - note_added: { message?:string, content?:string, author?:string, visibility?:string }
     * - action_scheduled: { hook:string, when:int|string, args?:array, group?:string }
     * - action_run: { hook:string, duration_ms?:float, result?:string, args?:array, group?:string }
     * - system_snapshot/dev_debug: { ...arbitrary arrays... }
     *
     * @param array $data
     * @return string
     */
    public function renderContent(array $data): string
    {
        $toolkit = new PayloadComponentUIToolkit();
        $parts = [];

        // Common lightweight message
        if (!empty($data['message'])) {
            $msg = sanitize_text_field((string)$data['message']);
            $parts[] = $toolkit->render_notice($msg);
        }

        // Attribution detection and rendering (compact badges + details)
        $attr = null;
        if (!empty($data['attribution_context']) && is_array($data['attribution_context'])) {
            $attr = $data['attribution_context'];
        } elseif (isset($data['source']) || isset($data['request_type']) || isset($data['source_plugin']) || isset($data['external_service'])) {
            // Build a compact attribution structure from component-level fields
            $attr = [
                'request_type'     => isset($data['request_type']) ? sanitize_key((string)$data['request_type']) : null,
                'source_plugin'    => is_array($data['source_plugin'] ?? null) ? $data['source_plugin'] : null,
                'external_service' => is_array($data['external_service'] ?? null) ? $data['external_service'] : null,
                'user_context'     => isset($data['user_logged_in']) ? ['is_logged_in' => (bool)$data['user_logged_in']] : [],
            ];
            if (isset($data['source'])) {
                $attr['source'] = sanitize_key((string)$data['source']);
            }
        }

        if (is_array($attr)) {
            // Derive source label if not explicitly provided
            $request_type = isset($attr['request_type']) ? sanitize_key((string)$attr['request_type']) : '';
            $is_logged_in = isset($attr['user_context']['is_logged_in']) ? (bool)$attr['user_context']['is_logged_in'] : false;
            $source = isset($attr['source']) ? sanitize_key((string)$attr['source']) : '';
            if ($source === '') {
                if ($is_logged_in && in_array($request_type, ['admin','ajax'], true)) {
                    $source = 'manual';
                } elseif ($request_type === 'webhook' || !empty($attr['external_service'])) {
                    $source = 'webhook';
                } elseif ($request_type === 'rest') {
                    $source = 'api';
                } elseif (in_array($request_type, ['action_scheduler','cron','cli','wp_cli'], true)) {
                    $source = 'scheduled';
                } else {
                    $source = 'system';
                }
            }

            // Prepare compact badges
            $badges = [];
            $source_label_map = [
                'manual'    => __('Manual', 'order-daemon'),
                'webhook'   => __('Webhook', 'order-daemon'),
                'api'       => __('REST API', 'order-daemon'),
                'scheduled' => __('Scheduled', 'order-daemon'),
                'system'    => __('System', 'order-daemon'),
            ];
            $source_text = $source_label_map[$source] ?? ucfirst($source);
            $badges[] = '<span class="odcm-badge odcm-badge--source">' . esc_html($source_text) . '</span>';

            $plugin = isset($attr['source_plugin']) && is_array($attr['source_plugin']) ? $attr['source_plugin'] : null;
            if ($plugin) {
                $pSlug = isset($plugin['slug']) ? sanitize_text_field((string)$plugin['slug']) : '';
                $pType = isset($plugin['type']) ? sanitize_key((string)$plugin['type']) : '';
                $pConf = isset($plugin['confidence']) ? (float)$plugin['confidence'] : null;
                if ($pSlug !== '') {
                    $label = $pSlug . ($pType !== '' ? ' (' . $pType . ')' : '');
                    $badges[] = '<span class="odcm-badge odcm-badge--plugin">' . esc_html($label) . '</span>';
                }
                if ($pConf !== null) {
                    $confPct = max(0, min(100, (int) round($pConf * 100)));
                    $confClass = $confPct >= 85 ? 'high' : ($confPct >= 60 ? 'med' : 'low');
                    $badges[] = '<span class="odcm-badge odcm-badge--confidence odcm-confidence-' . esc_attr($confClass) . '">' . esc_html(sprintf(__('Confidence %d%%', 'order-daemon'), $confPct)) . '</span>';
                }
            }

            $ext = isset($attr['external_service']) && is_array($attr['external_service']) ? $attr['external_service'] : null;
            if ($ext) {
                $eName = isset($ext['name']) ? sanitize_key((string)$ext['name']) : '';
                $eConf = isset($ext['confidence']) ? (float)$ext['confidence'] : null;
                if ($eName !== '') {
                    $badges[] = '<span class="odcm-badge odcm-badge--service">' . esc_html(ucfirst($eName)) . '</span>';
                }
                if ($eConf !== null) {
                    $ePct = max(0, min(100, (int) round($eConf * 100)));
                    $eClass = $ePct >= 85 ? 'high' : ($ePct >= 60 ? 'med' : 'low');
                    $badges[] = '<span class="odcm-badge odcm-badge--confidence odcm-confidence-' . esc_attr($eClass) . '">' . esc_html(sprintf(__('Confidence %d%%', 'order-daemon'), $ePct)) . '</span>';
                }
            }

            if (!empty($badges)) {
                $parts[] = '<div class="odcm-attr-badges">' . implode('', $badges) . '</div>';
            }

            // Build expandable details
            $details = '';

            // Summary block
            $summaryKv = [];
            if ($request_type !== '') { $summaryKv[__('Request Type', 'order-daemon')] = strtoupper($request_type); }
            if ($source_text !== '') { $summaryKv[__('Source', 'order-daemon')] = $source_text; }
            if ($is_logged_in) { $summaryKv[__('User', 'order-daemon')] = __('Logged in', 'order-daemon'); }
            if (!empty($summaryKv)) {
                $details .= $toolkit->render_key_value_list($summaryKv, __('Attribution Summary', 'order-daemon'));
            }

            // Plugin details
            if ($plugin) {
                $pKv = [];
                if (!empty($plugin['slug'])) { $pKv[__('Plugin', 'order-daemon')] = (string) $plugin['slug']; }
                if (!empty($plugin['type'])) { $pKv[__('Type', 'order-daemon')] = (string) $plugin['type']; }
                if (!empty($plugin['file'])) { $pKv[__('File', 'order-daemon')] = (string) $plugin['file']; }
                if (isset($plugin['frame'])) { $pKv[__('Frame', 'order-daemon')] = (string) $plugin['frame']; }
                if (isset($plugin['confidence'])) { $pKv[__('Confidence', 'order-daemon')] = sprintf('%.0f%%', max(0.0, min(1.0, (float)$plugin['confidence'])) * 100); }
                if (!empty($pKv)) {
                    $details .= $toolkit->render_key_value_list($pKv, __('Source Plugin', 'order-daemon'));
                }
            }

            // External service details
            if ($ext) {
                $eKv = [];
                if (!empty($ext['name'])) { $eKv[__('Service', 'order-daemon')] = ucfirst((string)$ext['name']); }
                if (isset($ext['confidence'])) { $eKv[__('Confidence', 'order-daemon')] = sprintf('%.0f%%', max(0.0, min(1.0, (float)$ext['confidence'])) * 100); }
                if (!empty($ext['indicators']) && is_array($ext['indicators'])) {
                    $indJson = (string) wp_json_encode($ext['indicators'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $details .= $toolkit->render_expandable_section(__('Indicators', 'order-daemon'), $toolkit->render_code_block($indJson, 'json'));
                }
                if (!empty($eKv)) {
                    $details .= $toolkit->render_key_value_list($eKv, __('External Service', 'order-daemon'));
                }
            }

            // User context details
            if (!empty($attr['user_context']) && is_array($attr['user_context'])) {
                $uc = $attr['user_context'];
                $uKv = [];
                if (!empty($uc['user_id'])) { $uKv[__('User ID', 'order-daemon')] = (string) (int) $uc['user_id']; }
                if (!empty($uc['roles']) && is_array($uc['roles'])) { $uKv[__('Roles', 'order-daemon')] = implode(', ', array_map('sanitize_text_field', (array)$uc['roles'])); }
                if (!empty($uc['ip'])) { $uKv[__('IP', 'order-daemon')] = (string) $uc['ip']; }
                if (!empty($uc['session']) && is_array($uc['session'])) {
                    $sess_summary = [];
                    if (!empty($uc['session']['php_session'])) { $sess_summary[] = 'PHP'; }
                    if (!empty($uc['session']['wc_session'])) { $sess_summary[] = 'WC'; }
                    if (!empty($uc['session']['wc_customer_id'])) { $sess_summary[] = 'CID:' . sanitize_text_field((string)$uc['session']['wc_customer_id']); }
                    if (!empty($sess_summary)) { $uKv[__('Session', 'order-daemon')] = implode(' | ', $sess_summary); }
                }
                if (!empty($uKv)) {
                    $details .= $toolkit->render_key_value_list($uKv, __('User Context', 'order-daemon'));
                }
            }

            // HTTP headers (for webhooks/API)
            if (!empty($attr['http']['headers']) && is_array($attr['http']['headers'])) {
                $headersJson = (string) wp_json_encode($attr['http']['headers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $details .= $toolkit->render_expandable_section(__('Request Headers', 'order-daemon'), $toolkit->render_code_block($headersJson, 'json'));
            }

            // Performance metrics
            if (!empty($attr['performance']) && is_array($attr['performance'])) {
                $perf = $attr['performance'];
                $perfKv = [];
                if (isset($perf['build_ms'])) { $perfKv[__('Build Time', 'order-daemon')] = sprintf('%.2f ms', (float)$perf['build_ms']); }
                if (isset($perf['backtrace_ms'])) { $perfKv[__('Backtrace Time', 'order-daemon')] = sprintf('%.2f ms', (float)$perf['backtrace_ms']); }
                if (isset($perf['cache'])) { $perfKv[__('From Cache', 'order-daemon')] = !empty($perf['cache']) ? __('Yes', 'order-daemon') : __('No', 'order-daemon'); }
                if (!empty($perfKv)) {
                    $details .= $toolkit->render_key_value_list($perfKv, __('Performance', 'order-daemon'));
                }
            }

            if ($details !== '') {
                $parts[] = $toolkit->render_expandable_section(__('Attribution details', 'order-daemon'), $details);
            }
        }

        // Action Scheduler: scheduled/run
        $hook = isset($data['hook']) ? sanitize_text_field((string)$data['hook']) : '';
        $group = isset($data['group']) ? sanitize_text_field((string)$data['group']) : '';
        $when  = isset($data['when']) ? (string)$data['when'] : '';
        $dur   = isset($data['duration_ms']) ? (float)$data['duration_ms'] : null;
        $result= isset($data['result']) ? sanitize_text_field((string)$data['result']) : '';

        $kv = [];
        if ($hook !== '') { $kv['Hook'] = $hook; }
        if ($group !== '') { $kv['Group'] = $group; }
        if ($when !== '') { $kv['When'] = $when; }
        if ($dur !== null) { $kv['Duration'] = sprintf('%.2f ms', $dur); }
        if ($result !== '') { $kv['Result'] = $result; }
        if (!empty($kv)) {
            $parts[] = $toolkit->render_key_value_list($kv, __('Action Scheduler', 'order-daemon'));
        }

        // Args/context
        if (!empty($data['args']) && is_array($data['args'])) {
            $argsJson = (string) wp_json_encode($data['args'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parts[] = $toolkit->render_expandable_section(
                __('Arguments', 'order-daemon'),
                $toolkit->render_code_block($argsJson, 'json')
            );
        }
        if (!empty($data['context']) && is_array($data['context'])) {
            $ctxJson = (string) wp_json_encode($data['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parts[] = $toolkit->render_expandable_section(
                __('Context', 'order-daemon'),
                $toolkit->render_code_block($ctxJson, 'json')
            );
        }

        // Note content (safe HTML allowed)
        if (!empty($data['content'])) {
            $parts[] = '<div class="odcm-system-note">' . wp_kses_post((string)$data['content']) . '</div>';
        }

        // Snapshots / debug blocks: render entire data safely for inspection
        if (empty($parts)) {
            $json = (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parts[] = $toolkit->render_expandable_section(
                __('Details', 'order-daemon'),
                $toolkit->render_code_block($json, 'json')
            );
        }

        return implode('', $parts);
    }

    /**
     * Render compact attribution badges for embedded usage.
     *
     * @param array $attribution Attribution array structure.
     * @return string HTML
     */
    private function renderAttributionBadges(array $attribution): string
    {
        $badges = [];

        $source = '';
        if (!empty($attribution['source'])) {
            $source = sanitize_key((string)$attribution['source']);
        } elseif (!empty($attribution['request_type'])) {
            $rt = sanitize_key((string)$attribution['request_type']);
            // Derive source from request_type when not provided
            if ($rt === 'rest') {
                $source = 'api';
            } elseif ($rt === 'webhook') {
                $source = 'webhook';
            } elseif (in_array($rt, ['action_scheduler','cron','cli','wp_cli'], true)) {
                $source = 'scheduled';
            } elseif ($rt === 'admin' || $rt === 'ajax') {
                $source = 'manual';
            } else {
                $source = 'system';
            }
        }

        if ($source !== '') {
            $label_map = [
                'manual'    => __('Manual', 'order-daemon'),
                'webhook'   => __('Webhook', 'order-daemon'),
                'api'       => __('API', 'order-daemon'),
                'scheduled' => __('Scheduled', 'order-daemon'),
                'system'    => __('System', 'order-daemon'),
            ];
            $text = $label_map[$source] ?? ucfirst($source);
            $badges[] = '<span class="odcm-attribution-badge odcm-source-' . esc_attr($source) . '">' . esc_html($text) . '</span>';
        }

        // User badge for manual actions when user context indicates logged-in
        $user_logged = false;
        if (isset($attribution['user_logged_in'])) {
            $user_logged = (bool)$attribution['user_logged_in'];
        } elseif (!empty($attribution['user_context']['is_logged_in'])) {
            $user_logged = (bool)$attribution['user_context']['is_logged_in'];
        }
        if ($source === 'manual' && $user_logged) {
            $badges[] = '<span class="odcm-user-badge">' . esc_html(__('User', 'order-daemon')) . '</span>';
        }

        // External service badge
        $service_name = '';
        if (!empty($attribution['external_service']['name'])) {
            $service_name = (string)$attribution['external_service']['name'];
        } elseif (!empty($attribution['service']) && is_array($attribution['service']) && !empty($attribution['service']['name'])) {
            $service_name = (string)$attribution['service']['name'];
        }
        if ($service_name !== '') {
            $badges[] = '<span class="odcm-service-badge">' . esc_html($service_name) . '</span>';
        }

        return implode(' ', $badges);
    }

    /**
     * Analyzer compatibility (not used by narrative flow).
     *
     * @param array $data
     * @return bool
     */
    public function canHandle(array $data): bool
    {
        return isset($data['message']) || isset($data['hook']) || isset($data['content']);
    }
}
