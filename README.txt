=== Order Daemon for WooCommerce ===
Contributors: orderdaemon
Tags: woocommerce, automation, order-completion, virtual-products, order-management
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.1
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

* One active automation rule
* Basic rule creation and management
* Order processing trigger
* Product type and category conditions
* Order completion action
* Audit logging and basic reporting
* Security and performance monitoring

**Premium Version Adds**

* Unlimited active automation rules
* Advanced triggers and conditions
* Additional actions and integrations
* Priority support and updates

**Why Choose Order Daemon?**

Unlike other automation plugins that try to do everything, Order Daemon focuses specifically on order completion automation. This laser focus allows us to deliver:

* **Superior Performance** - Optimized specifically for order processing workflows
* **Reliability** - Robust error handling and fallback systems ensure orders are never missed
* * **Simplicity** - Clean, intuitive interface that doesn't overwhelm you with unnecessary features
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

The free version allows you to create one active automation rule, which is perfect for getting started with order automation. The premium version removes this limitation, allowing unlimited active rules to handle various scenarios for different products or customer types. You can upgrade at any time to unlock unlimited rules and additional features.

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
2. **Insights Dashboard** - Monitor audit log automation activity and performance in real-time
3. **Rule Management** - Organize and manage multiple automation rules from a central location
4. **Settings Panel** - Configure plugin options and performance settings

== Changelog ==

= 1.1.2 =
* Included Alpine.js local copy for improved performance
* Bug fixes and stability improvements
* Improved UI for insight dashboard

= 1.1.1 =
* Enhanced security system with guard-based architecture
* Improved premium component fallback handling
* Performance optimizations for large order volumes
* Updated compatibility testing for WordPress 6.4 and WooCommerce 8.5
* Bug fixes and stability improvements

= 1.1.0 =
* Added premium component fallback system
* Enhanced rule evaluation performance
* Improved audit logging capabilities
* Added manual status tracking for chain of custody
* Security enhancements and access control improvements
* Bug fixes and code quality improvements

= 1.0.0 =
* Initial public release
* Core rule-based automation engine
* Visual rule builder interface
* Comprehensive audit logging
* Real-time insights dashboard
* Security and performance monitoring
* REST API endpoints for extensibility

== Upgrade Notice ==

= 1.1.1 =
This update includes important security enhancements and performance improvements. We recommend updating immediately.

= 1.1.0 =
Major update with enhanced premium component handling and improved security. Backup your site before updating.

== Third-Party Libraries ==

This plugin proudly uses the following open-source projects:

*   **Prism.js** for syntax highlighting. See [https://prismjs.com/](https://prismjs.com/). Licensed under the MIT License.
*   **Alpine.js** for interactive UI elements. See [https://alpinejs.dev/](https://alpinejs.dev/). Licensed under the MIT License.

== Support ==

**Documentation**
Comprehensive documentation is available at [https://orderdaemon.com/docs](https://orderdaemon.com/docs)

**Support Forums**
Get help from our community and support team in the WordPress.org support forums.

**Premium Support**
Priority support and advanced features are available with our premium plans at [https://orderdaemon.com/pricing](https://orderdaemon.com/pricing)

**Contributing**
Order Daemon is open source! Developers can contribute to the project and report issues on our GitHub repository.

== Privacy Policy ==

Order Daemon does not collect, store, or transmit any personal data outside of your WordPress installation. All automation activity is logged locally on your server for auditing purposes. The plugin respects WordPress privacy standards and GDPR compliance requirements.

== License ==

This plugin is licensed under the GPL v2 or later.

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
