# Order Daemon for WooCommerce — Overview and Key Concepts

Audience: Store owners and managers
Last updated: 2025-11-15

Welcome to Order Daemon — an automation assistant for your WooCommerce store. It helps you complete orders automatically, reduce manual work, and keep a clear audit trail of what happened and why.

What Order Daemon does
- Automates order completion based on simple, flexible rules.
- Listens to order events (like payments completing or statuses changing) and applies your rules in the background.
- Records a human‑readable timeline of each automated action so you can review and troubleshoot when needed.

Typical use cases
- Digital products: Automatically mark paid orders as Completed so customers get access immediately.
- High‑value orders: Complete orders automatically when the total is above a certain amount.
- Category‑specific handling: Treat certain product categories differently (e.g., complete only if they’re all virtual/downloadable).
- Hybrid stores: Use different rules for physical vs digital goods.

How it works (in plain language)
- You create “rules” that say: “If this happens, and certain conditions are met, then do that.”
  - “If this happens” is a trigger (for example: “payment completed” or “order status changed to processing”).
  - “Conditions” narrow it down (for example: “order total is at least $50” or “contains only digital items”).
  - “Then do that” is an action (for example: “set status to Completed”).
- Once a rule is enabled, Order Daemon watches your store and runs the action whenever the trigger and conditions match.
- Every time it runs, it adds an entry to a timeline so you can see what ran and why.

Free vs Pro at a glance
- Free (Core):
  - Essential triggers and conditions for common completion scenarios.
  - Basic actions to set order status.
  - Insight dashboard to review what the automation did, with core filters.
- Pro (Add‑on):
  - Additional triggers, conditions, and advanced options for complex workflows.
  - More flexible filters and analytics in the dashboard.
  - Designed for stores with multi‑step rules or specialized fulfillment needs.

Notes
- The plugin UI and messages are translated into your site’s language when translations are available.
- Premium features appear in the UI for discoverability and are clearly marked. If Pro isn’t active, these options are shown as disabled with an educational note.

Quick start
1) Install and activate Order Daemon (free). Make sure WooCommerce is active.
2) Go to Orders → Completion Rules and add your first rule.
3) Pick a trigger, set a few conditions, and choose the “Complete order” action.
4) Save and enable the rule. New matching orders will complete automatically.

Where to go next
- Getting Started: /docs/getting-started/
- Rules & Automation: /docs/rules-automation/
- Pro Overview: /docs/pro-overview/

Troubleshooting
- If rules don’t seem to run, check that WooCommerce is active and your rule is enabled.
- If you see premium items but can’t use them, you need the Pro add‑on for those features.
- For more detail on what happened for a specific order, open the Insight dashboard and look at the timeline entries.
