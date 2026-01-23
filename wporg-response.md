The following issues were found in Order Daemon (the free core plugin):

Continuing with the plugin review for "orderdaemon". Let’s dive in!

Your plugin is not yet ready to be approved, you are receiving this email because the volunteers have manually checked it and have found some issues in the code / functionality of your plugin.

Please check this email thoroughly, address any issues listed, test your changes, and upload a corrected version of your code if all is well.

List of issues found


## The link to the ajax endpoint may not work in some configurations.

When you link to the Ajax endpoint, you cannot assume that it's always located at wp-admin/admin-ajax.php . There are different configurations in which that won't work.

This means you can't link it statically, you have to use a function to determine its location, for example: admin_url( 'admin-ajax.php' );

Obviously you would need to execute that call on PHP and then pass the information to your JS file, you can do that using the wp_localize_script() function which is also useful for other uses like passing the nonce. Let me share an example:

function ordedafo_scripts() {
wp_enqueue_script( 'ordedafo-script', ORDEDAFO_PLUGIN_URL . 'js/script.js', array(), ORDEDAFO_VERSION );

wp_localize_script( 'ordedafo-script', 'ordedafo-ajax', array(
  'ajax_url' => admin_url('admin-ajax.php'),
  'nonce'  => wp_create_nonce( 'ordedafo-ajax-nonce' ),
));
}
add_action( 'wp_enqueue_scripts', 'ordedafo_scripts' );


Once you have this, you can later refer to your Ajax endpoint in the JS file by using the ordedafo-ajax.ajax_url variable. Please refer to the Ajax documentation: https://developer.wordpress.org/plugins/javascript/ajax/

Example(s) from your plugin:

assets/js/admin-notices.js:58 : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');




## Use wp_enqueue commands

Your plugin is not correctly including JS and/or CSS. You should be using the built in functions for this:

When including JavaScript code you can use:

    wp_register_script() and wp_enqueue_script() to add JavaScript code from a file.
    wp_add_inline_script() to add inline JavaScript code to previous declared scripts.


When including CSS you can use:

    wp_register_style() and wp_enqueue_style() to add CSS from a file.
    wp_add_inline_style() to add inline CSS to previously declared CSS.


Note that as of WordPress 6.3, you can easily pass attributes like defer or async: https://make.wordpress.org/core/2023/07/14/registering-scripts-with-async-and-defer-attributes-in-wordpress-6-3/

Also, as of WordPress 5.7, you can pass other attributes by using this functions and filters: https://make.wordpress.org/core/2021/02/23/introducing-script-attributes-related-functions-in-wordpress-5-7/

If you're trying to enqueue on the admin pages you'll want to use the admin enqueues.

    https://developer.wordpress.org/reference/hooks/admin_enqueue_scripts/
    https://developer.wordpress.org/reference/hooks/admin_print_scripts/
    https://developer.wordpress.org/reference/hooks/admin_print_styles/


Example(s) from your plugin:

src/Admin/Notices.php:180 <script>




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

# Domain(s) not mentioned in the readme file.
src/Diagnostics/Frontend/ConfigDiagnostic.php:273 'www.google.com/recaptcha/api.js' => 'reCAPTCHA v2/v3 APIs are commonly loaded together',
src/Diagnostics/Frontend/ConfigDiagnostic.php:274 'googletagmanager.com' => 'Multiple GTM implementations are sometimes intentional',
src/Includes/functions.php:724 *         'api_endpoint' => 'https://api.example.com/sync',




## Determine files and directories locations correctly

WordPress provides several functions for easily determining where a given file or directory lives.

We detected that the way your plugin references some files, directories and/or URLs may not work with all WordPress setups. This happens because there are hardcoded references or you are using the WordPress internal constants.

Let's improve it, please check out the following documentation:

https://developer.wordpress.org/plugins/plugin-basics/determining-plugin-and-content-directories/

It contains all the functions available to determine locations correctly.

Most common cases in plugins can be solved using the following functions:

    For where your plugin is located: plugin_dir_path() , plugin_dir_url() , plugins_url()
    For the uploads directory: wp_upload_dir() (Note: If you need to write files, please do so in a folder in the uploads directory, not in your plugin directories).


Example(s) from your plugin:

src/Core/AttributionTracker.php:238 $content_dir = defined('WP_CONTENT_DIR') ? wp_normalize_path((string) constant('WP_CONTENT_DIR')) : (defined('ABSPATH') ? wp_normalize_path((string) (rtrim(ABSPATH, '/\\') . '/wp-content')) : 'wp-content');
 -----> ABSPATH




## Data Must be Sanitized, Escaped, and Validated

When you include POST/GET/REQUEST/FILE calls in your plugin, it's important to sanitize, validate, and escape them. The goal here is to prevent a user from accidentally sending trash data through the system, as well as protecting them from potential security issues.

SANITIZE: Data that is input (either by a user or automatically) must be sanitized as soon as possible. This lessens the possibility of XSS vulnerabilities and MITM attacks where posted data is subverted.

