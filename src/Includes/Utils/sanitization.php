<?php

declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Utils;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sanitization utilities for the Order Daemon plugin.
 */

/**
 * Sanitizes data for logging purposes.
 * 
 * This function recursively processes data to ensure it's safe for logging,
 * removing sensitive information and handling complex data structures.
 * 
 * @param mixed $data    The data to sanitize.
 * @param int   $depth   Current recursion depth (used internally).
 * 
 * @return mixed Sanitized data safe for logging.
 */
function odcm_sanitize_payload_for_logging($data, int $depth = 0) {
    if ($depth > 20) { return '[REDACTED - DEPTH LIMIT EXCEEDED]'; }
    
    // Initialize the denylist (only once)
    static $master_denylist = null;
    if ($master_denylist === null) {
        // Define hardcoded rules for different sanitization types
        $redact_keys_base = [
            'password', 'pass', 'pwd', 'secret', 'token', 'api_key', 'apikey', 'auth',
            'credit_card', 'card_number', 'cc_number', 'cvv', 'cvc', 'ssn', 'social_security',
            'tax_id', 'account_number', 'routing_number', 'secret_key', 'private_key'
        ];
        
        $name_keys_base = [
            'name', 'first_name', 'last_name', 'customer_name', 'user_name', 'display_name',
            'billing_first_name', 'billing_last_name', 'shipping_first_name', 'shipping_last_name'
        ];
        
        $email_keys_base = [
            'email', 'user_email', 'customer_email', 'billing_email', 'account_email',
            'contact_email', 'mail', 'e_mail', 'email_address'
        ];
        
        $address_keys_base = [
            'address', 'street', 'city', 'state', 'zip', 'postal', 'country', 'billing_address',
            'shipping_address', 'billing_city', 'shipping_city', 'billing_state', 'shipping_state',
            'billing_postcode', 'shipping_postcode', 'billing_country', 'shipping_country'
        ];
        
        // Fetch and merge custom rules
        $custom_redact_keys = get_option('odcm_custom_redact_keys', '');
        $custom_redact_array = [];
        
        if (!empty($custom_redact_keys)) {
            $custom_redact_array = array_map('trim', explode("\n", $custom_redact_keys));
            $custom_redact_array = array_filter($custom_redact_array);
        }
        
        // Merge custom keys with base keys
        $redact_keys = array_merge($redact_keys_base, $custom_redact_array);
        
        // Prepare the master denylist with lookup tables
        $master_denylist = [
            'redact' => array_flip($redact_keys),
            'name' => array_flip($name_keys_base),
            'email' => array_flip($email_keys_base),
            'address' => array_flip($address_keys_base)
        ];
    }
    
    // Implement recursive sanitization
    if (is_array($data) || is_object($data)) {
        $is_object = is_object($data);
        $array_data = $is_object ? (array) $data : $data;
        
        foreach ($array_data as $key => &$value) {
            // Recursively process arrays and objects
            if (is_array($value) || is_object($value)) {
                $value = odcm_sanitize_payload_for_logging($value, $depth + 1);
                continue;
            }
            
            // Skip non-string values
            if (!is_string($value)) {
                continue;
            }
            
            // Apply appropriate sanitization based on key
            if (isset($master_denylist['redact'][$key])) {
                // Fully redact sensitive data
                $value = '[REDACTED]';
            } elseif (isset($master_denylist['name'][$key])) {
                // Pseudonymize names
                $value = 'User_' . substr(md5($value), 0, 8);
            } elseif (isset($master_denylist['email'][$key])) {
                // Mask email addresses
                if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    list($username, $domain) = explode('@', $value);
                    $masked_username = substr($username, 0, 2) . str_repeat('*', strlen($username) - 2);
                    $value = $masked_username . '@' . $domain;
                }
            } elseif (isset($master_denylist['address'][$key])) {
                // Redact address information
                $value = '[ADDRESS REDACTED]';
            }
        }
        
        // Convert back to object if original was an object
        return $is_object ? (object) $array_data : $array_data;
    }
    
    return $data;
}
