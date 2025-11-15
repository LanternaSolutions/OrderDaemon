# Troubleshooting & FAQ

Audience: Store owners and managers
Last updated: 2025-11-15

This page helps you diagnose common issues and quickly get back to a healthy, automated workflow. It uses plain language and links to other guides when deeper steps are needed.

---

## Quick checks (start here)

- WooCommerce is active
  - Order Daemon depends on WooCommerce. If WooCommerce is deactivated, Order Daemon will not fully initialize.
- Correct user role
  - Use a Shop Manager or Administrator account to see menus and run diagnostics.
- Rule is enabled
  - Draft rules do not run. Open the rule and ensure it’s published/enabled.
- Test with a new order
  - Place a fresh test order to avoid confusion with older data and timelines.

---

## Common installation issues

- WooCommerce missing or outdated
  - Symptom: You don’t see “Completion Rules” or “Insight” menus; some features don’t appear.
  - Fix: Install/activate WooCommerce and update to a supported version. Then reload your admin.

- Pro is active but Core is missing
  - Symptom: Pro says it’s active but nothing is unlocked.
  - Fix: Install and activate the free Order Daemon plugin (Core). Pro is an add‑on and depends on Core.

- Menus not visible (no access)
  - Symptom: You can’t find Order Daemon → All Order Rules or Order Daemon → Insight Dashboard.
  - Fix: Ensure your account can manage WooCommerce (Shop Manager or Administrator). Log out and back in if roles were just changed.

---

## A rule didn’t run (rule not firing)

- Checklist
  - Trigger happened: Confirm the event you picked actually occurred (e.g., status changed to Processing, payment completed).
  - Conditions match: If you added conditions (categories, product types, totals), all of them must pass.
  - Rule is enabled: Draft rules won’t run.
  - New test order: Use a new order that clearly meets (or does not meet) your conditions.

- Use the Audit Log to investigate
  - Go to Orders → Insight.
  - Find the entry around the time of your test.
  - Open the details panel to see which conditions passed or failed and why.
  - Guide: /docs/audit-log/

- Special cases
  - Payment gateways that delay confirmations may use webhooks; rules run after the confirmation arrives. See /docs/webhooks-and-integrations/.

---

## Orders not completing as expected

- Interference from other plugins
  - Payment, fulfillment, fraud, or status‑management plugins may alter order status. Temporarily disable non‑essential plugins to test.

- Gateway behavior differences
  - Some gateways set status directly; others rely on a webhook. If your gateway needs a webhook, configure it and test. See /docs/webhooks-and-integrations/.

- Conflicting rules
  - If multiple rules might apply, refine conditions so each rule targets a distinct scenario.

---

## Translation and language issues

- Strings appear in English
  - Set your Site Language under Settings → General.
  - Ensure you’re on the latest plugin version so translation files are current.

- Script/UI strings
  - Admin screens load JSON translations for the Rule Builder and Insight dashboard automatically when available.

- More help
  - See Security & Privacy notes on data handling and localization: /docs/security-and-privacy/

---

## Performance and scaling

- Large number of rules
  - Keep rules focused and avoid heavy, overlapping conditions. Start simple and expand gradually.

- Checkout impact
  - Rules generally run after order events. If you notice slowdowns, test on a staging site and check the Audit Log timestamps. Use the Diagnostics Dashboard to review environment health.

- Cron/Background processing
  - Ensure your site can run scheduled tasks (WP‑Cron). Your host or a cron service can help ensure reliability.

---

## Diagnostics Dashboard

- Where to find it
  - Order Daemon → Diagnostics.

- What it does
  - Runs health checks for environment, permissions, REST endpoints, and translations.

- When to use it
  - Before contacting support or after changing settings. It often points directly to the root cause.

- Guide: /docs/diagnostics-dashboard/

---

## Pro features show but are disabled

- Expected behavior in the free plugin
  - Premium items appear with a badge and remain disabled until the Pro add‑on is active (and licensed, if applicable).

- After installing Pro
  - Premium items unlock automatically when Pro is active. If licensing applies in your setup, verify the license status in Pro’s settings.

- Pro overview: /docs/pro-overview/

---

## Frequently asked questions (FAQ)

- Can I run multiple rules?
  - Yes. Keep them specific to avoid overlap. If several rules could run, refine conditions.

- Does this modify past orders?
  - Rules run when their trigger happens. To change older orders, create a clear rule and run "Reprocess Pending Orders" from the Insight Dashboard settings.

- Will this affect checkout speed?
  - The plugin is designed to process events efficiently. Use the Diagnostics Dashboard and Audit Log to monitor behavior.

- Where are logs stored?
  - The plugin writes structured entries for the Insight dashboard. See /docs/audit-log/ for how to read them.

---

## Getting support

- Share helpful details
  - A screenshot of the Diagnostics summary and any specific issues.
  - Order numbers used for testing and approximate timestamps.
  - Your WordPress, WooCommerce, and plugin versions.
  - Relevant Audit Log entries (screenshots or copied summaries).

- Where to look before contacting support
  - Diagnostics Dashboard: /docs/diagnostics-dashboard/
  - Audit Log: /docs/audit-log/
  - Getting Started: /docs/getting-started/

Notes about privacy and safety
- The plugin avoids storing unnecessary personal data and respects your site language when translations are available.
