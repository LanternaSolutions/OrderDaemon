The following issues were found in Order Daemon (the free core plugin):

Have you read the guidelines and this plugin complies with them?

Upon submitting your plugin, you agreed and confirmed that it complies with the WordPress.org Plugin Directory Guidelines, which apply to all plugins in the directory.

Our automated tools have detected patterns that may require a closer look regarding compliance with certain guidelines. We will verify this during our manual review, but it’s best to address any potential issues beforehand. In particular, please pay attention to the following:

    Any included code must be GPL-compatible. This means, among others, that the users have to receive the four essential freedoms: (0) to run the program, (1) to study and change the program in source code form, (2) to redistribute exact copies, and (3) to distribute modified versions. (Guidelines 1 & 4)
    Plugins must not restrict or lock functionality. (Guidelines 1, 5, & 9 — see clarification on SaaS in Guideline 6)
    Plugins are permitted to require the use of third party/external services. The service itself must provide functionality of substance and be clearly documented (what the service is and what is used for + what data is sent and when + links to privacy and service terms) in the readme file submitted with the plugin. (Guideline 6)
    Plugins must not track users without explicit consent. (Guidelines 7 & 9)
    Plugins should not hijack the admin dashboard. Upgrade prompts, notices, alerts, and the like must be limited in scope and used with moderation. (Guideline 11)


Please check it, and if you think everything is fine, do not worry. Our tools are very thorough and may highlight different things as potential issues.

Have you checked for common technical issues?

Please ensure that your plugin adheres to best practices, including the following:

🔴 Use wp_enqueue commands

ℹ️ Why it matters: Because of performance and compatibility, please make use of the built in functions for including static and dynamic JS and/or CSS.

🔍 Identify JS and CSS outputs: Look for any <script> or <style> HTML tags in your plugin. In the majority of cases you could enqueue them.

🛠 Fix it: Make use of the specific function for enqueue them:
Type of code
Functions
Static JS
wp_register_script() , wp_enqueue_script() , admin_enqueue_scripts()
Inline JS
wp_add_inline_script()
Static CSS
wp_register_style() , wp_enqueue_style()
Inline CSS
wp_add_inline_style()

👉 In the public pages you can enqueue them using the hook wp_enqueue_scripts() .
👉 In the admin pages you can enqueue them using the hook admin_enqueue_scripts() . You can also use admin_print_scripts() and admin_print_styles() .
👉 As of WordPress 6.3, you can easily pass attributes like defer or async, as of WordPress 5.7, you can pass other attributes by using functions and filters.

Example:

