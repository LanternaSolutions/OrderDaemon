# Installation & Setup

Audience: Store owners and managers
Last updated: 2025-11-15

This page helps you install Order Daemon (free core) and, optionally, the Pro add‑on. It also covers a quick first‑time checklist and common troubleshooting tips.

---

## Requirements

- WordPress and WooCommerce must be installed and active on your site.
- Order Daemon follows the supported versions of WordPress and WooCommerce. For best results, keep your site updated to the latest stable releases.
- PHP: Use a currently supported PHP version (we recommend PHP 8.0+ when possible).

Note: Order Daemon automatically loads translations for its UI when available.

---

## Install the free plugin (Core)

You can install the free plugin from your WordPress dashboard:

1) In your WordPress admin, go to Plugins → Add New.
2) Search for “Order Daemon for WooCommerce”.
3) Click Install, then Activate.
4) Ensure WooCommerce is active (Order Daemon depends on it).

After activation:
- Go to Orders → Completion Rules to create and manage rules.
- Go to Orders → Insight (or the Insight Dashboard entry) to review logs/timelines of what the automation does.

---

## Install the Pro add‑on (optional)

Pro extends the free plugin with additional triggers, conditions, and advanced options.

1) Download the Pro add‑on ZIP from your account (the Pro plugin is installed like any other plugin).
2) In WordPress admin, go to Plugins → Add New → Upload Plugin, choose the ZIP, and click Install Now.
3) Click Activate.

Important:
- The free plugin must be active for Pro to work. If the free plugin is missing, WordPress will show a message; activate the free plugin first and then Pro.
- Premium features appear in the UI and will unlock automatically when Pro is active (and licensed, if licensing applies in your setup).

---

## First‑time setup checklist

Use this short checklist to confirm everything is ready:

- WooCommerce is active (Plugins screen shows WooCommerce as Active).
- Orders → Completion Rules menu is visible.
- You can open a rule edit screen (Add New under Completion Rules) and see available triggers/conditions/actions.
- Insight Dashboard is accessible from the Orders menu (used to review what happened and why).
- Translations: The UI appears in your site language when translations are available.

Optional next steps:
- Create a simple rule (for example: when payment is completed and the order contains only digital items, set status to Completed).
- Place a test order and verify the rule runs; then view the timeline in the Insight dashboard.

---

## Troubleshooting

- I don’t see the Completion Rules menu
  - Make sure the free plugin is installed and active, and you’re logged in with a role that can manage WooCommerce (Shop Manager or Administrator).

- WooCommerce is required
  - If WooCommerce is inactive, Order Daemon will not fully initialize. Activate WooCommerce and reload your admin.

- Pro is active but nothing new unlocked
  - Ensure the free plugin is also active. Some premium items remain visible in Core but disabled; they unlock when Pro is active (and licensed, if applicable). If licensing applies, check the Pro settings page to verify license status.

- Translations aren’t showing
  - Make sure your site language is set under Settings → General. The plugin loads translations automatically when available.

- Rules don’t seem to run
  - Confirm the rule is enabled. Create a new test order that matches the trigger/conditions. Check the Insight dashboard for a timeline entry and any notes about why a rule did or did not run.

---

## What’s next

- Rules & Automation (concepts): /docs/rules-automation/
- Using the Rule Builder (step‑by‑step): /docs/using-the-rule-builder/
- Audit Log (reading timelines): /docs/audit-log/
- Pro overview: /docs/pro/overview/
