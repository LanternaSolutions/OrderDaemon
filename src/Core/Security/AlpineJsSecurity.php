<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Core\Security;

/**
 * Alpine.js Security Integration
 *
 * Provides security integration for Alpine.js functionality, including
 * wp_kses filters to allow Alpine.js attributes while maintaining WordPress security standards.
 *
 * @package OrderDaemon\CompletionManager\Core\Security
 * @since   2.0.3
 */
class AlpineJsSecurity
{
    /**
     * Initialize Alpine.js security features
     */
    public static function init(): void
    {
        // Add wp_kses filter to allow Alpine.js attributes in admin context
        add_filter('wp_kses_allowed_html', [self::class, 'addAlpineJsAttributes'], 10, 2);
    }

    /**
     * Add Alpine.js attributes to wp_kses allowed HTML
     *
     * This filter extends the allowed HTML to include Alpine.js directives
     * while maintaining WordPress security standards.
     *
     * @param array $tags Current allowed HTML tags
     * @param string $context Context in which wp_kses is being called
     * @return array Modified allowed HTML tags with Alpine.js attributes
     */
    public static function addAlpineJsAttributes(array $tags, string $context): array
    {
        // Only modify the 'odcm_admin' context
        if ($context !== 'odcm_admin') {
            return $tags;
        }

        // HTML tags that can contain Alpine.js attributes
        $alpinized_tags = [
            'div', 'section', 'template', 'span', 'button', 'input', 'form', 'label',
            'img', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'
        ];

        // Alpine.js attributes to allow
        $alpine_directives = [
            'x-data'  => true,
            'x-init'  => true,
            'x-show'  => true,
            'x-bind'  => true,
            'x-model' => true,
            'x-on'    => true,
            'x-text'  => true,
            'x-html'  => true,
            'x-ref'   => true,
            'x-cloak' => true,
            'x-transition' => true,
            'x-effect' => true,
            'x-ignore' => true,
            'x-modelable' => true,
            'x-teleport' => true,
            'x-if'    => true,
            'x-for'   => true,
        ];

        // Add Alpine.js attributes to each allowed tag
        foreach ($alpinized_tags as $tag) {
            if (!isset($tags[$tag])) {
                $tags[$tag] = [];
            }
            $tags[$tag] = array_merge($tags[$tag], $alpine_directives);
        }

        return $tags;
    }
}