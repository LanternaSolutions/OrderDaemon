# Webhooks & External Integrations

Audience: Store owners and managers
Last updated: 2025-11-15

This page explains, in plain language, how Order Daemon connects with other systems using webhooks. You don’t need to be a developer to understand the basics. If you work with a developer or a third‑party service, share this page so everyone aligns on the flow and where to look when testing.

---

## What is a webhook?

A webhook is a simple way for apps to talk to each other in real time. One app sends a small message (an HTTP request) to another app when something happens. In our case, external services can notify your store about events (like payments, subscription changes, or app actions) and Order Daemon can react with your rules.

Examples
- A payment provider sends a “payment completed” webhook → your rule completes the order.
- A course platform sends a “user enrolled” webhook → your rule adds a note or tags the order (Pro, if actions/options apply).

---

## What you can do with webhooks

- Receive events from external systems and let your rules run automatically when those events arrive.
- See webhook arrivals in the Audit Log (Insight dashboard) to understand what came in and what rules ran.
- Test and verify your webhook setup safely.

Note: Outbound webhooks (your site sending webhooks out to other systems) are not part of the free plugin at this time. If you need outbound or advanced integration features, see the Pro notes below.

---

## Where to configure webhooks

- In most cases, you configure webhooks in the external service (e.g., your payment provider, automation tool, or app). They will ask you for a “Webhook URL.”
- Order Daemon provides a standard endpoint on your site that can accept these messages. Your developer or the app’s guide will tell you the “gateway” name to use in the URL.

Webhook URL pattern
- https://your-site.com/wp-json/odcm/v1/webhooks/{gateway}
  - Replace "your-site.com" with your domain.
  - Replace {gateway} with the integration’s key (letters/numbers/dashes/underscores). Example: `stripe`, `zapier`, `make`, etc.

Health check URL
- https://your-site.com/wp-json/odcm/v1/webhooks/health
  - Use this to confirm your site is reachable. It does not validate secrets.

Admin test tools (for site admins)
- Your site admins can generate test events using the built‑in test endpoint (admin‑only). Ask your developer or administrator to run a test if needed.

---

## How to verify it’s working

1) Send a test from the external service (or ask your admin to use the test tool).
2) Open Orders → Insight (the Audit Log) in your WordPress admin.
3) Look for a new entry showing the webhook gateway/source and a short summary.
4) Click it to open details. If a rule ran, you’ll see which trigger/conditions matched and what action was taken.

If you don’t see an entry:
- Check that your site URL is correct and publicly reachable.
- Confirm the gateway key in the URL matches what your service expects.
- Ask your developer to verify any required shared secret/signature settings.

---

## Security tips (plain language)

- Most services let you set a secret or signature so only trusted messages are accepted. Use it when available.
- Treat webhook URLs as sensitive. Don’t share them publicly.
- If a service retries a lot, it often means your URL or secret is wrong, or your site blocked the request. Fix the setup rather than disabling security.

Behind the scenes (non‑technical summary):
- The Order Daemon webhook endpoint is intentionally open so third‑party services can reach it. Authentication is usually provided by the service’s secret/signature headers. Your developer will enable these checks when setting up the adapter.
- Responses return HTTP 200 even on processing errors so third‑party systems don’t retry endlessly. You’ll still see success/failure details in the Audit Log.

---

## Common use cases

- Payment completed events from gateways that prefer webhooks to confirm payments.
- External automation tools (e.g., Zapier/Make) notifying your store of business events.
- Internal systems (ERP/CRM) sending order updates to trigger rules.

---

## Troubleshooting

- “The external service says the webhook failed”
  - Open the Audit Log and search for entries around the time of the test.
  - If nothing appears, recheck the URL and gateway key. Use the health URL to confirm the site is reachable.
  - Verify any shared secret/signature settings with your developer.

- “I see the webhook entry, but no rules ran”
  - Open the entry and read the details. Check triggers and conditions in your rule to ensure they match the webhook’s data.
  - Try a simpler rule for testing, then add conditions gradually.

- “Messages are in English”
  - The plugin supports translation. Your WordPress site language determines the UI language. Translated messages appear when translations are available.

---

## Pro notes

- Some advanced integration options, outbound webhooks, or specialized actions/conditions may require the Pro add‑on. In the free plugin, you may see certain options with a “Premium” badge; they remain disabled until Pro is active (and licensed, if applicable).
- For a high‑level summary of what Pro adds, see: /docs/pro-overview/

---

## What’s next

- Review the Audit Log: /docs/audit-log/
- Learn rules and conditions: /docs/rules-automation/
- Getting started guide: /docs/getting-started/