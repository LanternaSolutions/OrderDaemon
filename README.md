# Order Daemon for WooCommerce

**Order automation that shows its work.** Build WHEN/IF/THEN rules, watch every order event in a live log stream, and catch checkout failures before your customers do.

![Order Daemon — order automation that shows its work](assets/banner-1544x500.png)

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/order-daemon?label=version&color=0F5FA0)](https://wordpress.org/plugins/order-daemon/)
[![Active Installs](https://img.shields.io/wordpress/plugin/installs/order-daemon?color=2E6F3F)](https://wordpress.org/plugins/order-daemon/)
[![WP tested up to](https://img.shields.io/wordpress/plugin/tested/order-daemon)](https://wordpress.org/plugins/order-daemon/)
[![License: GPL v2](https://img.shields.io/badge/license-GPLv2-0F5FA0)](https://www.gnu.org/licenses/gpl-2.0)

---

## What it does

```
WHEN  order → processing
IF    product type is virtual
THEN  complete the order
```

Rules run in the background. Every execution — along with every status change, checkout failure, and payment event — is written to the **Insight Dashboard**: a searchable, filterable log stream. Click any row to open the full event timeline for that order, with time deltas and raw event data.

## Free version

| | Free |
|---|---|
| Automation rules | Unlimited |
| Rule builder | Visual WHEN / IF / THEN |
| Triggers | Order processing |
| Conditions | Product type · product category · order total |
| Actions | Complete order |
| Insight Dashboard | Full — live log stream + per-order event timeline |
| Log retention | 30 days |
| HPOS compatible | Yes |

**Pro** adds more triggers, conditions, and actions; rule testing against real past orders without modifying state; webhook connections; WP-CLI; and extended log retention. [See plans →](https://orderdaemon.com/pricing/)

## Requirements

- WordPress 5.6+
- WooCommerce 5.0+
- PHP 7.4+

## Installation

```bash
# Via WP-CLI
wp plugin install order-daemon --activate
```

Or: **Plugins → Add New** → search "Order Daemon" → Install → Activate.

## Development

```bash
composer install
```

Hook and filter reference: [orderdaemon.com/docs](https://orderdaemon.com/docs/)

Contributing: [CONTRIBUTING.md](CONTRIBUTING.md)

## License

[GPL-2.0-or-later](LICENSE)