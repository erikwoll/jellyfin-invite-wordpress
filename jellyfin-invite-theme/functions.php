<?php
if (!defined('ABSPATH')) exit;

const JFIT_OPTION_KEY = 'jfit_settings';
const JFIT_NONCE = 'jfit_admin_nonce';

add_action('after_switch_theme', 'jfit_activate_theme');
function jfit_activate_theme() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $invite_table = $wpdb->prefix . 'jfit_invites';
    $reg_table = $wpdb->prefix . 'jfit_registrations';

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$invite_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        token_hash VARCHAR(255) NOT NULL,
        token_plain VARCHAR(255) NOT NULL DEFAULT '',
        label VARCHAR(190) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        expires_at DATETIME NULL,
        max_uses INT UNSIGNED NOT NULL DEFAULT 1,
        used_count INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_used_at DATETIME NULL,
        PRIMARY KEY (id)
    ) {$charset_collate};");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$reg_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        invite_id BIGINT UNSIGNED NOT NULL,
        username VARCHAR(190) NOT NULL,
        email VARCHAR(190) NOT NULL DEFAULT '',
        jellyfin_user_id VARCHAR(190) NOT NULL DEFAULT '',
        registered_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY invite_id (invite_id)
    ) {$charset_collate};");

    $columns = $wpdb->get_col("SHOW COLUMNS FROM {$invite_table}", 0);
    if (is_array($columns) && !in_array('token_plain', $columns, true)) {
        $wpdb->query("ALTER TABLE {$invite_table} ADD COLUMN token_plain VARCHAR(255) NOT NULL DEFAULT '' AFTER token_hash");
    }
}

add_action('after_setup_theme', function() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
});

add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style(
        'jfit-theme-style',
        get_stylesheet_uri(),
        array(),
        filemtime(get_stylesheet_directory() . '/style.css')
    );
});

function jfit_get_settings() {
    $defaults = array(
        'jellyfin_url' => '',
        'api_key' => '',
        'app_name' => 'WordPress Invites',
        'device_name' => 'WP Server',
        'device_id' => 'wp-' . md5(home_url()),
        'default_expiry_hours' => 72,
        'invite_page_id' => 0,
    );
    return wp_parse_args(get_option(JFIT_OPTION_KEY, array()), $defaults);
}

add_action('admin_init', function() {
    register_setting('jfit_settings_group', JFIT_OPTION_KEY, 'jfit_sanitize_settings');
});

function jfit_sanitize_settings($input) {
    return array(
        'jellyfin_url' => isset($input['jellyfin_url']) ? esc_url_raw(untrailingslashit($input['jellyfin_url'])) : '',
        'api_key' => isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '',
        'app_name' => isset($input['app_name']) ? sanitize_text_field($input['app_name']) : 'WordPress Invites',
        'device_name' => isset($input['device_name']) ? sanitize_text_field($input['device_name']) : 'WP Server',
        'device_id' => isset($input['device_id']) ? sanitize_text_field($input['device_id']) : wp_generate_uuid4(),
        'default_expiry_hours' => isset($input['default_expiry_hours']) ? max(1, absint($input['default_expiry_hours'])) : 72,
        'invite_page_id' => isset($input['invite_page_id']) ? absint($input['invite_page_id']) : 0,
    );
}

function jfit_help_icon($text) {
    return ' <span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:999px;background:#2271b1;color:#fff;font-size:12px;font-weight:700;cursor:help;vertical-align:middle;" title="' . esc_attr($text) . '">?</span>';
}

function jfit_field_help($text) {
    echo '<p class="description" style="max-width:680px;margin-top:6px;">' . esc_html($text) . '</p>';
}


add_action('admin_menu', function() {
    add_theme_page('Jellyfin Invites', 'Jellyfin Invites', 'manage_options', 'jfit', 'jfit_render_admin_page');
});

add_action('admin_post_jfit_create_invite', 'jfit_handle_create_invite');
add_action('admin_post_jfit_delete_invite', 'jfit_handle_delete_invite');

