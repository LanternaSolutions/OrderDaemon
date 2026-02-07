=== Order Daemon for WooCommerce ===
Contributors: orderdaemon
Tags: woocommerce, automation, order-completion, virtual-products, order-management
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Rule-based automation for WooCommerce order completion. Auto-complete orders based on products, payment, and custom conditions.

== Description ==

Stop wasting time manually completing WooCommerce orders. Order Daemon is a lightweight, high-performance utility that ensures your virtual and downloadable orders are completed reliably, every time, without slowing down your site.

**Transform Your Order Management**

Order Daemon revolutionizes how you handle WooCommerce orders by providing intelligent, rule-based automation that works seamlessly in the background. Whether you're selling digital products, courses, memberships, or any virtual goods, Order Daemon ensures your customers receive their purchases instantly while you focus on growing your business.

**Key Features**

* **Intelligent Rule Builder** - Create custom automation rules with an intuitive visual interface
* **Multiple Triggers** - Respond to order processing events, payment confirmations, and more
* **Flexible Conditions** - Target specific products, categories, order amounts, and customer types
* **Reliable Actions** - Automatically complete orders, send notifications, and trigger workflows
* **Real-time Monitoring** - Track all automation activity with comprehensive audit logs
* **Performance Optimized** - Lightweight architecture that won't slow down your site
* **Security First** - Built with WordPress security best practices and capability-based access control
* **Developer Friendly** - Extensible architecture with hooks and filters for customization

**Perfect For**

* Digital product stores
* Course and training platforms
* Membership sites
* Software and app downloads
* Virtual service providers
* Any business selling non-physical products

**How It Works**

1. **Set Up Rules** - Define when orders should be automatically completed using our visual rule builder
2. **Choose Triggers** - Select what events should activate your rules (order processing, payment received, etc.)
3. **Add Conditions** - Specify which orders qualify (product types, categories, amounts, etc.)
4. **Define Actions** - Set what happens when conditions are met (complete order, send emails, etc.)
5. **Monitor Results** - Track all automation activity through the comprehensive insights dashboard

**Free Version Includes**

* Unlimited active automation rules
* Rule creation and management
* Order processing trigger
* Product type and category conditions
* Order completion action
* Audit logging and reporting
* Diagnostics dashboard

**Why Choose Order Daemon?**

Unlike other automation plugins that try to do everything, Order Daemon focuses specifically on order completion automation. This laser focus allows us to deliver:

* **Superior Performance** - Optimized specifically for order processing workflows
* **Reliability** - Robust error handling and fallback systems ensure orders are never missed
* **Simplicity** - Clean, intuitive interface that doesn't overwhelm you with unnecessary features
* **Compatibility** - Works seamlessly with popular payment gateways, themes, and plugins
* **Support** - Dedicated support team with deep WooCommerce expertise

== Installation ==

**Automatic Installation**

1. Log in to your WordPress admin dashboard
2. Navigate to Plugins > Add New
3. Search for "Order Daemon"
4. Click "Install Now" and then "Activate"
5. Go to Order Daemon in your admin menu to set up your first rule

**Manual Installation**

1. Download the plugin zip file
2. Log in to your WordPress admin dashboard
3. Navigate to Plugins > Add New > Upload Plugin
4. Choose the downloaded zip file and click "Install Now"
5. Click "Activate Plugin"
6. Go to Order Daemon in your admin menu to set up your first rule

== Uninstallation ==

For complete information about uninstallation, data preservation, and removal
options, please see the UNINSTALLATION.md file included with the plugin.

**Default Behavior**: All your data (rules, audit logs, settings) is preserved when you uninstall the plugin to prevent accidental data loss.

**Complete Removal**: If you want to completely remove all data, add `define('ODCM_REMOVE_ALL_DATA', true);` to your wp-config.php file before uninstalling.

**Requirements**

* WordPress 5.6 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* MySQL 5.6 or higher (or MariaDB equivalent)

== Frequently Asked Questions ==

= Does Order Daemon work with all payment gateways? =

Yes! Order Daemon works with any payment gateway that properly updates WooCommerce order statuses. It monitors standard WooCommerce order status changes, so compatibility is universal.

= Will this plugin slow down my site? =

No. Order Daemon is built with performance as a top priority. It uses efficient database queries, minimal resource consumption, and runs automation in the background without affecting your site's front-end performance.

= Can I create multiple automation rules? =

Yes! The free plugin allows you to create unlimited active rules to handle various scenarios.

= Is it safe to automate order completion? =

