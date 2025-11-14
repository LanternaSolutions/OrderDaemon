# Diagnostics Dashboard

Audience: Store owners and managers
Last updated: 2025-11-15

The Diagnostics Dashboard helps you check that everything is set up correctly and running smoothly. It runs a series of health checks and highlights anything that might block automations or affect performance.

What it’s for
- Quickly verify your environment (WordPress, WooCommerce, PHP) and plugin setup.
- Spot configuration issues that prevent rules from running.
- See recommendations to fix problems before they impact orders.

Where to find it
- In your WordPress admin, go to Orders → Insight → Diagnostics (or a “Diagnostics” entry under the Order Daemon menu, depending on your version).
- Access requires a user who can manage WooCommerce (Shop Manager or Administrator).

---

Run diagnostics

You can run checks in a few ways:
- Run all diagnostics: Runs the full suite of checks.
- Critical tests only: A quick pass focused on problems that could stop automation.
- Run specific categories: Target a group of checks, for example “Core,” “API,” “Performance,” or “Frontend.”

Tip: If you’re working with support, they may ask you to run a specific category and share the results.

---

Understand the results

Each run shows:
- Overall status
  - Healthy: No issues found.
  - Warning: Non‑critical problems detected; recommended to review.
  - Critical: Items that can block automations or indicate a misconfiguration.
- Issues list
  - Each issue includes a short description and suggested next steps.
  - Critical issues are clearly labeled so you can prioritize them.
- Category breakdown
  - Core: Plugin activation, dependencies, and basic configuration.
  - API: REST endpoints reachability and permissions.
  - Performance: Settings that can affect speed or stability.
  - Frontend: Assets and translation loading relevant to the admin UI.

Notes about translations
- Messages in the dashboard are translated to your site language when translations are available.

---

Act on recommendations

Typical recommendations you might see and what to do:
- WooCommerce is inactive or outdated
  - Activate/Update WooCommerce and re‑run diagnostics.
- Missing permissions to manage rules
  - Log in with a Shop Manager or Administrator account.
- REST API blocked by a security plugin
  - Whitelist WordPress REST API routes for your admin users; then try again.
- Translations not loaded
  - Ensure your Site Language is set under Settings → General; update to the latest plugin version so language files are current.
- Premium options appear but are disabled
  - Those are part of the Pro add‑on. You can keep using all free features; install Pro to unlock premium items if needed.

When to contact support
- You’ve fixed the suggested items but still see critical issues.
- Automations don’t run even though Diagnostics shows Healthy.

Helpful details to include
- A screenshot of the Diagnostics summary and any specific issues.
- The Overall status and category breakdown (copy/paste if easier).
- The order number(s) you tested and approximate time of testing.

---

Troubleshooting

- I can’t find the Diagnostics page
  - Make sure Order Daemon and WooCommerce are active, and that your user can manage WooCommerce.
- Checks never finish or time out
  - Try running Critical tests only first. If that works, run categories one by one to find the slow area.
- I see English text instead of my language
  - Confirm your Site Language in Settings → General. The plugin loads translations automatically when available.

---

What’s next
- Read timelines in the Audit Log: /docs/audit-log/
- Using the Rule Builder: /docs/using-the-rule-builder/
- General Troubleshooting guide: /docs/troubleshooting/
