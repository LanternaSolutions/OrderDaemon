# CLI & Automation (Pro)

Audience: Developers building automation/scripts (public developer docs)
Last updated: 2025-11-15

This page describes how to use command-line automation with Order Daemon. Core does not register WP-CLI commands. A Pro add-on may provide a CLI surface for bulk operations and diagnostics. The guidance below is safe to follow and shows recommended patterns, expected shapes, and best practices.

Important
- No CLI in Core: If you run wp help and do not see commands under odcm, that is expected.
- Pro adds CLI: When the Pro add-on is installed and active, it may register commands in the odcm namespace (examples below). Exact command names/flags are subject to the Pro implementation.
- Entitlements still apply: Premium-only behaviors should be gated by odcm_can_use() where appropriate.

---

## Prerequisites

- WP-CLI installed and working: wp --info
- Appropriate permissions on the server to run wp as the site’s PHP user.
- WooCommerce active (the free Core plugin requires it for most operations).

---

## Expected command namespace (Pro)

Pro is expected to register commands under the odcm namespace. These examples are illustrative; consult Pro’s help once installed.

- List rules
  wp odcm rules list --format=table

- Show a rule
  wp odcm rules get <rule_id> --format=json

- Enable/disable a rule
  wp odcm rules update <rule_id> --status=enabled
  wp odcm rules update <rule_id> --status=disabled

- Reprocess an order through the engine
  wp odcm orders reprocess <order_id> [--trigger=<trigger_id>] [--force]

- Bulk reprocess with a query
  wp odcm orders reprocess-batch --status=processing --limit=250 --since=2025-01-01

- Diagnostics (environment / health)
  wp odcm diag run [--category=core|api|performance] [--format=json]

- Audit log maintenance (if supported)
  wp odcm logs cleanup [--days=90] [--dry-run]

Notes
- Use --format=json for machine-readable output in scripts.
- Commands should return non-zero exit codes on failure so scripts can detect errors.

---

## Usage in cron/automation scripts

System cron example
- Crontab: run nightly diagnostics and cleanup.
  5 2 * * * cd /var/www/html && wp odcm diag run --format=json >> /var/log/odcm-diag.log 2>&1
  15 2 * * 0 cd /var/www/html && wp odcm logs cleanup --days=120 >> /var/log/odcm-cleanup.log 2>&1

WP-Cron trigger from CLI
- If your integration needs to queue background jobs, prefer WP-Cron/Action Scheduler and use CLI only as a driver or for inspections.

Idempotency and retries
- Design commands to be idempotent. For long-running batches, store progress markers (e.g., last processed ID or date) and support interruption/resume.

---

## Logging and observability

- Emit structured audit events using odcm_log_event() from within command handlers so operators can find results in the Insight timeline.
- Include correlation fields (e.g., process_id) in the extra payload to tie together batch runs and per-order operations.
- Respect ODCM_DEBUG to emit verbose developer logs sparingly.

---

## Security and permissions

- WP-CLI runs as the system user and bypasses browser nonces. Still enforce capability checks conceptually: when mutating store data, validate that commands are intended for administrators.
- Never expose secrets on the command line that could be captured in process lists or shells. Prefer environment variables for tokens used by your integration.
- For public endpoints (webhooks) do not rely on CLI; use signatures/HMAC and rate limiting as documented elsewhere.

---

## Entitlements (free vs Pro)

- CLI commands that unlock premium behaviors should check odcm_can_use() for relevant capability keys before performing those actions.
- When a capability is not available, fail fast with a clear, translated error and exit code 3 (example). Also emit an audit event noting capability denial.

---

## Examples (illustrative)

- Reprocess a single order and print a summary
  wp odcm orders reprocess 12345 --format=json | jq '.summary'

- Reprocess all processing orders in the last day
  wp odcm orders reprocess-batch --status=processing --since="yesterday" --limit=500 --format=table

- Check available commands (once Pro is active)
  wp help odcm

---

## Troubleshooting

- Command not found: Ensure Pro is installed and activated. Core does not register CLI commands.
- WooCommerce inactive: Most commands will no-op or fail with an explanatory error.
- Permissions / file ownership: wp must run as the same user that owns WordPress files (or with sufficient permissions).
- Exit code non-zero: Inspect stdout/stderr logs; also check the Audit Log in the admin for entries emitted by command handlers.
- Translations: If output includes untranslated keys, confirm the order-daemon text domain is loaded in the environment where wp runs.

---

## Implementation notes (for Pro maintainers)

- Register commands with WP_CLI::add_command under the odcm namespace.
- Use dependency guards: bail early if WooCommerce or Core plugin is inactive.
- Structure handlers as thin wrappers that call into existing services (Core engine, diagnostics), not duplicate logic.
- Return WP_CLI::success / WP_CLI::error consistently; support --format=json for structured outputs.
- Emit odcm_log_event() entries for key milestones and failures; include process IDs for correlation.
- Gate premium behaviors with odcm_can_use() and provide helpful error messages when locked.
- Cover with basic tests where feasible; avoid long-running work inside single commands—delegate to batches/queues.

TODO (Pro repo)
- Finalize exact command names, arguments, and outputs.
- Document performance characteristics and limits for each batch operation.
- Provide examples for multisite (network) environments if supported.
