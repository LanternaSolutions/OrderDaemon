WordPress.org Plugin Directory
	
Jan 15, 2026, 11:06 PM (15 hours ago)
	
	
to me
It's time to move forward with the plugin review "orderdaemon"!

Your plugin is not yet ready to be approved, you are receiving this email because the volunteers have manually checked it and have found some issues in the code / functionality of your plugin.

Please check this email thoroughly, address any issues listed, test your changes, and upload a corrected version of your code if all is well.

List of issues found



## Review: Missing permission_callback in REST API Route

When using register_rest_route() or wp_register_ability() to define custom REST API endpoints, it is crucial to include a proper permission_callback .

🔒 This callback function ensures that only authorized users can access or modify data through your endpoint.

Code example, checking that the user can change options:

register_rest_route( 'order-daemon/v1', '/my-endpoint', array(
    'methods' => 'GET',
    'callback' => 'order-daemon_callback_function',
    'permission_callback' => function() {
        return current_user_can( 'manage_options' );
    }
) );


Please check the register_rest_route() documentation and the current_user_can() documentation.

✅ When a permission_callback is NOT Required:

There are valid use cases for public endpoints, such as publicly available data (e.g., posts, public metadata) or endpoints designed for unauthenticated access (e.g., fetching public stats or information).

In these cases, you should use __return_true as the permission_callback to indicate that the endpoint is intentionally public.

🔒 When a permission_callback IS Required:

For endpoints that involve sensitive data or actions (e.g., getting not public data, creating, updating, or deleting content).

In these cases, you should always implement proper permission checks.

Possible cases found on this plugin's code:

src/API/AuditLogEndpoint.php:301 register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/diagnostic', [['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'diagnostic_check'], 'permission_callback' => '__return_true']]);
# ✨ Endpoint is publicly accessible in debug mode via __return_true but returns internal diagnostics (DB/table presence, log stats, sample logs/environment details), so it should require an authenticated capability check.  
src/API/AuditLogEndpoint.php:310 register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/raw-data/(?P<log_id>\\d+)', [['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'get_raw_timeline_data'], 'permission_callback' => '__return_true']]);
# ✨ Endpoint is publicly accessible in debug mode via __return_true and returns raw timeline/component data for a given log_id, which is sensitive and should require proper permissions (not unauthenticated access).  



## Variables and options must be escaped when echo'd

Much related to sanitizing everything, all variables that are echoed need to be escaped when they're echoed, so it can't hijack users or (worse) admin screens. There are many esc_*() functions you can use to make sure you don't show people the wrong data, as well as some that will allow you to echo HTML safely.

At this time, we ask you escape all $-variables, options, and any sort of generated data when it is being echoed. That means you should not be escaping when you build a variable, but when you output it at the end. We call this 'escaping late.'

Besides protecting yourself from a possible XSS vulnerability, escaping late makes sure that you're keeping the future you safe. While today your code may be only outputted hardcoded content, that may not be true in the future. By taking the time to properly escape when you echo, you prevent a mistake in the future from becoming a critical security issue.

This remains true of options you've saved to the database. Even if you've properly sanitized when you saved, the tools for sanitizing and escaping aren't interchangeable. Sanitizing makes sure it's safe for processing and storing in the database. Escaping makes it safe to output.

Also keep in mind that sometimes a function is echoing when it should really be returning content instead. This is a common mistake when it comes to returning JSON encoded content. Very rarely is that actually something you should be echoing at all. Echoing is because it needs to be on the screen, read by a human. Returning (which is what you would do with an API) can be json encoded, though remember to sanitize when you save to that json object!

There are a number of options to secure all types of content (html, email, etc). Yes, even HTML needs to be properly escaped.

https://developer.wordpress.org/apis/security/escaping/

Remember: You must use the most appropriate functions for the context. There is pretty much an option for everything you could echo. Even echoing HTML safely.

Example(s) from your plugin:

src/View/DashboardComponents/DashboardComponentRenderer.php:78 _e($rendered_html, 'order-daemon');;




Note: The functions _e and _ex outputs the translation without escaping, please use an alternative function that escapes the output.

    An alternative to _e would be esc_html_e , esc_attr_e or simply using __ wrapped by a escaping function and inside an echo .
    An alternative to _ex would be using _x wrapped by a escaping function and inside an echo .

Examples:

<h2><?php esc_html_e('Settings page', 'order-daemon'); ?></h2>

<h2><?php echo esc_html(__('Settings page', 'order-daemon')); ?></h2>

<h2><?php echo esc_html(_x('Settings page', 'Settings page title', 'order-daemon')); ?></h2>


Example(s) from your plugin:

src/View/DashboardComponents/DashboardComponentRenderer.php:78 _e($rendered_html, 'order-daemon');;



✔️ You can check this using Plugin Check.


👉 Continue with the review process.

Read this email thoroughly.

Please, take the time to fully understand the issues we've raised. Review the examples provided, read the relevant documentation, and research as needed. Our goal is for you to gain a clear understanding of the problems so you can address them effectively and avoid similar issues when maintaining your plugin in the future.
Note that there may be false positives - we are humans and make mistakes, we apologize if there is anything we have gotten wrong. If you have doubts you can ask us for clarification, when asking us please be clear, concise, direct and include an example.

📋 Complete your checklist.

✔️ I fixed all the issues in my plugin based on the feedback I received and my own review, as I know that the Plugins Team may not share all cases of the same issue. I am familiar with tools such as Plugin Check, PHPCS + WPCS, and similar utilities to help me identify problems in my code.
✔️ I tested my updated plugin on a clean WordPress installation with WP_DEBUG set to true.

    ⚠️ Do not skip this step. Testing is essential to make sure your fixes actually work and that you haven’t introduced new issues.


✔️ I acknowledge that this review will be rejected if I overlook the issues or fail to test my code.
✔️ I went to "Add your plugin" and uploaded the updated version. I can continue updating the code there throughout the review process — the team will always check the latest version.
✔️ I replied to this email. I was concise and shared any clarifications or important context that the team needed to know.
I didn't list all the changes, as the team will review the entire plugin again and that is not necessary at all.

ℹ️ To make this process as quick as possible and to avoid burden on the volunteers devoting their time to review this plugin's code, we ask you to thoroughly check all shared issues and fix them before sending the code back to us. I know we already asked you to do so, and it is because we are really trying to make it very clear.

While we try to make our reviews as exhaustive as possible we, like you, are humans and may have missed things. We appreciate your patience and understanding.

Review ID: R order-daemon/orderdaemon/10Jan26/T2 15Jan26/3.8RC2


--
WordPress Plugins Team | plugins@wordpress.org
https://make.wordpress.org/plugins/
https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
https://wordpress.org/plugins/plugin-check/ 