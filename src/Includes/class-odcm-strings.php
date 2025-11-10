<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Includes;

/**
 * Centralized translation strings for Order Daemon
 * 
 * This class contains commonly reused strings to maintain consistency
 * and reduce duplication while keeping full WordPress translation compatibility.
 * 
 * WordPress translation tools will automatically scan these constant values
 * for inclusion in .po/.pot files, ensuring perfect translation workflow compatibility.
 * 
 * @package OrderDaemon\CompletionManager\Includes
 * @since   1.0.0
 */
final class Odcm_Strings
{
    /**
     * Common UI strings used across multiple components
     */
    public const LOADING = 'Loading...';
    public const ERROR_LOADING_DATA = 'Error loading data';
    public const SAVE = 'Save';
    public const CANCEL = 'Cancel';
    public const CLOSE = 'Close';
    public const REFRESH = 'Refresh';
    public const RETRY = 'Retry';
    public const DELETE = 'Delete';
    public const EDIT = 'Edit';
    public const VIEW = 'View';
    public const SEARCH = 'Search';
    public const FILTERS = 'Filters';
    public const SETTINGS = 'Settings';
    public const DETAILS = 'Details';
    public const APPLY = 'Apply';
    public const CLEAR_ALL = 'Clear All';
    public const AUTO_REFRESH = 'Auto-refresh';
    public const EVERY = 'every';
    public const OF = 'of';
    
    /**
     * Status and state strings
     */
    public const STATUS_SUCCESS = 'Success';
    public const STATUS_ERROR = 'Error';
    public const STATUS_WARNING = 'Warning';
    public const STATUS_INFO = 'Info';
    public const STATUS_PENDING = 'Pending';
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_INACTIVE = 'Inactive';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_FAILED = 'Failed';
    public const ALL_STATUSES = 'All Statuses';
    public const ALL_EVENT_TYPES = 'All Event Types';
    public const ALL_SOURCES = 'All Sources';
    
    /**
     * Order-related strings
     */
    public const ORDER_DAEMON = 'Order Daemon';
    public const ORDER_PROCESSING = 'Order Processing';
    public const ORDER_COMPLETION = 'Order Completion';
    public const REPROCESS_PENDING_ORDERS = 'Reprocess Pending Orders';
    public const NO_ORDERS_FOUND = 'No orders found';
    public const PROCESSING_ORDERS = 'Processing...';
    public const ORDER_COLON = 'Order:';
    
    /**
     * Log and audit strings
     */
    public const NO_LOGS_FOUND = 'No log entries found';
    public const SELECT_LOG_ENTRY = 'Select a log entry to view details';
    public const LOG_STREAM = 'Log Stream';
    public const EVENTS_TIMELINE = 'Events Timeline';
    public const CONSOLIDATED_ENTRY = 'Consolidated Entry';
    public const SELECT_ALL = 'Select All';
    public const DELETE_SELECTED = 'Delete Selected';
    public const DELETING = 'Deleting...';
    public const NEW_LOGS_AVAILABLE = 'New log entries available';
    public const INCLUDE_DEBUG_LOGS = 'Include Debug Logs';
    public const INCLUDE_TEST_LOGS = 'Include Test Logs';
    
    /**
     * Time and date strings
     */
    public const TIME_ONLY = 'Time Only';
    public const DATE_AND_TIME = 'Date & Time';
    public const RELATIVE_TIME = 'Relative Time';
    
    /**
     * Permission and security strings
     */
    public const PERMISSION_DENIED = 'You do not have sufficient permissions to access this page.';
    public const SECURITY_CHECK_FAILED = 'Security check failed.';
    public const NONCE_VERIFICATION_FAILED = 'Security verification failed.';
    
    /**
     * Premium feature strings
     */
    public const PREMIUM_FEATURE = 'Premium Feature';
    public const UPGRADE_TO_PREMIUM = 'Upgrade to Premium';
    public const PREMIUM_ONLY = 'This feature is only available for premium users.';
    
    /**
     * Form and validation strings
     */
    public const REQUIRED_FIELD = 'This field is required.';
    public const INVALID_INPUT = 'Invalid input provided.';
    public const CHANGES_SAVED = 'Changes saved successfully.';
    public const FAILED_TO_SAVE = 'Failed to save changes.';
    public const ENTRIES_PER_PAGE = 'Entries Per Page';
    public const APPLY_FILTERS = 'Apply Filters';
    
    /**
     * Navigation and pagination strings
     */
    public const PREVIOUS = 'Previous';
    public const NEXT = 'Next';
    public const FIRST = 'First';
    public const LAST = 'Last';
    
    /**
     * Translation helper methods - unified approach for all string translations
     * 
     * These methods work seamlessly with both constants and custom strings:
     * - Odcm_Strings::__('Custom text')
     * - Odcm_Strings::__(Odcm_Strings::LOADING)
     */

    /**
     * Translate a string (works with constants or custom text)
     * 
     * @param string $text The text to translate (can be a constant value or custom string)
     * @return string Translated text
     */
    public static function __( string $text ): string
    {
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- This is a simple wrapper function that ensures consistent usage of the correct text domain
        return __( $text, 'order-daemon' );
    }

    /**
     * Translate and escape for HTML output
     * 
     * @param string $text The text to translate and escape
     * @return string Translated and HTML-escaped text
     */
    public static function esc_html__( string $text ): string
    {
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- This is a simple wrapper function that ensures consistent usage of the correct text domain
        return esc_html__( $text, 'order-daemon' );
    }

    /**
     * Translate and escape for HTML attribute output
     * 
     * @param string $text The text to translate and escape
     * @return string Translated and attribute-escaped text
     */
    public static function esc_attr__( string $text ): string
    {
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- This is a simple wrapper function that ensures consistent usage of the correct text domain
        return esc_attr__( $text, 'order-daemon' );
    }

    /**
     * Translate plural forms
     * 
     * @param string $singular Singular form of the text
     * @param string $plural Plural form of the text
     * @param int $number The number to determine singular vs plural
     * @return string Translated text in appropriate form
     */
    public static function _n( string $singular, string $plural, int $number ): string
    {
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralSingle, WordPress.WP.I18n.NonSingularStringLiteralPlural -- This is a simple wrapper function that ensures consistent usage of the correct text domain
        return _n( $singular, $plural, $number, 'order-daemon' );
    }

    /**
     * JavaScript-safe translation (for wp_localize_script)
     * 
     * @param string $text The text to translate and escape for JavaScript
     * @return string Translated and JavaScript-escaped text
     */
    public static function esc_js__( string $text ): string
    {
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- This is a simple wrapper function that ensures consistent usage of the correct text domain
        return esc_js( __( $text, 'order-daemon' ) );
    }
}
