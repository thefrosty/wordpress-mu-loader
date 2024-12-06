<?php declare(strict_types=1);

/**
 * Plugin initialization file
 *
 * @wordpress-plugin
 * @formatter:off
 * Plugin Name: WP Plugin MU Loader
 * Plugin URI: https://gist.github.com/felixarntz/daff4006112b60dfea677ca08fc0b31c
 * Description: Loads regular plugins from the plugins directory as must-use plugins, enforcing their activity while maintaining the typical update flow.
 * Version: 1.0.0
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

defined('ABSPATH') || exit;

if (function_exists('wp_installing') && wp_installing()) {
    return;
}

/**
 * Class WpPluginMuLoader
 * Responsible for loading regular plugins as must-use (MU) plugins.
 * @since 1.0.0
 */
class WpPluginMuLoader
{

    /**
     * Name of the option to use as internal cache.
     * @since 1.0.0
     */
    public const CACHE_OPTION_NAME = 'wp_plugin_mu_loader';

    /**
     * Prefix to use for the internal AJAX actions.
     * @since 1.0.0
     */
    protected const AJAX_ACTION_PREFIX = 'wp_plugin_mu_loader_';

    /**
     * Base names of the plugins to load as MU plugins.
     * @since 1.0.0
     * @var string[] $plugins
     */
    protected array $plugins = [];

    /**
     * Base names of the plugins to load as MU plugins, as previously stored.
     * @since 1.0.0
     * @var string[]|null $cache
     */
    protected ?array $cache = null;

    /**
     * Constructor.
     * @param array $plugins Optional. Base names of all plugins to load. Alternatively, the
     * `loadPlugin()` method can be used to load a single plugin. Default empty array.
     * @since 1.0.0
     */
    public function __construct(array $plugins = [])
    {
        $this->getCache();
        array_walk($plugins, [$this, 'loadPlugin']);
    }

    /**
     * Loads a given plugin.
     * @param string $plugin Plugin basename, consisting of the plugin directory name, a trailing slash,
     * and the plugin main file name.
     * @throws InvalidArgumentException Thrown when the plugin basename is invalid.
     * @throws RuntimeException Thrown when the plugin with the given basename is not installed.
     * @since 1.0.0
     */
    public function loadPlugin(string $plugin): void
    {
        if (validate_file($plugin) !== 0 || substr($plugin, -4) !== '.php') {
            /* translators: %s: plugin basename */
            throw new InvalidArgumentException(
                sprintf(esc_html__('%s is not a valid plugin basename.', 'wp-plugin-mu-loader'), $plugin)
            );
        }

        $file = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $plugin;

        if (!file_exists($file)) {
            /* translators: %s: plugin basename */
            throw new RuntimeException(
                sprintf(esc_html__('The plugin with the basename %s is not installed.', 'wp-plugin-mu-loader'), $plugin)
            );
        }

        $this->plugins[] = $plugin;
        $this->requirePluginFile($file);

        if ($this->needsActivation($plugin)) {
            $this->triggerActivationHook($plugin);
        }
    }

    /**
     * Registers the hooks necessary to load plugins as MU plugins.
     * @since 1.0.0
     */
    public function addHooks(): void
    {
        add_filter('option_active_plugins', [$this, 'filterActivePlugins'], 0);
        add_filter('site_option_active_sitewide_plugins', [$this, 'filterNetworkActivePlugins'], 0);
        add_filter('map_meta_cap', [$this, 'filterPluginMetaCaps'], 0, 4);

        add_filter('plugin_action_links', [$this, 'filterPluginActionLinks'], 0, 2);
        add_filter('network_admin_plugin_action_links', [$this, 'filterPluginActionLinks'], 0, 2);
        add_action('admin_footer-plugins.php', [$this, 'markPluginsActive'], 0);

        add_action('shutdown', [$this, 'setCache'], 0);
        add_action('shutdown', [$this, 'deactivateOldPlugins'], 0);
        add_action('wp_ajax_' . self::AJAX_ACTION_PREFIX . 'deactivate', [$this, 'ajaxCallback']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION_PREFIX . 'deactivate', [$this, 'ajaxCallback']);
    }

    /**
     * Filters the 'active_plugins' option, INCLUDING plugins loaded as MU plugins.
     * @param array $plugins List of active plugin base names.
     * @since 2.0.0
     */
    public function includeActivePlugins(array $plugins): void
    {
        $this->plugins = array_merge($this->plugins, $plugins);
        add_filter('option_active_plugins', static function (array $active_plugins) use ($plugins): array {
            return \array_merge($active_plugins, $plugins);
        }, 5);
    }

