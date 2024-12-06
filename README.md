# WP Plugin MU Loader

## Installation

```bash
composer require thefosty/wordpress-mu-loader:^1.1
```

Loads regular plugins from the plugins' directory as "must-use plugins", enforcing their activity 
while maintaining the typical update flow. This file will take care of all necessary logic, 
including preventing activation/deactivation/deletion of those plugins as regular plugins.

#### Benefits

* Enforce plugins to be active throughout the entire installation.
* Continue receiving automated update notifications.
* Be able to comfortably update those plugins from the WordPress dashboard.
* The plugin activation, deactivation, and uninstallation routines are executed as usual.

#### Requirements
* WordPress >= 6.0
* PHP >= 8.0

#### Usage

* You can then pass basenames of the plugins you would like to load as MU plugins to the constructor call in the
`wp_plugin_mu_loader()` function, as an array.
* A plugin basename consists of the plugin directory name, a trailing slash, and the plugin main file name, for
example wordpress-seo/wp-seo.php, jetpack/jetpack.php, or woocommerce/woocommerce.php.
* Alternatively, if you don't want to tweak the code of the function itself, you can also access the loader from the
outside: Retrieve the instance via wp_plugin_mu_loader() and then call its load_plugin() method, passing a single plugin
basename string to it.

#### Example

```php
wp_plugin_mu_loader()->loadPlugin( 'custom-login/custom-login.php' );
```

OR, create a git managed mu-plugin:

```php
<?php declare(strict_types=1);
/**
 * @wordpress-muplugin
 * Plugin Name: WordPress Plugins as Must-use
 * Description: Require regular WordPress plugins as "must-use" plugins.
 * Version: 1.0.0
 * Author: Austin Passy
 * Author URI: https://github.com/thefrosty
 */

namespace TheFrosty;

/**
 * Returns an array of basename formatted plugins to set as "must-use".
 * @return array
 */
function getRequiredPlugins(): array
{
    // Add plugins to the array here...
    return \array_filter([
        'disable-emojis/disable-emojis.php',
        'soil/soil.php',
        'custom-login/custom-login.php',
        'wp-login-locker/wp-login-locker.php',
    ]);
}

\add_action('muplugins_loaded', function () {
    $plugins = getRequiredPlugins();
    \array_walk($plugins, function (string $plugin_basename) {
        try {
            if (!\function_exists('wp_plugin_mu_loader') &&
                // You only need the file_exists/require is not using autoloading...
                \file_exists(WPMU_PLUGIN_DIR . '/wordpress-mu-loader/wp-plugin-mu-loader.php')
            ) {
                require_once WPMU_PLUGIN_DIR . '/wordpress-mu-loader/wp-plugin-mu-loader.php';
            }
            \wp_plugin_mu_loader()->loadPlugin($plugin_basename);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            // Log something here?
        }
    });
});
```

