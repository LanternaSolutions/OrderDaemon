=== Order Daemon for WooCommerce ===
Contributors: orderdaemon
Tags: woocommerce, orders, automation, order-management, event-log
Requires at least: 5.6
Tested up to: 7.0
Stable tag: 1.3.36
Requires PHP: 7.4
WC requires at least: 5.0
WC tested up to: 10.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce order automation with WHEN/IF/THEN rules and a live event log. Catch checkout failures before your customers do.

== Description ==

Order Daemon runs in the background of your WooCommerce store, applying automation rules to every order and recording every event to a searchable log. You build the rules. The daemon handles them — and shows you exactly what it did.

**Three things it does well**

= 01 · Automate =

Build automation rules using a visual WHEN / IF / THEN builder. No code. Each rule has a trigger (the WooCommerce event that starts evaluation), conditions (the filters that narrow the match), and an action (what executes when all conditions pass). Rules run automatically. There is no limit on how many you can create.

Common rules stores use on day one:

* Complete virtual and downloadable orders immediately after payment
* Auto-complete orders when every item in the cart is digital
* Complete orders above a threshold amount for verified customers
* Flag orders stuck in Processing for manual review

= 02 · Observe =

Every order event flows into the Insight Dashboard — a live, searchable log stream. Rule executions, status changes, checkout failures, payment events. Every event WooCommerce emits is captured, timestamped, and retained for 30 days.

Click any row to open the full event timeline for that order: every event on a single spine with time deltas, color-coded by severity, with raw event data one click away. Most WooCommerce stores have no visibility into what is actually happening to their orders. Order Daemon gives you a flight recorder.

= 03 · Diagnose =

Checkout failures are logged the moment they occur — before any customer complains. The log stream surfaces payment hiccups, rule conditions that did not match, and orders that stopped moving. You see it first.

**What the free version includes**

* Unlimited automation rules
* Visual WHEN / IF / THEN rule builder
* Order processing trigger
* Conditions: product type, product category, order total
* Action: complete the order
* Full Insight Dashboard — live log stream and per-order event timeline
* 30-day event log retention
* Built-in diagnostics and compatibility tools
* HPOS compatible (High-Performance Order Storage)

**Order Daemon Pro**