function jfit_handle_create_invite() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
    check_admin_referer(JFIT_NONCE, 'jfit_nonce');

    $settings = jfit_get_settings();
    $page_url = $settings['invite_page_id'] ? get_permalink($settings['invite_page_id']) : '';
    if (!$page_url) wp_die('Please set a valid Invite Page ID first.');

    $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';
    $hours = isset($_POST['hours']) ? max(1, absint($_POST['hours'])) : 72;
    $max_uses = isset($_POST['max_uses']) ? max(1, absint($_POST['max_uses'])) : 1;
    $token = wp_generate_password(48, false, false);

    global $wpdb;
    $table = $wpdb->prefix . 'jfit_invites';
    $wpdb->insert($table, array(
        'token_hash' => wp_hash_password($token),
        'token_plain' => $token,
        'label' => $label,
        'created_at' => current_time('mysql', true),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + ($hours * HOUR_IN_SECONDS)),
        'max_uses' => $max_uses,
        'used_count' => 0,
        'is_active' => 1,
    ), array('%s','%s','%s','%s','%s','%d','%d','%d'));

    $invite_url = add_query_arg('invite', rawurlencode($token), $page_url);
    $redirect = add_query_arg(array(
        'page' => 'jfit',
        'tab' => 'invites',
        'created' => '1',
        'invite_url' => rawurlencode($invite_url)
    ), admin_url('themes.php'));
    wp_safe_redirect($redirect);
    exit;
}

function jfit_handle_delete_invite() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
    check_admin_referer(JFIT_NONCE, 'jfit_nonce');
    $invite_id = isset($_POST['invite_id']) ? absint($_POST['invite_id']) : 0;

    if ($invite_id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'jfit_invites', array('id' => $invite_id), array('%d'));
    }

    wp_safe_redirect(add_query_arg(array('page' => 'jfit', 'tab' => 'invites'), admin_url('themes.php')));
    exit;
}

