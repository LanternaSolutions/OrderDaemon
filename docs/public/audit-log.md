# Audit Log — Reading Timelines and Finding Answers

Audience: Store owners and managers
Last updated: 2025-11-15

The Audit Log (Insight dashboard) shows a clear timeline of what Order Daemon did (or decided not to do) for your orders. Use it to confirm that automation is working and to troubleshoot when something doesn’t run.

---

## Where to find it

- In WordPress admin, go to Orders → Insight (or Orders → Insight Dashboard).
- You’ll see a list of recent events with filters at the top and a detail pane for the selected entry.

Tip: If you don’t see the Insight menu, make sure WooCommerce and Order Daemon are active and that your user can manage WooCommerce (Shop Manager or Administrator).

---

## What you’re looking at

Each row in the list represents an event or processing run. Common items:

- Status/Result: Whether the automation succeeded, partially succeeded, or failed.
- Event type: What happened, in simple terms (for example: Rule evaluated, Order status changed, Security check failed, Webhook received).
- Source: Where the event came from (automation/rules, manual change, webhook, payment gateway, etc.).
- Order/ID: The related order (or other object) and reference IDs.
- Time: When the event occurred (site time).

Click a row to see the details panel. You’ll usually see:
- Summary: A human‑readable explanation of the outcome.
- Components: A step‑by‑step breakdown of what ran (conditions evaluated, actions executed, results).
- Context: Useful data like trigger/conditions, order info snapshots, or webhook context.

---

## How to read entries (examples)

- Successful rule run
  - You’ll see the rule name, the trigger that fired (e.g., Payment completed), each condition marked as passed, and the action Set status to Completed executed.
- Rule didn’t run
  - You’ll see the trigger event, then one or more conditions marked as not met (for example: Order total below the threshold). The summary explains why no action was taken.
- Manual action
  - Labeled as manual in the source. Useful for distinguishing human changes from automation.
- Webhook received
  - Shows the gateway/source, a summary of parsed data, and any rules that ran as a result.

---

## Filters in Core (free)

- Basic search: Use the search box to find entries by order ID or keywords in the summary.

Note: Filter availability can vary by version; the core view focuses on the most common needs and keeps performance high on busy stores.

---

## Filters in Pro (clearly marked)

If you use the Pro add‑on, additional filters may appear with a Premium badge. These help you narrow down large timelines:

- Date range
- Status (success, partial, failure)
- Event type (rule evaluation, webhook, security, etc.)
- Source (automation, manual, gateway)

In the free plugin, these controls are visible for discoverability but disabled, with a short educational note. Pro unlocks them automatically when active (and licensed, if applicable).

Server‑side protection: Filtering is enforced on the server. Changing URL parameters does not bypass Pro-only limits.

---

## Troubleshooting with the Audit Log

- "Why didn’t my rule run?"
  - Open the related order’s entries. Look for a rule evaluation around the time of the event.
  - Read the components: any condition marked as not met explains the skip.
- "Did the order complete automatically?"
  - Find the entry for the order and look for the action Set status to Completed with a success status.
- "A webhook arrived but nothing happened"
  - Open the webhook entry and check the summary. Confirm the expected event type was detected and that rules were considered.

Tips
- Reproduce with a fresh test order so the timeline is clean and easy to follow.
- Keep rules specific; it’s easier to see why a single, focused rule fired or didn’t.

---

## Notes about translations and messages

- Labels and messages in the dashboard are translated into your site language when translations are available.

---

## What’s next

- Using the Rule Builder: /docs/using-the-rule-builder/
- Rules & Automation (concepts): /docs/rules-automation/
- Installation & Setup: /docs/getting-started/
- Pro Overview (advanced filters/webhooks): /docs/pro/overview/
