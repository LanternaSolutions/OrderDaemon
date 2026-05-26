=== Order Daemon for WooCommerce ===
Contributors: orderdaemon
Tags: woocommerce, automation, auto complete, digital products, order management
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.28
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically complete WooCommerce orders based on rules you define. Built for digital, virtual, and subscription-based stores.

== Description ==

Order Daemon lets you define rules that automatically complete WooCommerce orders when your conditions are met — no manual intervention needed. It is designed for stores selling virtual, downloadable, or digital products where orders don't require physical fulfillment.

If your store sells software, courses, memberships, or any product that doesn't need packing and shipping, you're probably completing orders manually or relying on a payment gateway integration that may or may not work reliably. Order Daemon gives you explicit, auditable control over when and how orders get completed.

**How it works**

You create rules using a visual rule builder. Each rule has:

* A **trigger** — the event that starts evaluation (e.g. order moves to Processing)
* **Conditions** — filters that must all pass (e.g. product type is Virtual, order total > $0)
* An **action** — what happens when conditions are met (e.g. complete the order)

Rules run automatically in the background. The Insights Dashboard gives you a full audit log of every rule evaluation and order action taken, so you always know exactly what happened and why.

**Free version includes**

* Unlimited active automation rules
* Visual rule builder
* Order processing trigger
* Product type, product category, and order total conditions
* Order completion action
* Full audit log and Insights Dashboard
* Built-in diagnostics tools

**Good for**

* Digital product and software stores
* Course and membership platforms
* SaaS and subscription-based businesses
* Any store where manual order completion is unnecessary overhead
* Recovering orders stuck in Processing

**Need more triggers, conditions, and actions?**

[Order Daemon Pro](https://orderdaemon.com/pricing) adds additional rule components, priority support, and advanced automation for high-volume stores.

== Installation ==

**Via the WordPress plugin directory**

1. Go to Plugins > Add New in your WordPress admin
2. Search for "Order Daemon"
3. Click Install Now, then Activate
4. Go to Order Daemon in your admin menu to create your first rule

**Manual installation**

1. Download the plugin zip file
2. Go to Plugins > Add New > Upload Plugin
3. Upload the zip file and activate
4. Go to Order Daemon in your admin menu to create your first rule

**Requirements**

* WordPress 5.6 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* MySQL 5.6 or higher (or MariaDB equivalent)

== Uninstallation ==

When you uninstall the plugin, all data (rules, audit logs, settings) is preserved by default to prevent accidental loss.

To permanently remove all plugin data before uninstalling, add this line to your `wp-config.php`:

`define('ODCM_REMOVE_ALL_DATA', true);`

Then uninstall the plugin normally. All tables and options will be deleted.

== Frequently Asked Questions ==

= Does Order Daemon work with all payment gateways? =

Yes. Order Daemon monitors standard WooCommerce order status changes, so it works with any payment gateway that correctly transitions orders through WooCommerce statuses.

= Will this slow down my site? =

No. The plugin runs automation during order processing events only, uses optimized database queries, and has no impact on front-end performance.

= Can I create multiple rules? =

Yes. There is no limit on the number of active rules.

= Is it safe to automate order completion? =

Yes. Every rule evaluation and action is logged in the audit log. Orders are only completed when all conditions in a rule are satisfied.

= Does it work with physical products? =

It can be configured to. The conditions system lets you target any product type or category, so you can scope rules precisely to the products where auto-completion makes sense.

= Is customer data secure? =

Yes. Order Daemon does not transmit any data outside your WordPress installation. All processing and logging happens locally on your server.

= Can developers extend the plugin? =

Yes. The plugin exposes hooks and filters for customization. See the [documentation](https://orderdaemon.com/docs) for details.

== Screenshots ==

1. The rule builder — create automation rules using a visual interface with triggers, conditions, and actions.
2. The Insights Dashboard — view a full audit log of rule evaluations and order actions in real time.
3. Rule management — enable, disable, and organize all your automation rules from one screen.

== Third-Party Libraries ==

This plugin uses the following open-source libraries:

* **Alpine.js** — lightweight JavaScript framework for interactive UI. [alpinejs.dev](https://alpinejs.dev/) — MIT License.
* **Prism.js** — syntax highlighting for code display. [prismjs.com](https://prismjs.com/) — MIT License.

== External Services ==

Order Daemon can receive and process webhook notifications from payment gateways. It does not initiate outbound connections unless explicitly verifying a payment notification.

**PayPal**
Receives IPN and webhook payloads (payment status, transaction ID, amount) and may send verification requests back to PayPal servers during PayPal payment processing.
[Terms of Service](https://www.paypal.com/legalhub) | [Privacy Policy](https://www.paypal.com/privacy)

**Stripe**
Receives webhook payloads with event details when your site receives a Stripe webhook. No data is sent to Stripe by this plugin.
[Terms of Service](https://stripe.com/legal/ssa) | [Privacy Policy](https://stripe.com/privacy)

**Mollie**
Receives webhook payloads with event details when your site receives a Mollie webhook. No data is sent to Mollie by this plugin.
[Terms of Service](https://www.mollie.com/en/user-agreement) | [Privacy Policy](https://www.mollie.com/en/privacy)

**Square**
Receives webhook payloads with event details when your site receives a Square webhook. No data is sent to Square by this plugin.
[Terms of Service](https://squareup.com/us/en/legal/general/ua) | [Privacy Policy](https://squareup.com/us/en/legal/general/privacy-notice)

Note: Order Daemon does not connect to Google services. References to Google Tag Manager, Google Analytics, and reCAPTCHA in the diagnostics system are detection patterns only, used to identify potential script conflicts from other plugins or themes.

== Privacy Policy ==

Order Daemon does not collect, store, or transmit any personal data outside of your WordPress installation. All automation activity is logged locally on your server. The plugin respects WordPress privacy standards and GDPR requirements.

== Changelog ==

= 1.3.28 =
* Added: Custom webhook connections in Insight Dashboard — configure a slug-based webhook URL with none, bearer token, or HMAC-SHA256 authentication
* Added: Discount total source option for the Order Amount condition — rules can now compare the order's discount total against a threshold
* Added: Site URL change detection — surfaces an admin notice when the site URL changes, with an Acknowledge button to dismiss
* Fixed: Timeline event ordering, parent-child hierarchy, and parent_id writing — resolves race conditions and ensures rule execution events nest correctly under their triggering business events

= 1.3.27 =
* Improved: Rule Builder mobile responsivity — fixed broken responsive breakpoint, enlarged touch targets on mobile, and improved layout on narrow screens
* Improved: Rule Builder condition component summaries are now more descriptive and extensible
* Improved: Insight Dashboard mobile responsivity and several UI improvements
* Fixed: Gateway adapter validation no longer blocked by missing log method

== Upgrade Notice ==

= 1.3.28 =
Adds custom webhook connections and a new discount total source option for the Order Amount condition.

= 1.3.27 =
UI and mobile usability improvements for the Rule Builder and Insights Dashboard. Recommended update for all users.
