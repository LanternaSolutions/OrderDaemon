# Entitlements & Premium Awareness (for Integrators)

Audience: Developers building add‑ons or custom components (public developer docs)
Last updated: 2025-11-15

This guide explains how Order Daemon’s entitlement model (free vs Pro) affects extensions, and how to design components and integrations that behave correctly whether Pro is installed or not.

What you’ll learn
- How capability keys declare whether a component is free or premium‑gated
- How the UI and REST API enforce entitlements
- How to design graceful degradation when Pro is missing
- Common pitfalls (and how to avoid them)

---

## Core concepts

- Capability key: Each component provides a capability string via `ComponentInterface::get_capability()`. Examples: `trigger_basic`, `condition_order_total`, `trigger_premium`.
- Global checker: Use `odcm_can_use( string $capability ): bool` (defined in Core, see `src/Includes/functions.php`) to decide if a capability is allowed. Core returns true for free capabilities and false for premium ones unless Pro is active (or a debug override is enabled for development).
- Separation of concerns:
  - Authorization/permissions (user roles, nonces) are enforced by the Guard system and WordPress caps.
  - Entitlements/licensing are enforced by `odcm_can_use()` and the REST controller. Both must pass.

---

## Declaring free vs premium components

Implement `ComponentInterface` and return a stable capability key from `get_capability()`:

```php
use OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ComponentInterface;

final class HighValueOrderCondition implements ComponentInterface {
    public function get_id(): string { return 'order_total_threshold'; }
    public function get_label(): string { return __( 'components.order_total_threshold.label', 'order-daemon' ); }
    public function get_description(): string { return __( 'components.order_total_threshold.description', 'order-daemon' ); }

    // Mark as free or premium by capability string:
    public function get_capability(): string { return 'condition_order_total'; /* free example */ }

    public function get_settings_schema(): ?array { /* ... */ }
}
```

Guidelines for capability keys
- Keep them lowercase, snake_case. Treat them as stable API.
- Group by domain when helpful: `trigger_*`, `condition_*`, `action_*`, `audit_*`.
- Premium components should use a key that clearly indicates tier (e.g., `trigger_premium`, `condition_multi_category`).

UI hints in schemas
- If a specific option within an otherwise free component is premium, add hints in your schema so the Rule Builder can present it correctly:
  - `ui:capability`: capability required to enable the entire field/group.
  - `ui:premium_options`: an array of enum values that are premium‑only.

Example
```php
return [
  'type' => 'object',
  'properties' => [
    'mode' => [
      'type' => 'string',
      'enum' => ['basic', 'advanced'],
      'default' => 'basic',
      'title' => __( 'components.example.mode', 'order-daemon' ),
      'ui:widget' => 'select',
      'ui:premium_options' => ['advanced'],
      'ui:capability' => 'condition_example_advanced',
    ],
  ],
];
```

---

## How enforcement works

- UI layer (Rule Builder)
  - Uses capability keys to disable premium components and options in Core. Premium items remain visible for discoverability but are not selectable. Translated tooltips explain the requirement (no sales links).
  - When Pro is active, the same schema renders fully enabled controls.

- REST API layer (server‑side)
  - `RuleBuilderApiController` validates rules on save:
    - It rejects payloads containing components or options for which `odcm_can_use()` returns false.
    - It filters component schemas sent to the UI based on entitlements so premium‑only fields can be marked/disabled.
    - It defensively blocks known premium triggers even if posted manually; for example, a universal “any status change” trigger is rejected without the `trigger_premium` capability, returning a 403 with code like `odcm_premium_blocked`.
  - Expect `WP_Error` JSON with:
    - HTTP 403
    - Code: `odcm_premium_blocked`
    - Message: i18n string (text domain `order-daemon`)

- Engine/runtime
  - If Pro is removed but existing rules reference premium components, the Core’s Premium Component Fallback is initialized (when available) to prevent fatals and guide behavior until the rule is edited. Design your components to no‑op safely when entitlements are absent.

---

## Designing graceful degradation

Do
- Keep premium logic behind entitlement checks at boundaries: UI, REST, and execute paths.
- Provide safe defaults in `get_settings_schema()` so that disabled options still produce valid configurations.
- Make actions idempotent; if a capability becomes unavailable, bail with a logged, translated notice rather than throwing fatals.
- Log helpful audit events (e.g., `component_capability_denied`) so store owners understand what happened.

Avoid
- Calling Pro‑only classes/functions directly from Core‑compatible code. Prefer interfaces or existence checks (`class_exists`) with clear fallbacks.
- Hiding components entirely. Visible‑but‑disabled aids discoverability and keeps the UI consistent.
- Encoding licensing decisions in multiple places. Treat `odcm_can_use()` as the single source of truth for entitlement checks.

Pattern: guarded execution
```php
if ( function_exists('odcm_can_use') && ! odcm_can_use('action_advanced') ) {
    // Emit an audit entry or return a translated error that surfaces in logs/UI
    return false; // or WP_Error in REST context
}
// Proceed with advanced behavior
```

---

## Testing your integration

- Verify UI states: In Core‑only environment, ensure premium components/fields are visibly disabled with appropriate help text. With Pro active, confirm they unlock.
- Exercise REST saves: Attempt to save a rule using a premium component without Pro; confirm 403 `odcm_premium_blocked` with a translated message. Then retry with Pro active.
- Remove Pro after creating a premium rule: Confirm no fatals; the rule should be guarded by fallback and/or be clearly reported in the Audit Log when it cannot execute.
- i18n: All labels/help/diagnostics should use the `order-daemon` text domain. Confirm JSON script translations load for admin bundles.

---

## Troubleshooting

- Component not showing: Ensure your class implements the correct interface and is autoloaded. Entitlement only affects enabled state, not discovery.
- REST save blocked (403): Your payload includes a premium‑only component or option without entitlement. Check capability keys and `odcm_can_use()`.
- Mixed free/premium options: Use `ui:premium_options` and `ui:capability` to guide the UI, and mirror checks server‑side.
- Pro present but options still disabled: Confirm the site’s license state in Pro (handled by the Pro add‑on) and clear any caches; ensure `odcm_can_use()` reflects the updated status.

---

## See also

- Developer overview: /docs/developers/overview/
- Creating custom components: /docs/developers/extending-rules/
- Settings schemas & UI: /docs/developers/settings-schema-and-ui/
- Audit Log (user level): /docs/audit-log/
- Internal references (this repo): A4 Entitlements, A8 REST API