Absolutely. Order Daemon includes comprehensive safety measures including audit logging, rollback capabilities, and extensive testing to ensure orders are only completed when appropriate conditions are met.

= Does it work with digital/virtual products? =

Yes! Order Daemon is specifically designed for virtual and downloadable products, though it can be configured to work with any product type based on your automation rules.

= Can I see what the plugin is doing? =

Yes. The Insights Dashboard provides real-time monitoring of all automation activity, including detailed logs of every action taken, performance metrics, and system status.

= Is customer data secure? =

Yes. Order Daemon follows WordPress security best practices, includes capability-based access control, and maintains audit trails of all administrative actions. No customer data is transmitted outside your site.

= Can developers extend the plugin? =

Yes! Order Daemon includes numerous hooks and filters for developers to extend functionality. The architecture is designed to be developer-friendly with clear documentation and examples.

== Screenshots ==

1. **Rule Builder Interface** - Create automation rules with an intuitive drag-and-drop interface
https://www.orderdaemon.com/wp-content/uploads/2026/01/rule-builder-2.png
2. **Insights Dashboard** - Monitor audit log automation activity and performance in real-time
https://www.orderdaemon.com/wp-content/uploads/2026/01/insight-dashboard.png
3. **Rule Management** - Organize and manage multiple automation rules from a central location
https://www.orderdaemon.com/wp-content/uploads/2026/01/all-order-rules.png

== Third-Party Libraries ==

This plugin proudly uses the following open-source projects:

*   **Prism.js** for syntax highlighting. See [https://prismjs.com/](https://prismjs.com/). Licensed under the MIT License.
*   **Alpine.js** for interactive UI elements. See [https://alpinejs.dev/](https://alpinejs.dev/). Licensed under the MIT License.

== Support ==

**Documentation**
Comprehensive documentation is available at [https://orderdaemon.com/docs](https://orderdaemon.com/docs)

**Support Forums**
Get help from our community and support team in the WordPress.org support forums.

**Contributing**
Order Daemon is open source! Developers can contribute to the project and report issues on our GitHub repository.

== External Services ==

This plugin connects to PayPal's API services to verify PayPal payment notifications and ensure secure transaction processing.

**PayPal API Services**
- **Service**: PayPal IPN and Webhook Verification
- **Purpose**: Verify authenticity of PayPal payment notifications to prevent fraud
- **Data Sent**: Transaction details (payment status, transaction ID, amount, currency) and webhook payloads
- **When Sent**: During PayPal payment processing when configured to handle PayPal payments
- **Terms of Service**: https://www.paypal.com/legalhub
- **Privacy Policy**: https://www.paypal.com/privacy

**Important Notes About Google Services**
The plugin's diagnostic system mentions Google Tag Manager (https://googletagmanager.com), Google Analytics (https://google-analytics.com), and reCAPTCHA (https://www.google.com/recaptcha/api.js) as examples of common third-party services that might be detected as duplicates. However, Order Daemon does NOT actively connect to or use these Google services. These are only diagnostic reference patterns used to identify potential script conflicts caused by other plugins or themes.

== Debug API Endpoints ==

**Note:** These endpoints are only available when `ODCM_DEBUG` constant is set to `true` in your `wp-config.php`. They are intended for development and troubleshooting purposes only and should generally not be enabled on production sites.

**Available Endpoints:**

1. **Diagnostic Check**
   - **Endpoint:** `GET /wp-json/odcm/v1/audit-log/diagnostic`
   - **Purpose:** Verifies API route functionality and provides system diagnostics
   - **Security:** Public access (debug mode only)
   - **Response:** System status and configuration information

2. **Raw Timeline Data**
   - **Endpoint:** `GET /wp-json/odcm/v1/audit-log/raw-data/{log_id}`
   - **Purpose:** Returns unprocessed timeline data for debugging rendering issues
   - **Security:** Public access (debug mode only)
   - **Response:** Complete raw timeline data structure

**Security Warning:**
These endpoints expose sensitive system information and should only be enabled temporarily for debugging purposes. Always disable debug mode after troubleshooting is complete.

== Privacy Policy ==

Order Daemon does not collect, store, or transmit any personal data outside of your WordPress installation. All automation activity is logged locally on your server for auditing purposes. The plugin respects WordPress privacy standards and GDPR compliance requirements.

The plugin may connect to PayPal's API services to verify payment notifications when processing PayPal transactions, as documented in the External Services section above.

== License ==

This plugin is licensed under the GPL v2.

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
