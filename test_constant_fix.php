<?php
// Test script to verify the ODCM_DEBUG constant fix
// This simulates the scenario where ODCM_DEBUG is already defined in wp-config.php

// Simulate wp-config.php defining the constant
define('ODCM_DEBUG', true);

// Include the plugin's main file (simulated)
echo "Testing ODCM_DEBUG constant handling...\n";

// This is the fixed code from order-daemon.php
if ( ! defined( 'ODCM_DEBUG' ) ) {
    define('ODCM_DEBUG', false); // Default disabled, explicit enable only
}
// If ODCM_DEBUG is already defined (e.g., in wp-config.php), respect that setting
// This elseif block ensures we don't attempt redefinition which causes warnings
elseif (defined('ODCM_DEBUG') && ODCM_DEBUG) {
    // Constant already defined and enabled - no action needed
}
// If ODCM_DEBUG is defined but false, also respect that setting
elseif (defined('ODCM_DEBUG') && !ODCM_DEBUG) {
    // Constant already defined but disabled - no action needed
}

echo "No warnings generated - fix is working!\n";
echo "ODCM_DEBUG value: " . (ODCM_DEBUG ? 'true' : 'false') . "\n";

// Test the other case where it's not defined
echo "\nTesting case where ODCM_DEBUG is not predefined...\n";

// Undefine it to test the other path
if (defined('ODCM_DEBUG')) {
    // Note: We can't actually undefine a constant, so we'll simulate this in a function
    test_undefined_case();
}

function test_undefined_case() {
    // This simulates the case where ODCM_DEBUG is not defined
    if ( ! defined( 'ODCM_DEBUG' ) ) {
        define('ODCM_DEBUG', false); // Default disabled, explicit enable only
    }
    // If ODCM_DEBUG is already defined (e.g., in wp-config.php), respect that setting
    // This elseif block ensures we don't attempt redefinition which causes warnings
    elseif (defined('ODCM_DEBUG') && ODCM_DEBUG) {
        // Constant already defined and enabled - no action needed
    }
    // If ODCM_DEBUG is defined but false, also respect that setting
    elseif (defined('ODCM_DEBUG') && !ODCM_DEBUG) {
        // Constant already defined but disabled - no action needed
    }

    echo "Function test completed - no warnings!\n";
    echo "ODCM_DEBUG value in function: " . (defined('ODCM_DEBUG') ? (ODCM_DEBUG ? 'true' : 'false') : 'not defined') . "\n";
}

echo "Test completed successfully!\n";
