# Working with Settings Schemas & the Rule Builder UI

Audience: Developers (public developer docs)
Last updated: 2025-11-15

This guide explains how to define component settings schemas that the Rule Builder UI can render, how to add UI metadata, and how entitlements (free vs Pro) affect available options. It complements the “Creating Custom Rule Components” page and focuses on stable, public contracts.

---

## What is a settings schema?

Every Trigger, Condition, and Action can declare a settings schema via:
- Interface: OrderDaemon\CompletionManager\Core\RuleComponents\Interfaces\ComponentInterface
- Method: get_settings_schema(): ?array

The schema is a PHP array that uses a JSON‑schema-like structure. The Rule Builder consumes this schema to render fields, validate input, and persist configuration.

General rules
- Return null or [] if your component has no settings.
- Keep IDs stable. Saved rule JSON references your schema keys.
- Labels and descriptions must be i18n string IDs resolved with the order-daemon text domain.

Example
```php
public function get_settings_schema(): ?array {
    return [
        'type'        => 'object',
        'title'       => __( 'components.min_total.title', 'order-daemon' ),
        'description' => __( 'components.min_total.desc', 'order-daemon' ),
        'properties'  => [
            'operator' => [
                'type'        => 'string',
                'title'       => __( 'components.common.operator', 'order-daemon' ),
                'enum'        => ['>=', '>'],
                'default'     => '>=',
                'ui:widget'   => 'radio',
            ],
            'amount' => [
                'type'        => 'number',
                'title'       => __( 'components.common.amount', 'order-daemon' ),
                'minimum'     => 0,
                'default'     => 0,
                'ui:widget'   => 'currency',
                'ui:placeholder' => '0.00',
            ],
            'currencies' => [
                'type'        => 'array',
                'title'       => __( 'components.common.currencies', 'order-daemon' ),
                'items'       => [ 'type' => 'string' ],
                'uniqueItems' => true,
                'ui:widget'   => 'multiselect',
                'ui:searchable' => true,
                // Pro-only prefilled options example:
                'ui:premium_options' => [
                    'capability' => 'condition_advanced_currency',
                ],
            ],
        ],
        'required'    => ['amount'],
    ];
}
```

---

## Supported types and common keywords

Root container
- type: object — Most component schemas use an object root with properties.
- properties: array — Keyed by field id.
- required: string[] — List of required property keys.

Field types
- string — With optional enum, format, pattern
- number/integer — With optional minimum/maximum, multipleOf
- boolean — Renders as a toggle/checkbox
- array — With items (type or schema), uniqueItems, minItems, maxItems
- object — Nested objects are supported for grouped settings

Common keywords
- title: i18n label for the field
- description: i18n helper text
- default: default value
- enum: fixed list of values (strings or numbers)
- enumNames: i18n labels for enum values (optional)
- minimum/maximum: numeric bounds
- pattern: regex for strings
- examples: example values for docs/tooling

i18n guidance
- Always wrap labels/descriptions in translation functions with text domain order-daemon.
- Use stable string keys like 'components.my_condition.amount.label' rather than hard‑coded English.

---

## UI metadata (ui:* hints)

The Rule Builder supports ui:* hints to influence rendering. These are advisory; the editor may fall back to a reasonable default if a widget is unknown.

Common hints
- ui:widget — Choose a widget: select, multiselect, radio, checkbox, textarea, currency, number, text, code
- ui:placeholder — Placeholder text (string)
- ui:help — Short helper text displayed near the input
- ui:searchable — Boolean; enables search on large selects
- ui:options — Object for widget‑specific options (e.g., { asyncSource: 'categories', min: 0 })
- ui:width — Layout width hint (e.g., 'sm', 'md', 'lg', 'full')

Premium/entitlement hints
- ui:capability — String capability key gating the entire field
- ui:premium_options — Object describing premium‑only choices inside a field (e.g., premium enum values) with:
  - capability — The capability required to enable/select premium options
  - message (optional) — i18n key for an inline prompt when locked in Core

Behavior
- In Core (without entitlement), premium‑gated fields/options render disabled with an educational message; the REST API also enforces entitlements on save.
- With Pro (or when odcm_can_use(capability) returns true), locked controls are enabled.

---

## Patterns for common UIs

Select lists (taxonomy, product types)
- Provide enum/enumNames or ui:options.asyncSource to let the UI fetch options via REST.
- Use ui:searchable for long lists. For multi‑select, set type: 'array' with items: { type: 'string' }.

Numeric inputs (currencies, totals)
- Prefer type: 'number'; add minimum/maximum and sensible defaults.
- For order totals, use ui:widget: 'currency' when available to render with the store currency.

Boolean flags
- type: 'boolean' renders as a toggle or checkbox; add description for clarity.

Nested groups
- Use type: 'object' for grouped settings; provide a title to render a sub‑section.

Conditional fields
- Keep schemas static and handle conditional show/hide in UI hints where possible.
- If you must switch fields based on another field’s value, prefer a single field with enum + ui:widget and handle branching inside your component at runtime.

---

## Validation and defaults

- The editor performs light validation using your schema (required, enum, min/max). Perform server‑side validation in your REST/controller or component logic.
- Always set defaults so new fields don’t break existing saved rules. Never remove or rename keys without a migration path.
- For arrays with enum values, ensure payload sanitization on save.

---

## Entitlements (free vs Pro)

- Choose clear capability keys in get_capability() for your component.
- For per‑field or per‑option gating, use ui:capability or ui:premium_options with a capability name.
- Core surfaces premium UI elements but disables them; the REST save will reject unauthorized payloads with 403 odcm_premium_blocked.
- Pro unlocks capabilities and should also remove upgrade prompts in the UI.

---

## End‑to‑end example (Condition)

```php
public function get_settings_schema(): ?array {
    return [
        'type'       => 'object',
        'title'      => __( 'components.category_rule.title', 'order-daemon' ),
        'properties' => [
            'mode' => [
                'type'    => 'string',
                'title'   => __( 'components.category_rule.mode', 'order-daemon' ),
                'enum'    => ['include_any', 'include_all', 'exclude_any'],
                'default' => 'include_any',
                'ui:widget' => 'select',
            ],
            'categories' => [
                'type'        => 'array',
                'title'       => __( 'components.category_rule.categories', 'order-daemon' ),
                'items'       => [ 'type' => 'integer' ],
                'uniqueItems' => true,
                'ui:widget'   => 'multiselect',
                'ui:searchable' => true,
                'ui:options'  => [ 'asyncSource' => 'product_categories' ],
                'ui:premium_options' => [ 'capability' => 'condition_multi_category' ],
            ],
        ],
        'required' => ['categories'],
    ];
}
```

---

## Testing and troubleshooting

- Verify i18n: Ensure the order-daemon text domain is loaded and JSON script translations are registered for the Rule Builder assets.
- Inspect REST: Use your browser network panel when saving a rule. A 403 with code odcm_premium_blocked means the payload included premium fields/options without entitlement.
- Backwards compatibility: When changing schemas, keep old keys working or add a migration. Avoid removing enum values that may exist in saved rules.
- Performance: Large async selects should page results server‑side; avoid heavy synchronous PHP in options generation.

---

## See also

- Creating Custom Rule Components: /docs/developers/extending-rules/
- Developer Overview: /docs/developers/overview/
- Internal reference: Admin UI and Rule Builder details are summarized in internal docs A6; public docs intentionally avoid private implementation specifics.