function ordedafo_enqueue_script() {
wp_enqueue_script( 'ordedafo_js', plugins_url( 'inc/main.js', __FILE__ ), array(), ORDEDAFO_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'ordedafo_enqueue_script' );

Your JS/CSS is now enqueued!

Possible cases from your plugin include:

src/Admin/Admin.php:123 <script>
src/Admin/RuleBuilder.php:1741 <style>
src/Admin/Admin.php:178 echo '<script>jQuery(document).ready(function($) {



🔴 Trialware and Locked Features
Please review your plugin to ensure that it does not include any locked or restricted built-in functionality. This is not permitted under the WordPress.org Plugin Directory Guidelines you agreed to when submitting the plugin.

❌ Guideline 5 – Trialware
Plugins must be fully functional. You may not:

    Lock, disable or limit built-in features behind a license key, trial period, usage limit, time, quota or any other kind of intended restriction.


Even if the locked feature is present in the code "just in case the user upgrades," it’s still not allowed. Your plugin may point out which features are available through a separated plugin, but that's it. All plugin code hosted on WordPress.org must be free and fully functional.

🌐 Guideline 6 – Serviceware
Plugins may connect to a legitimate external service to perform certain functionality, provided:

    The service performs actual processing on external servers.
    The functionality provided cannot be done locally by the plugin.
    The service is clearly documented in your readme, including Terms of Use and Privacy Policy links.


For example: a "Spam checker" plugin that connects to a external service to check for spam (and thus uses it to provide that functionality) is generally acceptable. A plugin that simply checks a license key to unlock local features is not.

✅ Ask yourself:

    Does any function only work after a license check or payment?
    Is any functionality in the plugin code disabled or limited until it’s unlocked?
    Are there any limitations on the plugin after a certain amount of time or usage?


After excluding functionalities provided by legitimate external services, if the answer is yes to any of the above, the plugin does not comply.

🔧 How to fix it:

    Remove all license checks or other mechanisms that control access to features built in in the plugin code.
    Remove or fully enable any built in features that are currently locked or limited.
    Make sure external services are compliant and clearly documented.


ℹ️ Important clarification:
WordPress.org is not a marketplace. It's a repository for free, fully functional, GPL-compliant plugins.

If you are not offering a service and want to offer additional features through a paid version, that code must be:

    Hosted elsewhere (e.g., your own website).
    Not included in the plugin hosted on WordPress.org.
    GPL compliant: Do not include any mechanisms that would prevent a plug-in from being used after a license has been checked.




Other details

We've detected some other details that you may want to check.

## Internationalization: Don't use variables or defines as text, context or text domain parameters.

In order to make a string translatable in your plugin you are using a set of special functions. These functions collectively are known as "gettext".

There is a dedicated team in the WordPress community to translate and help other translating strings of WordPress core, plugins and themes to other languages.

To make them be able to translate this plugin, please do not use variables or function calls for the text, context or text domain parameters of any gettext function, all of them NEED to be strings. Note that the translation parser reads the code without executing it, so it won't be able to read anything that is not a string within these functions.

For example, if your gettext function looks like this...
esc_html__( $greetings , 'order-daemon' );
...the translator won't be able to see anything to be translated as $greetings is not a string, it is not something that can be translated.
You need to give them the string to be translated, so they can see it in the translation system and can translate it, the correct would be as follows...
esc_html__( 'Hello, how are you?' , 'order-daemon' );

This also applies to the translation domain, this is a bad call:
esc_html__( 'Hello, how are you?' , $plugin_slug );
The fix here would be like this
esc_html__( 'Hello, how are you?' , 'order-daemon' );
Also note that the translation domain needs to be the same as your plugin slug.

What if we want to include a dynamic value inside the translation? Easy, you need to add a placeholder which will be part of the string and change it after the gettext function does its magic, you can use printf to do so, like this:

printf(

      /* translators: %s: First name of the user */
      esc_html__( 'Hello %s, how are you?', 'order-daemon' ),
      esc_html( $user_firstname )
);


You can read https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/#text-domains for more information.

Example(s) from your plugin:

src/Includes/class-odcm-strings.php:141 __($text, 'order-daemon');
src/Includes/class-odcm-strings.php:153 esc_html__($text, 'order-daemon');
src/Includes/class-odcm-strings.php:191 __($text, 'order-daemon');
src/Includes/class-odcm-strings.php:179 _n($singular, $plural, $number, 'order-daemon');
src/Includes/class-odcm-strings.php:165 esc_attr__($text, 'order-daemon');




## Undocumented use of a 3rd Party / external service

Plugins are permitted to require the use of third party/external services as long as they are clearly documented.

When your plugin reach out to external services, you must disclose it. This is true even if you are the one providing that service.

You are required to document it in a clear and plain language, so users are aware of: what data is sent, why, where and under which conditions.

To do this, you must update your readme file to clearly explain that your plugin relies on third party/external services, and include at least the following information for each third party/external service that this plugin uses:

    What the service is and what it is used for.
    What data is sent and when.
    Provide links to the service's terms of service and privacy policy.

Remember, this is for your own legal protection. Use of services must be upfront and well documented. This allows users to ensure that any legal issues with data transmissions are covered.

Example:

== External services ==

This plugin connects to an API to obtain weather information, it's needed to show the weather information and forecasts in the included widget.

It sends the user's location every time the widget is loaded (If the location isn't available and/or the user hasn't given their consent, it displays a configurable default location).
This service is provided by "PRT Weather INC": terms of use, privacy policy.



Example(s) from your plugin:

src/Diagnostics/Frontend/ConfigDiagnostic.php:274 'googletagmanager.com' => 'Multiple GTM implementations are sometimes intentional',
src/View/PayloadAnalyzer.php:190 *     'api_request' => ['url' => 'https://api.example.com'],
src/Core/Events/Adapters/PayPalAdapter.php:33 private const WEBHOOK_VERIFY_URL = 'https://api.paypal.com/v1/notifications/verify-webhook-signature';
src/Includes/functions.php:1071 *         'api_endpoint' => 'https://api.example.com/sync',




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

src/API/AuditLogEndpoint.php:309 register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/raw-data/(?P<log_id>\\d+)', [['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'get_raw_timeline_data'], 'permission_callback' => '__return_true']]);
src/API/AuditLogEndpoint.php:300 register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/diagnostic', [['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'diagnostic_check'], 'permission_callback' => '__return_true']]);
src/API/WebhookController.php:76 register_rest_route(self::NAMESPACE, '/' . self::BASE_ROUTE . '/health', [['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'health_check'], 'permission_callback' => '__return_true']]);



