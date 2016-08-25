<?php

namespace WP_CLI_Login;

use WP_CLI;
use WP_User;

if (! class_exists('WP_CLI')) {
    return;
}

WP_CLI::add_command('login', LoginCommand::class);


/**
 * Manage magic passwordless logins.
 */
class LoginCommand
{
    /**
     * Option key for the current magic login endpoint.
     */
    const ENDPOINT_OPTION = 'wp_cli_login_endpoint';

    /**
     * Companion plugin file path, relative to plugins directory.
     */
    const PLUGIN_FILE = 'wp-cli-login-server/wp-cli-login-server.php';

    /**
     * Get a magic login URL for the given user.
     *
     * ## OPTIONS
     *
     * <user-locator>
     * : A string which identifies the user to be logged in as.
     * Possible values are: User ID, User Login, or User Email.
     *
     * [--url-only]
     * : Output the magic link URL only.
     *
     * [--launch]
     * : Launch the magic url immediately in your web browser.
     *
     * @param $_
     * @param $assoc
     *
     * @subcommand as
     */
    public function as_($_, $assoc)
    {
        $this->requirePluginActivation();

        list($user_locator) = $_;

        $user      = $this->lookupUser($user_locator);
        $magic_url = $this->makeMagicUrl($user);

        if (WP_CLI\Utils\get_flag_value($assoc, 'url-only')) {
            WP_CLI::line($magic_url);
            exit;
        }

        WP_CLI::success('Magic login link created!');
        WP_CLI::line($magic_url);
        WP_CLI::line('This link will self-destruct in 5 minutes, or as soon as it is used; whichever comes first.');

        if (WP_CLI\Utils\get_flag_value($assoc, 'launch')) {
            $this->launch($magic_url);
        }
    }


    /**
     * Invalidate any existing magic links.
     */
    public function invalidate()
    {
        update_option(static::ENDPOINT_OPTION, uniqid());

        WP_CLI::success('Magic links invalidated.');
    }

    /**
     * Launch the magic link URL in the default browser.
     *
     * @param $url
     */
    private function launch($url)
    {
        static::debug('Attempting to launch magic login with system browser...');

        $launch  = preg_match('/^WIN/', PHP_OS) ? 'start' : 'open';
        $process = WP_CLI\Process::create(sprintf('%s "%s"', $launch, $url));
        $result  = $process->run();

        if ($result->return_code > 0) {
            WP_CLI::error($result->stderr);
        }

        WP_CLI::success("Magic link launched!");
    }

    /**
     * Create the endpoint if it does not exist, and return the current value.
     *
     * @return string
     */
    private function endpoint()
    {
        add_option(static::ENDPOINT_OPTION, uniqid());

        return get_option(static::ENDPOINT_OPTION);
    }

    /**
     * Install/update the server plugin.
     *
     * ## OPTIONS
     *
     * Overwrites existing installed plugin, if any.
     *
     * [--activate]
     * : Activate the plugin after installing.
     *
     * @todo Update this to use versioning.
     */
    public function install($_, $assoc)
    {
        static::debug('Installing/refreshing companion plugin.');

        wp_mkdir_p(WP_PLUGIN_DIR . '/' . dirname(static::PLUGIN_FILE));

        // update / overwrite / refresh installed plugin file
        copy(
            __DIR__ . '/plugin/wp-cli-login-server.php',
            WP_PLUGIN_DIR . '/' . static::PLUGIN_FILE
        );

        if (file_exists(WP_PLUGIN_DIR . '/' . static::PLUGIN_FILE)) {
            WP_CLI::success('Companion plugin installed.');
        }

        if (WP_CLI\Utils\get_flag_value($assoc, 'activate')) {
            $this->toggle(['on']);
        }
    }

    /**
     * Toggle the active state of the companion plugin.
     *
     * [<on|off>]
     * : Toggle the companion plugin on or off.
     * Default: toggles active status.
     * ---
     * options:
     *   - on
     *   - off
     * ---
     * @param $_
     */
    public function toggle($_)
    {
        if (($setState = reset($_)) && ! in_array($setState, ['on','off'])) {
            WP_CLI::error('Invalid toggle value. Possible options are "on" and "off".');
        }

        if (! $setState) {
            $setState = is_plugin_active(static::PLUGIN_FILE) ? 'off' : 'on';
        }

        self::debug("Toggling companion plugin: $setState");

        $command = $setState == 'on' ? 'activate' : 'deactivate';

        WP_CLI::run_command(['plugin', $command, 'wp-cli-login-server']);
    }

    /**
     * Create a magic login URL
     *
     * @param  WP_User $user User to create login URL for
     *
     * @return string  URL
     */
    private function makeMagicUrl(WP_User $user)
    {
        static::debug("Generating a new magic login for User # $user->ID");

        $domain   = $this->domain();
        $endpoint = $this->endpoint();
        $public   = $this->newPublicKey();
        $private  = wp_hash_password("$public|$endpoint|$domain|$user->ID");
        $magic    = [
            'user'    => $user->ID,
            'private' => $private,
            'time'    => time(),
        ];

        set_transient("wp-cli-login/$public", json_encode($magic), MINUTE_IN_SECONDS * 5);

        return home_url("$endpoint/$public");
    }

    /**
     * Generate a new cryptographically sound public key.
     *
     * @return string
     */
    private function newPublicKey()
    {
        return implode('-', array_map('bin2hex', [
            random_bytes(random_int(3, 7)),
            random_bytes(random_int(4, 7)),
            random_bytes(random_int(5, 7)),
        ]));
    }

    /**
     * Get the target user by the given locator.
     *
     * @param  mixed $locator User login, ID, or email address
     *
     * @return WP_User
     */
    private function lookupUser($locator)
    {
        /**
         * WP_User does not accept a email in the constructor,
         * however an ID or user_login works just fine.
         * If the locator is a valid email address, use that,
         * otherwise, fallback to the constructor.
         */
        if (filter_var($locator, FILTER_VALIDATE_EMAIL)) {
            $user = get_user_by('email', $locator);
        }
        if (empty($user) || ! $user->exists()) {
            $user = new WP_User($locator);
        }

        if (! $user->exists()) {
            WP_CLI::error("No user found by: $locator");
        }

        return $user;
    }

    /**
     * Check active status of the companion plugin, and stop execution if it is not,
     * with instructions as to how to proceed.
     */
    private function requirePluginActivation()
    {
        if (! is_plugin_active(static::PLUGIN_FILE)) {
            WP_CLI::error('This command requires the companion plugin to be installed and active. Run `wp login install --activate` and try again.');
        }
    }

    /**
     * Get the domain of the current site.
     * @return mixed
     */
    private function domain()
    {
        return parse_url(home_url(), PHP_URL_HOST);
    }

    /**
     * Log to debug.
     *
     * @param $message
     */
    private static function debug($message)
    {
        WP_CLI::debug("[login] $message");
    }
}