    /**
     * Filters the 'active_plugins' option, EXCLUDING plugins loaded as MU plugins.
     * @param array $plugins List of active plugin base names.
     * @return array Filtered value of $plugins.
     * @since 1.0.0
     */
    public function filterActivePlugins(array $plugins): array
    {
        if (defined('WP_CLI') && WP_CLI && method_exists('WP_CLI', 'get_runner')) {
            // vendor/wp-cli/wp-cli/php/WP_CLI/Runner.php:1608
            if (WP_CLI::get_runner()->config['skip-plugins'] === true) {
                return $plugins;
            }
        }
        $function = defined('WP_CLI') && WP_CLI ? 'array_merge' : 'array_diff';

        return $function($plugins, $this->plugins);
    }

    /**
     * Filters the 'active_sitewide_plugins' network option, excluding plugins loaded as MU plugins.
     * @param array|null $plugins Associative array of $plugin_basename => $timestamp pairs.
     * @return array Filtered value of $plugins.
     * @since 1.0.0
     */
    public function filterNetworkActivePlugins(?array $plugins): array
    {
        return array_diff_key((array)$plugins, array_flip($this->plugins));
    }

    /**
     * Filters the capabilities for activating and deactivating plugins.
     * This method prevents access to those capabilities for plugins loaded as MU plugins.
     * @param array $caps List of primitive capabilities resolved to in `map_meta_cap()`.
     * @param string|null $cap Meta capability actually being checked.
     * @param int|null $user_id User ID for which the capability is being checked.
     * @param array $args Additional arguments passed to the capability check.
     * @return array Filtered value of $caps.
     * @since 1.0.0
     */
    public function filterPluginMetaCaps(array $caps, ?string $cap, ?int $user_id, array $args): array
    {
        switch ($cap) {
            case 'activate_plugin':
            case 'deactivate_plugin':
            case 'delete_plugin':
                if (in_array($args[0], $this->plugins, true)) {
                    $caps[] = 'do_not_allow';
                }
                break;

            /*
             * Core does not actually have 'delete_plugin' yet, so this is a bad but
             * necessary hack to prevent deleting one of these plugins loaded as MU.
             */
            case 'delete_plugins':
                if (isset($_REQUEST['checked'])) {
                    $plugins = wp_unslash($_REQUEST['checked']);
                    if (array_intersect($plugins, $this->plugins)) {
                        $caps[] = 'do_not_allow';
                    }
                }
                break;
        }

        return $caps;
    }

    /**
     * Filters the plugin action links in the plugins list tables.
     * This method removes links to actions that should not be allowed for plugins loaded
     * as MU plugins and adds a message informing about that status.
     * @param array $actions Associative array of $action_slug => $markup pairs.
     * @param string $plugin Plugin base name to which the actions apply.
     * @return array Filtered value of $actions.
     * @since 1.0.0
     */
    public function filterPluginActionLinks(array $actions, string $plugin): array
    {
        if (!in_array($plugin, $this->plugins, true)) {
            return $actions;
        }

        $disallowed_actions = ['activate', 'deactivate', 'delete'];
        foreach ($disallowed_actions as $disallowed_action) {
            if (isset($actions[$disallowed_action])) {
                unset($actions[$disallowed_action]);
            }
        }

        // Use 'network_active' as action slug because it is correctly styled by core.
        $actions['network_active'] = esc_html__('Must-Use', 'wp-plugin-mu-loader');

        return $actions;
    }

    /**
     * Dynamically applies the 'active' CSS class to the plugins that are loaded as MU plugins.
     * This is hacky, but there is no other way of adjusting the CSS classes.
     * @since 1.0.0
     */
    public function markPluginsActive(): void
    {
        if (empty($this->plugins)) {
            return;
        }

        ?>
        <script type="text/javascript">
          (function () {
            const plugins = JSON.parse('<?php echo wp_json_encode(array_values($this->plugins)); ?>')

            plugins.forEach(function (plugin) {
              const rows = document.querySelectorAll('tr[data-plugin="' + plugin + '"]')

              rows.forEach(function (row) {
                row.classList.remove('inactive')
                row.classList.add('active')
              })
            })
          })()
        </script>
        <?php
    }

    /**
     * Gets the list of plugins to load as MU plugins from the cache.
     * @return array List of plugin base names.
     * @since 1.0.0
     */
    public function getCache(): array
    {
        if (is_array($this->cache)) {
            return $this->cache;
        }

        if (is_multisite()) {
            $this->cache = get_network_option(null, self::CACHE_OPTION_NAME, []);

            return $this->cache;
        }

        $this->cache = get_option(self::CACHE_OPTION_NAME, []);

        return $this->cache;
    }

    /**
     * Sets the list of plugins to load as MU plugins in the cache.
     * @return bool True on success, false on failure.
     * @since 1.0.0
     */
    public function setCache(): bool
    {
        if ($this->plugins === $this->cache) {
            return true;
        }

        if (is_multisite()) {
            return update_network_option(null, self::CACHE_OPTION_NAME, $this->plugins);
        }

        return update_option(self::CACHE_OPTION_NAME, $this->plugins);
    }

