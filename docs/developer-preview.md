# Developer Preview: API & Webhooks

This section is for developers who want to extend Order Daemon or integrate it with other systems.

**Disclaimer:** The features described below are in an early stage of development. While they are functional, they are subject to change in future updates. Please use them with this in mind.

---

### REST API

Order Daemon provides comprehensive REST API endpoints for programmatic rule management. The API includes:

*   **Rule Management:** Get and save automation rules via `/wp-json/odcm/v1/rule/{id}`
*   **Component Discovery:** Fetch available triggers, conditions, and actions via `/wp-json/odcm/v1/rule-builder/components`
*   **Dynamic Content Search:** Search products, categories, and other WooCommerce data for use in rule conditions

**Example API use cases:**
*   Build a custom dashboard for managing rules across multiple sites
*   Sync automation rules between staging and production environments
*   Create rules programmatically based on external business logic

---

### Webhooks

Order Daemon can receive webhook data from payment gateways and external services via dedicated endpoints:

*   **Gateway-specific endpoints:** `/wp-json/odcm/v1/webhooks/{gateway}` (supports PayPal, Stripe, and generic webhooks)
*   **Health monitoring:** Built-in health check endpoint for webhook uptime monitoring
*   **Testing capabilities:** Test any webhook integration with simulated payloads

**Webhook payload data includes:**
*   Complete order details and customer information
*   Payment gateway metadata and transaction details
*   Request headers, IP address, and timing information
*   Custom fields and order metadata

This webhook system enables integrations with external analytics platforms, fulfillment services, accounting systems, and automation tools like Zapier.
