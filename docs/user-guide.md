# User Guide

This guide will help you get started with Order Daemon and show you how to build your first automation rules.

## Setup & Dashboard Tour

1.  **Installation:** Install Order Daemon from the WordPress plugin directory and activate it.
2.  **Dashboard:** Navigate to **Order Daemon** in your WordPress admin panel. This is your central hub for creating rules, monitoring activity, and managing settings.

## Practical Recipes

Here are a couple of popular automation recipes to get you started quickly.

---

### Recipe 1: Automatically Complete Virtual Orders

This is perfect for stores that sell digital products, services, or anything that doesn't require shipping.

*   **Trigger:** `Order Processing`
*   **Condition:** `Product Type` -> `is` -> `Virtual`
*   **Action:** `Complete Order`

With this rule, any order containing only virtual products will be instantly marked as "Completed," giving your customers immediate access to their purchases.

---

### Recipe 2: Auto-Complete Orders for a Specific Product Category

This is useful if you have a category of products (e.g., "eBooks" or "Digital Downloads") that should always be completed instantly, regardless of other items in the cart.

*   **Trigger:** `Order Processing`
*   **Condition:** `Product Category` -> `is` -> `eBooks`
*   **Action:** `Complete Order`

This rule ensures that any order containing a product from your "eBooks" category is immediately marked as "Completed".

---

## Available Components in Order Daemon Core

To help you decide if Order Daemon is right for you, here is a complete list of the automation components included in the free version.

### Triggers
The event that initiates a rule check.

*   **Order Processing:** Runs when an order is ready for fulfillment, typically after payment has been confirmed.

### Conditions
The "if" statements that an order must match.

*   **Order Total:** Checks if the total amount of the order is `greater than` or `less than` a specific value.
*   **Product Category:** Checks if any product in the order belongs to a specific category.
*   **Product Type:** Checks if any product in the order is of a certain type (e.g., `Simple`, `Variable`, `Virtual`).

### Actions
The task that is performed when all conditions are met.

*   **Complete Order:** Changes the order status to "Completed."
