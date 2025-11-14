# Rules and Automation — Concepts for Store Owners

Audience: Store owners and managers
Last updated: 2025-11-15

This page explains, in plain language, how Order Daemon’s automation works: Rules, Triggers, Conditions, and Actions. You don’t need to be technical to use it.

What is a rule?
- A rule is a simple instruction you set for your store: “If this happens, and certain things are true, then do that.”
- You manage rules in WordPress under: Orders → Completion Rules.
- You can keep rules as drafts while you experiment, then enable them when you’re ready.

How a rule is structured
- Trigger — When should the rule run?
  - Examples:
    - “Order status changes to Processing.”
    - “Payment is completed.”
    - “Order is created/paid.”
  - The plugin listens to WooCommerce events and wakes rules at the right time.
- Conditions — Should the rule apply to this order?
  - Examples (Core):
    - Product categories: only if all items are from selected categories.
    - Product types: only digital/downloadable products, or exclude certain types.
    - Order total: only if the order value is above/below a threshold.
  - You can combine multiple conditions; they all must pass for the rule to run.
- Actions — What should happen when conditions pass?
  - Example (Core):
    - Change the order status to Completed.
  - Depending on your setup, you may also add secondary actions (e.g., add a note). If an option appears with a “Premium” badge, it requires the Pro add‑on.

Typical examples
- Auto‑complete digital orders
  - Trigger: Payment completed
  - Conditions: Products are all digital/downloadable
  - Action: Set status to Completed
- High‑value orders
  - Trigger: Order status changes to Processing
  - Condition: Order total is at least $50 (or your currency)
  - Action: Set status to Completed
- Category‑specific handling
  - Trigger: Payment completed
  - Condition: Products belong to a specific category (e.g., “Courses”)
  - Action: Set status to Completed

Free vs Pro (at a glance)
- Free (Core):
  - Essential triggers and conditions for common completion scenarios.
  - Change order status action.
- Pro (Add‑on):
  - More trigger options and more granular conditions.
  - Additional actions or settings for complex workflows.
- In the UI, premium options are shown with a badge and are disabled unless Pro is active. This helps you discover what’s possible without breaking anything.

How rules are evaluated
- Order Daemon checks rules automatically whenever their trigger event happens.
- If all the conditions you set are met, the action runs.
- Every time a rule runs, the plugin records a timeline entry you can view in the Insight dashboard.

Where to manage rules
- Go to Orders → Completion Rules.
- Click “Add New” to create a rule, or open an existing rule to edit it.
- Use the Rule Builder to pick a trigger, add conditions, and choose actions. Save any time; enable the rule when ready.

Tips and best practices
- Start simple: Create one clear rule and test with a fresh order.
- Name rules clearly: e.g., “Complete digital-only orders after payment.”
- Use the Insight dashboard if something doesn’t run as expected; it shows why a rule did or didn’t run.
- Keep overlapping rules to a minimum to avoid confusion; if multiple rules could apply, use conditions to separate them.

Troubleshooting basics
- A rule didn’t run:
  - Make sure the rule is Enabled (not Draft).
  - Confirm the trigger you chose actually happened (e.g., status reached Processing).
  - Check that all conditions match the order you tested.
  - Open the Insight dashboard to see timeline entries for that order.
- Premium options are disabled:
  - Those require the Pro add‑on. Install/activate Pro to unlock them.

What’s next
- Learn the Rule Builder step‑by‑step: /docs/using-the-rule-builder/
- Review the Audit Log to see what happened and why: /docs/audit-log/
- Get set up from scratch: /docs/getting-started/
