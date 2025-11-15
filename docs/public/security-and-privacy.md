# Security, Privacy & Compliance

Audience: Store owners and managers
Last updated: 2025-11-15

This page explains, in plain language, how Order Daemon handles your store’s data, what gets stored, and how to keep things secure and compliant.

---

## What data the plugin stores

Order Daemon stores only what it needs to run your automations and show you a clear history of what happened.

- Rules (your configuration)
  - Triggers, conditions and actions you configured for each rule.
  - Status (enabled/disabled), labels, and priorities.
- Audit log entries (timeline)
  - A summary of what ran and why (for example: which rule matched, which conditions passed/failed, what action ran).
  - Helpful context such as order status, rule names, and processing results.
  - Security‑related events (for example: a permissions check failed) may also appear as entries with a simple description.
- Diagnostics results (admin‑only)
  - High‑level outcomes of health checks so you can fix configuration or environment issues.

Notes
- The audit log is designed for operations and support. It aims to be informative without storing unnecessary personal data.
- Pro versions may add more filter options and potentially more detailed entries, but the overall approach is the same: operational details, not marketing profiles.

---

## Where data is stored

- Rules are stored as a standard WordPress “Custom Post Type” (named Completion Rules) with structured settings saved as post meta.
- Audit/timeline entries are stored in your site database via the plugin’s logging system.
- Diagnostics settings and preferences (like items per page) are stored as standard WordPress options or user preferences.

Nothing is sent to external services by the free plugin. If you connect external systems (for example, by using inbound webhooks), only the data needed for that integration flows through your site.

---

## GDPR and privacy considerations

- Personal data: The audit log focuses on events and outcomes (for example, “Rule X set order 123 to Completed”). It does not need to store full customer profiles. When order details are shown, they are limited snapshots to help you understand the decision.
- Minimization: Avoid putting sensitive information into custom fields or custom messages if you do not need it for operations. Keep rule names and notes generic where possible.
- Exports and erasure: Order Daemon uses standard WordPress/WooCommerce storage. Your existing GDPR export/erasure processes cover orders and user accounts. The audit log is operational history; you can delete entries via the dashboard if needed.
- Retention: Keep audit history as long as it’s useful for operations or support. If your policies require it, periodically clean up old entries. (The plugin includes batch delete tools in the Audit Log screen.)

This page is not legal advice. For compliance questions, consult your legal team and align the plugin’s retention settings with your company policies.

---

## Access control (who can see what)

- Managing rules and viewing the Insight (Audit) dashboard generally requires a role that can manage WooCommerce (Shop Manager or Administrator). If in doubt, ask your site administrator.
- Public endpoints used for inbound webhooks accept messages from external services by design. These do not expose your admin screens. Use shared secrets or signature headers provided by the external service when available, and keep webhook URLs private.
- If you use additional security plugins or firewalls, make sure they allow WordPress REST API access for your logged‑in admin users so the dashboards can load.

Tip: If someone shouldn’t be able to change rules or view logs, make sure they do not have the WooCommerce management capabilities on your site.

---

## Differences in Pro

- Pro may add more filters or options in the Audit Log and more rule components. The same privacy principles apply: operational data only, focused on explaining what happened.
- Pro’s licensing features (activation, status checks) do not send your order data to our servers. They only validate your license key and site information for the add‑on itself.

---

## Good practices for security and privacy

- Keep WordPress, WooCommerce, and all plugins up to date.
- Use strong admin passwords and two‑factor authentication where possible.
- Limit who has Administrator or Shop Manager access.
- Treat webhook URLs like passwords—don’t share them publicly, and use shared secrets/signatures when the external service supports it.
- Review the Audit Log to spot unusual activity and to confirm automations are working as expected.

---

## Troubleshooting and housekeeping

- I see English text instead of my language
  - Set your Site Language under Settings → General. The plugin loads translations automatically when available.
- I want fewer details in the audit log
  - Use the batch delete action on the Audit Log screen to remove old entries. Keep only what you need for operations and support.
- A user can’t access the dashboards
  - Make sure they have a role with WooCommerce management capabilities (Shop Manager or Administrator) and that no security plugin is blocking REST API calls for logged‑in admins.

---

## What’s next

- Audit Log: /docs/audit-log/
- Diagnostics Dashboard: /docs/diagnostics-dashboard/
- Using the Rule Builder: /docs/using-the-rule-builder/