VALIDATE: All data should be validated, no matter what. Even when you sanitize, remember that you don’t want someone putting in ‘dog’ when the only valid values are numbers.

ESCAPE: Data that is output must be escaped properly when it is echo'd, so it can't hijack admin screens. There are many esc_*() functions you can use to make sure you don't show people the wrong data.

To help you with this, WordPress comes with a number of sanitization and escaping functions. You can read about those here:

    https://developer.wordpress.org/apis/security/sanitizing/
    https://developer.wordpress.org/apis/security/escaping/


Remember: You must use the most appropriate functions for the context. If you’re sanitizing email, use sanitize_email() , if you’re outputting HTML, use wp_kses_post() , and so on.

An easy mantra here is this:

Sanitize early
Escape Late
Always Validate

Clean everything, check everything, escape everything, and never trust the users to always have input sane data. After all, users come from all walks of life.

Example(s) from your plugin:

src/Admin/InsightDashboard.php:1650 $issues_raw = isset($_POST['issues']) ? wp_unslash($_POST['issues']) : '[]';
# ↳ Line 1662: 'potential_issues' => is_array($issues) ? $issues : [],
src/Admin/InsightDashboard.php:1649 $env_raw = isset($_POST['env']) ? wp_unslash($_POST['env']) : '{}';
# ↳ Line 1661: 'environment' => is_array($env) ? $env : [],




Note: When using functions like filter_var , filter_var_array , filter_input and/or filter_input_array you will need to set the FILTER parameter to any kind of filter that sanitizes the input.

Leaving the filter parameter empty, PHP by default will apply the filter "FILTER_DEFAULT" which is not sanitizing at all.

Example:

$post_id = filter_input(INPUT_GET, 'post_id', FILTER_SANITIZE_NUMBER_INT);


Example(s) from your plugin:

src/Core/ManualStatusTracker.php:411 $action_raw = filter_input(INPUT_GET, 'action', FILTER_UNSAFE_RAW);




Note: While the json_decode() function in PHP is useful for decoding JSON strings, it does not sanitize the input. Sanitization refers to the process of cleaning or filtering the input data to ensure that it is safe and secure to use.

The json_decode() function simply transforms a JSON string into a PHP array or object. Any potentially malicious data or scripts may persist after json_decode().
Example(s) from your plugin:

src/Admin/InsightDashboard.php:1653 $env = json_decode(stripslashes($env_raw), true);
src/Admin/InsightDashboard.php:1654 $issues = json_decode(stripslashes($issues_raw), true);



✔️ You can check this using Plugin Check.


## Processing the whole input

We strongly recommend you never attempt to process the whole $_POST/$_REQUEST/$_GET stack. This makes your plugin slower as you're needlessly cycling through data you don't need. Instead, you should only be attempting to process the items within that are required for your plugin to function.

Example(s) from your plugin:

src/Core/AttributionTracker.php:538 foreach ($_SERVER as $key => $value) {
if (strpos($key, 'HTTP_') === 0) {
$name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
$headers[$name] = is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
} elseif ($key === 'CONTENT_TYPE') {
$headers['content-type'] = is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
} elseif ($key === 'CONTENT_LENGTH') {
$headers['content-length'] = is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
}
}
src/Core/Core.php:269 foreach ($_GET as $key => $value) {
if (is_array($value)) {
$safe_get[$key] = array_map('sanitize_text_field', array_map('wp_unslash', $value));
} else {
$safe_get[$key] = sanitize_text_field(wp_unslash($value));
}
}
src/Core/Core.php:260 foreach ($_POST as $key => $value) {
if (is_array($value)) {
$safe_post[$key] = array_map('sanitize_text_field', array_map('wp_unslash', $value));
} else {
$safe_post[$key] = sanitize_text_field(wp_unslash($value));
}
}
src/Core/AttributionTracker.php:622 foreach ($_COOKIE as $name => $v) {
if (is_string($name) && strpos($name, 'wp_woocommerce_session_') === 0) {
return true;
}
}




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

src/View/DashboardComponents/DashboardComponentRenderer.php:96 echo $rendered_html;




Note: When escaping, there are cases where your plugin will need to output HTML. This can be done using the functions wp_kses_post or wp_kses . The function wp_kses_post will allow any common HTML that can go inside a post content, wp_kses will allow any HTML that you set up using its second and third parameters, please refer to its documentation.

A common mistake is to use esc_html to escape HTML. This function is not intended for that, it's intended to escape the output that will go inside an HTML tag, therefore it will strip any HTML tags.

Examples:

echo wp_kses_post($html_content);

echo wp_kses($html_content, array( 'a', 'div', 'span' ));


We have heuristically detected these cases of your plugin that might need HTML escaping (might be false positives, please check them out):

src/View/DashboardComponents/DashboardComponentRenderer.php:96 echo $rendered_html;



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