    /**
     * Iterates through all plugins that might need to be deactivated and triggers AJAX requests
     * to run their deactivation routines.
     * @since 1.0.0
     */
    public function deactivateOldPlugins(): void
    {
        $plugins_to_deactivate = array_diff($this->cache, $this->plugins);

        foreach ($plugins_to_deactivate as $plugin) {
            if (!$this->needsDeactivation($plugin)) {
                continue;
            }

            wp_safe_remote_post(
                admin_url('admin-ajax.php'),
                [
                    'timeout' => 0.01,
                    'blocking' => false,
                    'cookies' => $_COOKIE,
                    'body' => [
                        'action' => self::AJAX_ACTION_PREFIX . 'deactivate',
                        '_wpnonce' => wp_create_nonce(self::AJAX_ACTION_PREFIX . 'deactivate_' . $plugin),
                        'basename' => $plugin,
                    ],
                ]
            );
        }
    }

    /**
     * Listens to an AJAX request in which a plugin's deactivation routine should fire.
     * @since 1.0.0
     */
    public function ajaxCallback()
    {
        $plugin = wp_unslash(filter_input(INPUT_POST, 'basename'));
        $file = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $plugin;

        check_ajax_referer(self::AJAX_ACTION_PREFIX . 'deactivate_' . $plugin);

        if (!file_exists($file)) {
            wp_die('0');
        }

        $this->requirePluginFile($file);
        $this->triggerDeactivationHook($plugin);

        wp_die('1');
    }

    /**
     * Checks whether the plugin of the given basename needs to be activated.
     * @param string $plugin Plugin basename.
     * @return bool True if the plugin needs to run its activation routine, false otherwise.
     * @since 1.0.0
     */
    protected function needsActivation(string $plugin): bool
    {
        if (in_array($plugin, $this->cache, true)) {
            return false;
        }

        return !in_array($plugin, $this->getActivePluginsUnFiltered(), true);
    }

    /**
     * Triggers the activation hook for the plugin of the given basename for the current context.
     * If a multisite, the activation hook is triggered network-wide. Otherwise it is triggered
     * only for the current site.
     * @param string $plugin Plugin basename.
     * @since 1.0.0
     */
    protected function triggerActivationHook(string $plugin): void
    {
        /** This action is documented in wp-admin/includes/plugin.php */
        add_action('admin_init', static function () use ($plugin): void {
            /** This action is documented in wp-admin/includes/plugin.php */
            do_action("activate_{$plugin}", is_multisite());
        });
    }

    /**
     * Checks whether the plugin of the given basename needs to be deactivated.
     * @param string $plugin Plugin basename.
     * @return bool True if the plugin needs to run its deactivation routine, false otherwise.
     * @since 1.0.0
     */
    protected function needsDeactivation(string $plugin): bool
    {
        if (in_array($plugin, $this->cache, true)) {
            return true;
        }

        return !in_array($plugin, $this->getActivePluginsUnFiltered(), true);
    }

    /**
     * Triggers the deactivation hook for the plugin of the given basename for the current context.
     * If a multisite, the deactivation hook is triggered network-wide. Otherwise it is triggered
     * only for the current site.
     * @param string $plugin Plugin basename.
     * @since 1.0.0
     */
    protected function triggerDeactivationHook(string $plugin): void
    {
        /** This action is documented in wp-admin/includes/plugin.php */
        do_action("deactivate_{$plugin}", is_multisite());
    }

    /**
     * Gets the unfiltered list of active plugin basenames for the current context.
     * If a multisite, the network-active plugin basenames are returned. Otherwise,
     * the site-active plugin basenames are returned.
     * @return array List of active plugin basenames.
     * @since 1.0.0
     */
    protected function getActivePluginsUnFiltered(): array
    {
        if (is_multisite()) {
            remove_filter('site_option_active_sitewide_plugins', [$this, 'filterNetworkActivePlugins'], 0);
            $result = get_network_option(null, 'active_sitewide_plugins', []);
            add_filter('site_option_active_sitewide_plugins', [$this, 'filterNetworkActivePlugins'], 0);

            return array_keys($result);
        }

        remove_filter('option_active_plugins', [$this, 'filterActivePlugins'], 0);
        $result = get_option('active_plugins', []);
        add_filter('option_active_plugins', [$this, 'filterActivePlugins'], 0);

        return $result;
    }

    /**
     * Requires a given plugin file.
     * @param string $plugin Full path to the plugin main file.
     * @since 1.0.0
     */
    protected function requirePluginFile(string $plugin): void
    {
        wp_register_plugin_realpath($plugin);
        include_once $plugin;
        /**
         * Fires once a single activated plugin has loaded.
         * @param string $plugin Full path to the plugin's main file.
         * @since 5.1.0 (core)
         */
        do_action('plugin_loaded', $plugin);
    }
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
        $loader = new WpPluginMuLoader($plugins);
        add_action('plugins_loaded', static function () use ($loader): void {
            $loader->addHooks();
        }, 0);
    }

    return $loader;
}