function jfit_render_admin_page() {
    if (!current_user_can('manage_options')) return;

    global $wpdb;
    $settings = jfit_get_settings();
    $invite_table = $wpdb->prefix . 'jfit_invites';
    $reg_table = $wpdb->prefix . 'jfit_registrations';

    $invites = $wpdb->get_results("
        SELECT i.*,
               (
                   SELECT GROUP_CONCAT(r.username ORDER BY r.registered_at DESC SEPARATOR ', ')
                   FROM {$reg_table} r
                   WHERE r.invite_id = i.id
               ) AS used_by
        FROM {$invite_table} i
        ORDER BY i.id DESC
        LIMIT 100
    ");

    $registrations = $wpdb->get_results("
        SELECT r.*, i.label AS invite_label, i.token_plain
        FROM {$reg_table} r
        LEFT JOIN {$invite_table} i ON r.invite_id = i.id
        ORDER BY r.id DESC
        LIMIT 100
    ");

    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
    ?>
    <div class="wrap">
        <h1>Jellyfin Invites</h1>
        <p>Created by Erik Woll</p>
        <div style="margin:16px 0 18px;max-width:980px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:14px 16px;">
            <strong>Quick guide:</strong> Fill in the settings first, especially the Jellyfin URL, API key, and Invite Page ID. Then go to <em>Invites</em>, enter who the invite is for, choose expiry and max uses, and create the invite link.
        </div>

        <nav class="nav-tab-wrapper">
            <a href="<?php echo esc_url(admin_url('themes.php?page=jfit&tab=settings')); ?>" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
            <a href="<?php echo esc_url(admin_url('themes.php?page=jfit&tab=invites')); ?>" class="nav-tab <?php echo $tab === 'invites' ? 'nav-tab-active' : ''; ?>">Invites</a>
            <a href="<?php echo esc_url(admin_url('themes.php?page=jfit&tab=registrations')); ?>" class="nav-tab <?php echo $tab === 'registrations' ? 'nav-tab-active' : ''; ?>">Registrations</a>
        </nav>

        <?php if (!empty($_GET['created']) && !empty($_GET['invite_url'])): ?>
            <div class="notice notice-success">
                <p><strong>Invite created:</strong>
                    <input type="text" readonly class="large-text code" value="<?php echo esc_attr(rawurldecode(wp_unslash($_GET['invite_url']))); ?>">
                </p>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'settings'): ?>
            <form method="post" action="options.php" style="max-width:900px;background:#fff;padding:20px;border:1px solid #ddd;border-radius:12px;margin-top:20px;">
                <?php settings_fields('jfit_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="jellyfin_url">Jellyfin URL<?php echo jfit_help_icon('Enter the full base URL to your Jellyfin server, for example http://localhost:8096 or https://media.example.com'); ?></label></th>
                        <td>
                            <input class="regular-text" type="url" id="jellyfin_url" name="<?php echo esc_attr(JFIT_OPTION_KEY); ?>[jellyfin_url]" value="<?php echo esc_attr($settings['jellyfin_url']); ?>">
                            <?php jfit_field_help('Enter the full base URL to your Jellyfin server. Do not add /web at the end. Example: http://localhost:8096'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="api_key">Jellyfin API Key<?php echo jfit_help_icon('Create this in Jellyfin under Dashboard → API Keys. The key is only used server-side.'); ?></label></th>
                        <td>
                            <input class="regular-text" type="password" id="api_key" name="<?php echo esc_attr(JFIT_OPTION_KEY); ?>[api_key]" value="<?php echo esc_attr($settings['api_key']); ?>">
                            <?php jfit_field_help('Create an API key in Jellyfin under Dashboard → API Keys and paste it here. This lets WordPress create accounts in Jellyfin.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="invite_page_id">Invite Page ID<?php echo jfit_help_icon('This must be the WordPress page ID of the page that contains the [jellyfin_invite_signup] shortcode.'); ?></label></th>
                        <td>
                            <input class="small-text" type="number" id="invite_page_id" name="<?php echo esc_attr(JFIT_OPTION_KEY); ?>[invite_page_id]" value="<?php echo esc_attr($settings['invite_page_id']); ?>">
                            <?php jfit_field_help('Use the page ID of the page that contains the [jellyfin_invite_signup] shortcode. Invite links will be generated to this page.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="default_expiry_hours">Default Expiry (hours)<?php echo jfit_help_icon('This is the default lifetime for new invites. You can change it per invite later.'); ?></label></th>
                        <td>
                            <input class="small-text" type="number" id="default_expiry_hours" name="<?php echo esc_attr(JFIT_OPTION_KEY); ?>[default_expiry_hours]" value="<?php echo esc_attr($settings['default_expiry_hours']); ?>">
                            <?php jfit_field_help('This is the default number of hours new invites stay valid. You can still override it when creating an invite.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="app_name">App Name<?php echo jfit_help_icon('Shown to Jellyfin in request headers. The default value is fine for most setups.'); ?></label></th>
                        <td>
                            <input class="regular-text" type="text" id="app_name" name="<?php echo esc_attr(JFIT_OPTION_KEY); ?>[app_name]" value="<?php echo esc_attr($settings['app_name']); ?>">
                            <?php jfit_field_help('Internal app name sent to Jellyfin. You can keep the default unless you want a custom label.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="device_name">Device Name<?php echo jfit_help_icon('Shown to Jellyfin as the device making the request. The default value is usually enough.'); ?></label></th>
                        <td>
                            <input class="regular-text" type="text" id="device_name" name="<?php echo esc_attr(JFIT_OPTION_KEY); ?>[device_name]" value="<?php echo esc_attr($settings['device_name']); ?>">
                            <?php jfit_field_help('Internal device label used in Jellyfin request headers. The default value is fine for most setups.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="device_id">Device ID<?php echo jfit_help_icon('Unique identifier used in Jellyfin headers. Do not change this unless you know why you need to.'); ?></label></th>
                        <td>
                            <input class="regular-text" type="text" id="device_id" name="<?php echo esc_attr(JFIT_OPTION_KEY); ?>[device_id]" value="<?php echo esc_attr($settings['device_id']); ?>">
                            <?php jfit_field_help('Unique ID used for Jellyfin requests. Leave this as-is unless you need to rotate it.'); ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

        <?php elseif ($tab === 'invites'): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px;background:#fff;padding:20px;border:1px solid #ddd;border-radius:12px;margin-top:20px;">
                <?php wp_nonce_field(JFIT_NONCE, 'jfit_nonce'); ?>
                <input type="hidden" name="action" value="jfit_create_invite">
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="label">Assigned To<?php echo jfit_help_icon('Enter the name of the person this invite is intended for. This helps you track who received which link.'); ?></label></th>
                        <td>
                            <input id="label" name="label" class="regular-text" type="text" placeholder="e.g. John Smith">
                            <?php jfit_field_help('Use a name or note so you know who this invite was given to. Example: John Smith, Cousin Lisa, or Discord giveaway #1.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hours">Expires In (hours)<?php echo jfit_help_icon('How long the invite should remain valid from the time it is created.'); ?></label></th>
                        <td>
                            <input id="hours" name="hours" class="small-text" type="number" min="1" value="<?php echo esc_attr($settings['default_expiry_hours']); ?>">
                            <?php jfit_field_help('Choose how many hours the invite should stay active. After that, the invite link will stop working.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="max_uses">Max Uses<?php echo jfit_help_icon('Set how many times this invite link can be used before it is automatically disabled.'); ?></label></th>
                        <td>
                            <input id="max_uses" name="max_uses" class="small-text" type="number" min="1" value="1">
                            <?php jfit_field_help('Use 1 for a personal one-time invite. Increase this only if you want multiple people to use the same link.'); ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Create Invite'); ?>
            </form>

            <h2 style="margin-top:24px;">Existing Invites</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Assigned To</th>
                        <th>Invite Link</th>
                        <th>Created</th>
                        <th>Expires</th>
                        <th>Uses</th>
                        <th>Status</th>
                        <th>Used By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$invites): ?>
                    <tr><td colspan="9">No invites yet.</td></tr>
                <?php else: foreach ($invites as $invite): ?>
                    <?php
                    $invite_url = '';
                    if (!empty($settings['invite_page_id']) && !empty($invite->token_plain)) {
                        $invite_url = add_query_arg('invite', rawurlencode($invite->token_plain), get_permalink($settings['invite_page_id']));
                    }
                    $status = $invite->is_active ? 'Active' : 'Inactive';
                    if (!empty($invite->expires_at) && strtotime($invite->expires_at . ' UTC') < time()) {
                        $status = 'Expired';
                    } elseif ((int) $invite->used_count >= (int) $invite->max_uses) {
                        $status = 'Used up';
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html($invite->id); ?></td>
                        <td><?php echo esc_html($invite->label ?: '—'); ?></td>
                        <td style="max-width:340px;">
                            <?php if ($invite_url): ?>
                                <input type="text" readonly class="regular-text code" value="<?php echo esc_attr($invite_url); ?>" style="width:100%;">
                            <?php else: ?>
                                <em>Unavailable for older invites</em>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($invite->created_at); ?></td>
                        <td><?php echo esc_html($invite->expires_at); ?></td>
                        <td><?php echo esc_html($invite->used_count . '/' . $invite->max_uses); ?></td>
                        <td><?php echo esc_html($status); ?></td>
                        <td><?php echo esc_html($invite->used_by ?: '—'); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;" onsubmit="return confirm('Delete this invite permanently?');">
                                <?php wp_nonce_field(JFIT_NONCE, 'jfit_nonce'); ?>
                                <input type="hidden" name="action" value="jfit_delete_invite">
                                <input type="hidden" name="invite_id" value="<?php echo esc_attr($invite->id); ?>">
                                <button class="button button-link-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

        <?php else: ?>
            <h2 style="margin-top:24px;">Latest Registrations</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Assigned To</th>
                        <th>Invite Link</th>
                        <th>Jellyfin User ID</th>
                        <th>Registered At</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$registrations): ?>
                    <tr><td colspan="7">No registrations yet.</td></tr>
                <?php else: foreach ($registrations as $row): ?>
                    <?php
                    $registration_invite_url = '';
                    if (!empty($settings['invite_page_id']) && !empty($row->token_plain)) {
                        $registration_invite_url = add_query_arg('invite', rawurlencode($row->token_plain), get_permalink($settings['invite_page_id']));
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html($row->id); ?></td>
                        <td><?php echo esc_html($row->username); ?></td>
                        <td><?php echo esc_html($row->email ?: '—'); ?></td>
                        <td><?php echo esc_html($row->invite_label ?: ('Invite #' . $row->invite_id)); ?></td>
                        <td style="max-width:320px;">
                            <?php if ($registration_invite_url): ?>
                                <input type="text" readonly class="regular-text code" value="<?php echo esc_attr($registration_invite_url); ?>" style="width:100%;">
                            <?php else: ?>
                                <em>Unavailable for older invites</em>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($row->jellyfin_user_id); ?></td>
                        <td><?php echo esc_html($row->registered_at); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

add_shortcode('jellyfin_invite_signup', 'jfit_render_signup_shortcode');
function jfit_render_signup_shortcode() {
    $invite_token = isset($_GET['invite']) ? sanitize_text_field(wp_unslash($_GET['invite'])) : '';
    $output = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['jfit_submit'])) {
        $output .= jfit_handle_frontend_signup();
        $invite_token = isset($_POST['invite_token']) ? sanitize_text_field(wp_unslash($_POST['invite_token'])) : $invite_token;
    }

    ob_start();
    ?>
    <div class="jfit-wrap">
        <h2>Create your Jellyfin account</h2>
        <p>Use your invite link to register.</p>
        <?php echo $output; ?>
        <form method="post">
            <input type="hidden" name="invite_token" value="<?php echo esc_attr($invite_token); ?>">
            <div class="jfit-field">
                <label for="jfit_username">Username</label>
                <input id="jfit_username" type="text" name="username" required>
            </div>
            <div class="jfit-field">
                <label for="jfit_email">Email (optional)</label>
                <input id="jfit_email" type="email" name="email">
            </div>
            <div class="jfit-field">
                <label for="jfit_password">Password</label>
                <input id="jfit_password" type="password" name="password" minlength="8" required>
            </div>
            <button class="jfit-button" type="submit" name="jfit_submit" value="1">Create account</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

function jfit_handle_frontend_signup() {
    $invite_token = isset($_POST['invite_token']) ? sanitize_text_field(wp_unslash($_POST['invite_token'])) : '';
    $username = isset($_POST['username']) ? sanitize_user(wp_unslash($_POST['username']), true) : '';
    $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
    $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    $settings = jfit_get_settings();

    if (!$invite_token) return '<div class="jfit-message jfit-error">Invite token is missing.</div>';
    if (!$username || strlen($username) < 3) return '<div class="jfit-message jfit-error">The username is too short.</div>';
    if (!$password || strlen($password) < 8) return '<div class="jfit-message jfit-error">The password must be at least 8 characters.</div>';
    if (empty($settings['jellyfin_url']) || empty($settings['api_key'])) return '<div class="jfit-message jfit-error">Jellyfin is not configured yet.</div>';

    $invite = jfit_find_valid_invite($invite_token);
    if (!$invite) return '<div class="jfit-message jfit-error">This invite link is invalid or has expired.</div>';

    $created = jfit_jellyfin_create_user($username, $password, $settings);
    if (is_wp_error($created)) return '<div class="jfit-message jfit-error">' . esc_html($created->get_error_message()) . '</div>';

    $user_id = isset($created['Id']) ? sanitize_text_field($created['Id']) : '';
    if ($user_id) jfit_jellyfin_update_policy($user_id, $settings);
    jfit_mark_invite_used((int) $invite->id);
    jfit_log_registration((int) $invite->id, $username, $email, $user_id);

    return '<div class="jfit-message jfit-success">Done! Your Jellyfin account has been created.</div>';
}

function jfit_find_valid_invite($token) {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}jfit_invites WHERE is_active = 1 ORDER BY id DESC");
    $now = time();

    foreach ((array) $rows as $row) {
        if (!empty($row->expires_at) && strtotime($row->expires_at . ' UTC') < $now) continue;
        if ((int) $row->used_count >= (int) $row->max_uses) continue;
        if (wp_check_password($token, $row->token_hash)) return $row;
    }

    return false;
}

function jfit_mark_invite_used($invite_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'jfit_invites';
    $invite = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $invite_id));
    if (!$invite) return;

    $used_count = (int) $invite->used_count + 1;
    $is_active = $used_count < (int) $invite->max_uses ? 1 : 0;

    $wpdb->update($table, array(
        'used_count' => $used_count,
        'is_active' => $is_active,
        'last_used_at' => current_time('mysql', true),
    ), array('id' => $invite_id), array('%d','%d','%s'), array('%d'));
}

function jfit_log_registration($invite_id, $username, $email, $jellyfin_user_id) {
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'jfit_registrations', array(
        'invite_id' => $invite_id,
        'username' => $username,
        'email' => $email,
        'jellyfin_user_id' => $jellyfin_user_id,
        'registered_at' => current_time('mysql', true),
    ), array('%d','%s','%s','%s','%s'));
}

function jfit_jellyfin_headers($settings) {
    $auth = sprintf(
        'MediaBrowser Client="%s", Device="%s", DeviceId="%s", Version="1.0.0", Token="%s"',
        $settings['app_name'],
        $settings['device_name'],
        $settings['device_id'],
        $settings['api_key']
    );

    return array(
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-Emby-Authorization' => $auth,
        'X-Emby-Token' => $settings['api_key'],
    );
}

function jfit_jellyfin_create_user($username, $password, $settings) {
    $url = trailingslashit($settings['jellyfin_url']) . 'Users/New';
    $response = wp_remote_post($url, array(
        'timeout' => 20,
        'headers' => jfit_jellyfin_headers($settings),
        'body' => wp_json_encode(array('Name' => $username, 'Password' => $password)),
    ));

    if (is_wp_error($response)) {
        return new WP_Error('jfit_http', 'Could not connect to Jellyfin: ' . $response->get_error_message());
    }

    $status = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($status < 200 || $status >= 300) {
        $message = 'Jellyfin rejected the account creation.';
        if (is_array($body)) {
            if (!empty($body['message'])) $message = sanitize_text_field($body['message']);
            elseif (!empty($body['Message'])) $message = sanitize_text_field($body['Message']);
        }
        return new WP_Error('jfit_create_failed', $message);
    }

    return is_array($body) ? $body : array();
}

function jfit_jellyfin_update_policy($user_id, $settings) {
    $url = trailingslashit($settings['jellyfin_url']) . 'Users/' . rawurlencode($user_id) . '/Policy';
    $policy = array(
        'IsAdministrator' => false,
        'EnableRemoteControlOfOtherUsers' => false,
        'EnableSharedDeviceControl' => false,
        'EnableContentDeletion' => false,
        'EnableContentDownloading' => true,
        'EnableMediaPlayback' => true,
        'EnableAudioPlaybackTranscoding' => true,
        'EnableVideoPlaybackTranscoding' => true,
        'EnablePlaybackRemuxing' => true,
        'EnableLiveTvManagement' => false,
        'EnableLiveTvAccess' => false,
        'EnableUserPreferenceAccess' => true,
        'EnableAllFolders' => true,
        'AccessSchedules' => array(),
        'BlockUnratedItems' => array(),
        'EnabledFolders' => array(),
        'EnabledChannels' => array(),
        'EnableAllChannels' => true,
        'EnabledDevices' => array(),
        'EnableAllDevices' => true,
    );

    wp_remote_post($url, array(
        'method' => 'POST',
        'timeout' => 20,
        'headers' => jfit_jellyfin_headers($settings),
        'body' => wp_json_encode($policy),
    ));
}
