<?php

declare(strict_types=1);

/**
 * Plugin initialization file
 *
 * @wordpress-plugin
 * @formatter:off
 * Plugin Name: WP Plugin MU Loader
 * Plugin URI: https://github.com/thefrosty/wordpress-mu-loader/
 * Description: Loads regular plugins from the plugins directory as must-use plugins, enforcing their activity while maintaining the typical update flow.
 * Version: 1.2.1
 * Author: Austin Passy
 * Author URI: https://austin.passy.co
 * License: GNU General Public License v2 (or later)
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-plugin-mu-loader
 * @formatter:on
 *
 * @package WpPluginMuLoader
 * @since 1.0.0
 */

use TheFrosty\WpPluginMuLoader\WpPluginMuLoader;

defined('ABSPATH') || exit;

if (function_exists('wp_installing') && wp_installing()) {
    return;
}

/**
 * Gets the main plugin MU loader instance.
 * The instance will be created if not yet available, with its hooks registered.
 * @param array $plugins
 * @return WpPluginMuLoader Plugin MU loader instance.
 * @since 1.0.0
 */
function wp_plugin_mu_loader(array $plugins = []): WpPluginMuLoader
{
    static $loader;

    if ($loader === null) {
        if (!class_exists(WpPluginMuLoader::class, false)) {
            require __DIR__ . '/src/WpPluginMuLoader.php';
        }
        $loader = new WpPluginMuLoader($plugins);
        add_action('plugins_loaded', static function () use ($loader): void {
            $loader->addHooks();
        }, 0);
    }

    return $loader;
}
