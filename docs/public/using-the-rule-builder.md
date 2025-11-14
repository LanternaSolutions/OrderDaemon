# Using the Rule Builder

Audience: Store owners and managers
Last updated: 2025-11-15

This guide walks you through creating and managing automation rules that complete orders automatically.

---

## Open the Rule Builder

- In your WordPress dashboard, go to Orders → Completion Rules.
- Click Add New to create a new rule, or click an existing rule to edit it.

Tip: If you don’t see the Completion Rules menu, make sure WooCommerce and Order Daemon are both active, and that your user can manage WooCommerce (Shop Manager or Administrator).

---

## Create a new rule (step‑by‑step)

1) Choose a trigger (When should this rule run?)
- Examples:
  - Payment completed
  - Order status changed to Processing
- Pick one trigger. Some triggers have simple options to narrow down when they fire.

2) Add conditions (What must be true?)
- Examples (Core):
  - Product categories match your selection
  - Product types include only virtual/downloadable items
  - Order total is above/below a threshold
- You can add multiple conditions. All conditions must pass for the rule to run.

3) Choose action(s) (What should happen?)
- Core action: Set order status to Completed.
- You can also add secondary actions when available.

4) Save and activate
- Click Save to store your changes.
- Enable the rule (toggle/publish) so it can run on new orders.

---

## Understanding the fields

- Category pickers: Choose one or more product categories for your condition.
- Product type: Multi‑select to include/exclude types (simple, variable, virtual, downloadable, etc.).
- Order total: Choose an operator (e.g., “at least”) and enter an amount.
- Labels and descriptions in the editor are translated into your site language when translations are available.

---

## Pro callouts (if you see badges)

- Some triggers, conditions, or options are marked as Premium. In the free plugin, these appear disabled with a short educational note.
- When the Pro add‑on is active (and licensed if applicable), these items unlock automatically.
- You can still build and use rules with all free components without Pro.

---

## Test your rule

- Place a new test order that should match your rule.
- After checkout or when the trigger event happens, the rule will run in the background.
- Open Orders → Insight (the Insight dashboard) to see a timeline entry showing what ran and why. This is helpful for confirming and troubleshooting.

---

## Manage existing rules

- Edit: Open a rule, adjust its trigger/conditions/actions, and Save.
- Duplicate (if available): Create a copy to tweak similar rules.
- Delete: Remove rules you no longer need.
- Organize: Use clear names and group related rules (e.g., by product line or use case).

Best practices
- Start simple: One clear rule is easier to test than many complex ones.
- Be specific: Narrow conditions prevent rules from running when they shouldn’t.
- Review timelines: Use the Insight dashboard to monitor results and refine settings.

---

## Troubleshooting

- I can’t save a rule with a premium item
  - Premium items require the Pro add‑on. Replace the premium component with a free alternative or activate Pro.

- My rule didn’t run
  - Confirm the rule is enabled and that your test order actually matches the trigger and every condition. Check the Insight dashboard for a timeline entry and any notes.

- I don’t see fields in my language
  - Make sure your site language is set under Settings → General. The plugin loads translations automatically when available.

---

## What’s next

- Learn the concepts: /docs/rules-automation/
- Read timelines: /docs/audit-log/
- Installation & Setup: /docs/getting-started/