[Pro](https://orderdaemon.com/pricing/) adds more triggers, conditions, and actions; rule testing against real past orders without modifying state; webhook connections; WP-CLI; and extended log retention — upgrade when the free rule components are not enough or you need external integrations.

== Installation ==

**Via the WordPress plugin directory**

1. Go to Plugins → Add New in your WordPress admin
2. Search for "Order Daemon"
3. Click Install Now, then Activate
4. Navigate to Order Daemon in your admin menu to create your first rule

**Manual installation**

1. Download the plugin zip
2. Go to Plugins → Add New → Upload Plugin
3. Upload and activate
4. Navigate to Order Daemon to create your first rule

**Requirements**

* WordPress 5.6 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher

== Uninstallation ==

All data (rules, event log, settings) is preserved when you deactivate or delete the plugin — nothing is dropped automatically. To permanently remove all plugin data before uninstalling, add this to `wp-config.php`:

`define('ODCM_REMOVE_ALL_DATA', true);`

Then uninstall normally. All tables and options will be removed.

== Frequently Asked Questions ==

= Does this work with physical / shippable products? =

Yes. The conditions system lets you target any product type, category, or order total, so you can scope rules precisely. Most stores use Order Daemon for virtual and downloadable products, but it works with any order type.

= Does it work with HPOS (High-Performance Order Storage)? =

Yes. Order Daemon declares HPOS compatibility and uses WooCommerce's order abstraction layer throughout.

= Will it slow down my site? =

No. Automation rules run during WooCommerce order lifecycle hooks only, not on the front end. The event log uses optimised queries. There is no impact on page load time.

= Does it work with all payment gateways? =

Yes, as long as the gateway transitions orders through standard WooCommerce statuses. The plugin monitors order status changes, not payment gateway internals directly.

= Is the event log stored locally? =

Yes. All event data is stored in your WordPress database. Nothing is transmitted to external servers. See the External Services section for details about inbound webhook processing.

= What happens to my data if I uninstall? =

Your data is preserved unless you explicitly set `ODCM_REMOVE_ALL_DATA` in wp-config.php before uninstalling. See the Uninstallation section above.

= Can developers extend the plugin? =

Yes. The plugin exposes actions and filters for customisation. See the [documentation](https://orderdaemon.com/docs/) for the full hook reference.

= What is the difference between the free version and Pro? =

The free version includes unlimited rules, the full Insight Dashboard, and the core set of triggers, conditions, and actions. [Pro](https://orderdaemon.com/pricing/) adds more rule components, rule testing, webhook connections, WP-CLI, extended log retention, and priority support.

== Screenshots ==

1. The Insight Dashboard — live event log stream showing every rule execution, status change, and checkout failure with severity badges and timestamps.
2. The rule builder — visual WHEN / IF / THEN interface for creating automation rules without code.
3. Order event timeline — the full event history for a single order, with time deltas between events and expandable raw event data.
4. Rule management — enable, disable, and reorder all active rules from one screen.

== Third-Party Libraries ==

This plugin uses the following open-source libraries:

* **Alpine.js** — lightweight JavaScript framework for interactive UI components. [alpinejs.dev](https://alpinejs.dev/) · MIT License
* **Prism.js** — syntax highlighting for raw event data display. [prismjs.com](https://prismjs.com/) · MIT License

== External Services ==

Order Daemon receives inbound webhook payloads from payment gateways and may send outbound verification requests during payment processing. It does not initiate any other external connections.

**PayPal** — receives IPN and webhook payloads (payment status, transaction ID, amount); may send verification requests to PayPal during processing.
[Terms of Service](https://www.paypal.com/legalhub) | [Privacy Policy](https://www.paypal.com/privacy)

**Stripe** — receives webhook payloads for event processing. No data is sent to Stripe by this plugin.
[Terms of Service](https://stripe.com/legal/ssa) | [Privacy Policy](https://stripe.com/privacy)

**Mollie** — receives webhook payloads. No data is sent outbound by this plugin.
[Terms of Service](https://www.mollie.com/en/user-agreement) | [Privacy Policy](https://www.mollie.com/en/privacy)

**Square** — receives webhook payloads. No data is sent outbound by this plugin.
[Terms of Service](https://squareup.com/us/en/legal/general/ua) | [Privacy Policy](https://squareup.com/us/en/privacy)

Note: References to Google Tag Manager, Google Analytics, and reCAPTCHA in the diagnostics system are detection patterns only, used to identify potential conflicts from other plugins or themes. Order Daemon does not connect to Google services.

== Privacy Policy ==

Order Daemon does not collect, store, or transmit any personal data outside your WordPress installation. All automation activity is logged locally on your server. The plugin respects WordPress privacy standards and GDPR requirements.

== Changelog ==

= 1.3.35 =
* Fixed: Insight Dashboard showed raw dot-notation strings (e.g. `core.log.event.order_completed`) instead of English labels — `load_plugin_textdomain()` was never called so the bundled translation file was never loaded
* Fixed: `RefundDeletionDiagnostics::build_summary()` passed translation keys directly to `sprintf()` without `__()`, so refund and deletion event summaries were never translated regardless of locale
* Fixed: 6 missing translation entries for `_simple` and `order_refunded` event keys added to `en_US.po`; `.mo` recompiled

= 1.3.34 =
* Fixed: Insight Dashboard loading error caused by Alpine.js defer timing race condition

= 1.3.33 =
* Fixed: HPOS order meta lookups now use `wc_get_orders()` instead of raw SQL with wrong table and column names — all three lookup methods silently returned empty results on every HPOS store
* Fixed: Removed phantom `find_order_by_meta_hpos_optimized()` call that threw a fatal Error (caught silently) on every transaction ID lookup for HPOS stores
* Fixed: Direct `get_post_meta()` calls on order and refund IDs in event processing and refund diagnostics replaced with WC CRUD API — correct behaviour for full HPOS mode without compatibility mode

== Upgrade Notice ==

= 1.3.35 =
Fixes event translations in audit log.

= 1.3.34 =
Fixes Insight Dashboard loading error.

= 1.3.33 =
HPOS meta lookups fixed; WC 10.7 ready.

= 1.3.29 =
Full admin UI overhaul: new Settings page, custom Rules List, component icons, and dark mode improvements across all admin pages.

= 1.3.28 =
Adds custom webhook connections, a discount total source option for the Order Amount condition, and site URL change detection.

= 1.3.27 =
UI and mobile usability improvements for the Rule Builder and Insights Dashboard. Recommended update for all users.