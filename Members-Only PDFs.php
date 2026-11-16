<?php
/**
 * Plugin Name: Members-Only Media
 * Plugin URI: https://github.com/jcjason12108-alt/Members-Only-PDFs
 * Description: Protect selected PDFs, images, documents, and archives for allowed WordPress roles. Filename-free links, private storage names, and original-path restoration on unlock.
 * Version: 1.10.0
 * Requires at least: 5.8
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Author: Jason Cox
 * Author URI: https://github.com/jcjason12108-alt
 * License: GPL-2.0-or-later
 * Text Domain: members-only-pdfs
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

/** -----------------------------------------------------------------------
 * Constants
 * --------------------------------------------------------------------- */
define('MOP_VERSION',        '1.10.0');
define('MOP_META_PROTECT',   '_mop_members_only');     // "1" / "0"
define('MOP_META_ORIG',      '_mop_orig_filename');    // original filename incl. ext
define('MOP_META_ORIG_PATH', '_mop_orig_relative_path'); // original location relative to uploads
define('MOP_META_ROLES',     '_mop_allowed_roles');    // array of role slugs per file
define('MOP_META_SIDECARS',  '_mop_protected_sidecars'); // protected preview basename => original basename
define('MOP_DIR',            wp_normalize_path(WP_CONTENT_DIR . '/uploads/members-only'));
define('MOP_URL_PARAM',      'mop_members_file');      // query var for attachment ID
define('MOP_NAME_PARAM',     'mop_members_name');      // query var for requested filename
define('MOP_OPTION',         'mop_settings');          // settings array

$mop_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/jcjason12108-alt/Members-Only-PDFs/',
    __FILE__,
    'members-only-pdfs'
);
$mop_update_checker->setBranch('main');

add_filter(
    $mop_update_checker->getUniqueName('vcs_update_detection_strategies'),
    static function (array $strategies): array {
        return isset($strategies['branch']) ? ['branch' => $strategies['branch']] : $strategies;
    }
);

$mop_github_token = defined('PLUGIN_UPDATE_GITHUB_TOKEN')
    ? PLUGIN_UPDATE_GITHUB_TOKEN
    : getenv('PLUGIN_UPDATE_GITHUB_TOKEN');

if (!empty($mop_github_token)) {
    $mop_update_checker->setAuthentication($mop_github_token);
}

/** -----------------------------------------------------------------------
 * Activation: ensure dir/.htaccess, defaults, rewrites, flush
 * --------------------------------------------------------------------- */
register_activation_hook(__FILE__, function () {
    if (!file_exists(MOP_DIR)) wp_mkdir_p(MOP_DIR);
    mop_ensure_htaccess();

    $defaults = [
        // Not-logged-in behavior
        'redirect_mode'  => 'login',   // login | page | url
        'redirect_page'  => 0,         // page ID
        'redirect_url'   => '',        // absolute URL
        // Forbidden (logged-in, lacks role) behavior
        'forbidden_mode' => 'none',    // none | page | url
        'forbidden_page' => 0,         // page ID
        'forbidden_url'  => '',        // absolute URL
    ];
    $current = get_option(MOP_OPTION);
    if (!is_array($current)) {
        add_option(MOP_OPTION, $defaults);
    } else {
        update_option(MOP_OPTION, array_merge($defaults, $current));
    }

    mop_register_rewrites();
    update_option('mop_version', MOP_VERSION);
    flush_rewrite_rules(false);
});

/** -----------------------------------------------------------------------
 * Init: guard folder, add rewrites, one-time safe flush
 * --------------------------------------------------------------------- */
add_action('init', function () {
    if (!file_exists(MOP_DIR)) wp_mkdir_p(MOP_DIR);
    mop_ensure_htaccess();

    mop_register_rewrites();

    if (get_option('mop_version') !== MOP_VERSION) {
        update_option('mop_flush_rewrite', '1');
        update_option('mop_version', MOP_VERSION);
    }

    if (get_option('mop_flush_rewrite') === '1') {
        flush_rewrite_rules(false);
        delete_option('mop_flush_rewrite');
    }
});

/** Register rewrites + query var */
function mop_register_rewrites(): void {
    add_rewrite_rule('^members-file/([0-9]+)/?$', 'index.php?' . MOP_URL_PARAM . '=$matches[1]', 'top');
    add_rewrite_rule('^members-file/([0-9]+)/(.+)?$', 'index.php?' . MOP_URL_PARAM . '=$matches[1]&' . MOP_NAME_PARAM . '=$matches[2]', 'top');
    add_rewrite_tag('%' . MOP_URL_PARAM . '%', '([0-9]+)');
    add_rewrite_tag('%' . MOP_NAME_PARAM . '%', '(.+)');
}
add_filter('query_vars', function ($vars) {
    $vars[] = MOP_URL_PARAM;
    $vars[] = MOP_NAME_PARAM;
    return $vars;
});

/** -----------------------------------------------------------------------
 * Admin notice: only show Nginx hint if not Apache/LiteSpeed
 * --------------------------------------------------------------------- */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;

    if (isset($_GET['mop_repaired']) && sanitize_text_field(wp_unslash($_GET['mop_repaired'])) === '1') {
        echo '<div class="notice notice-success is-dismissible"><p>Members-Only Media: Routes were flushed.</p></div>';
    }

    if (!mop_ensure_htaccess()) {
        echo '<div class="notice notice-error"><p><strong>Members-Only Media:</strong> The protected uploads folder could not be secured. Check its .htaccess file and folder permissions before protecting more files.</p></div>';
    }

    $srv = $_SERVER['SERVER_SOFTWARE'] ?? '';
    $is_apache    = stripos($srv, 'Apache')    !== false || function_exists('apache_get_version');
    $is_litespeed = stripos($srv, 'LiteSpeed') !== false;
    if ($is_apache || $is_litespeed) return;

    echo '<div class="notice notice-warning"><p><strong>Members-Only Media:</strong> This server does not appear to be Apache or LiteSpeed. You must block direct access to <code>wp-content/uploads/members-only/</code> in your web server config, such as an Nginx <code>location</code> rule.</p></div>';
});

/** -----------------------------------------------------------------------
 * Settings Page + "Repair Routes" button
 * --------------------------------------------------------------------- */
add_action('admin_menu', function () {
    add_options_page(
        'Members-Only Media',
        'Members-Only Media',
        'manage_options',
        'mop',
        'mop_render_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('mop_group', MOP_OPTION, [
        'type' => 'array',
        'sanitize_callback' => 'mop_sanitize_options',
        'default' => [
            'redirect_mode'  => 'login',
            'redirect_page'  => 0,
            'redirect_url'   => '',
            'forbidden_mode' => 'none',
            'forbidden_page' => 0,
            'forbidden_url'  => '',
        ],
    ]);

    add_settings_section('mop_section_login', 'Access Settings — Not Logged In', '__return_false', 'mop');

    add_settings_field('redirect_mode', 'When user is not logged in', function () {
        $o = get_option(MOP_OPTION);
        $mode = $o['redirect_mode'] ?? 'login';
        ?>
        <select name="<?php echo esc_attr(MOP_OPTION); ?>[redirect_mode]" id="mop_redirect_mode">
            <option value="login" <?php selected($mode, 'login'); ?>>Send to WordPress Login</option>
            <option value="page"  <?php selected($mode, 'page');  ?>>Send to Specific Page</option>
            <option value="url"   <?php selected($mode, 'url');   ?>>Send to Custom URL</option>
        </select>
        <?php
    }, 'mop', 'mop_section_login');

    add_settings_field('redirect_page', 'Redirect Page', function () {
        $o = get_option(MOP_OPTION);
        wp_dropdown_pages([
            'name'              => MOP_OPTION . '[redirect_page]',
            'selected'          => intval($o['redirect_page'] ?? 0),
            'show_option_none'  => '-- Select Page --',
            'option_none_value' => 0,
        ]);
        echo '<p class="description">Used only if “Send to Specific Page” is selected.</p>';
    }, 'mop', 'mop_section_login');

    add_settings_field('redirect_url', 'Custom URL', function () {
        $o = get_option(MOP_OPTION);
        $url = esc_url($o['redirect_url'] ?? '');
        echo '<input type="url" class="regular-text" name="' . esc_attr(MOP_OPTION) . '[redirect_url]" value="' . esc_attr($url) . '"/>';
        echo '<p class="description">Absolute URL; used only if “Send to Custom URL” is selected.</p>';
    }, 'mop', 'mop_section_login');

    // Forbidden (logged-in, lacks role)
    add_settings_section('mop_section_forbidden', 'Access Settings — Lacks Permission (Logged In)', '__return_false', 'mop');

    add_settings_field('forbidden_mode', 'When user lacks permission', function () {
        $o = get_option(MOP_OPTION);
        $mode = $o['forbidden_mode'] ?? 'none';
        ?>
        <select name="<?php echo esc_attr(MOP_OPTION); ?>[forbidden_mode]" id="mop_forbidden_mode">
            <option value="none" <?php selected($mode, 'none'); ?>>Do nothing (send 403)</option>
            <option value="page" <?php selected($mode, 'page'); ?>>Send to Specific Page</option>
            <option value="url"  <?php selected($mode, 'url');  ?>>Send to Custom URL</option>
        </select>
        <?php
    }, 'mop', 'mop_section_forbidden');

    add_settings_field('forbidden_page', 'Forbidden Redirect Page', function () {
        $o = get_option(MOP_OPTION);
        wp_dropdown_pages([
            'name'              => MOP_OPTION . '[forbidden_page]',
            'selected'          => intval($o['forbidden_page'] ?? 0),
            'show_option_none'  => '-- Select Page --',
            'option_none_value' => 0,
        ]);
        echo '<p class="description">Used only if “Send to Specific Page” is selected above.</p>';
    }, 'mop', 'mop_section_forbidden');

    add_settings_field('forbidden_url', 'Forbidden Custom URL', function () {
        $o = get_option(MOP_OPTION);
        $url = esc_url($o['forbidden_url'] ?? '');
        echo '<input type="url" class="regular-text" name="' . esc_attr(MOP_OPTION) . '[forbidden_url]" value="' . esc_attr($url) . '"/>';
        echo '<p class="description">Absolute URL; used only if “Send to Custom URL” is selected above.</p>';
    }, 'mop', 'mop_section_forbidden');
});

function mop_render_settings_page() {
    if (isset($_POST['mop_repair_routes'])) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to manage Members-Only Media settings.', 'members-only-pdfs'));
        }

        check_admin_referer('mop_repair_routes');
        mop_register_rewrites();
        flush_rewrite_rules(false);
        wp_safe_redirect(add_query_arg('mop_repaired', '1', menu_page_url('mop', false)));
        exit;
    }

    if (isset($_POST['mop_unlock_attachment'])) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to manage Members-Only Media settings.', 'members-only-pdfs'));
        }

        $attachment_id = isset($_POST['mop_attachment_id']) ? absint(wp_unslash($_POST['mop_attachment_id'])) : 0;
        check_admin_referer('mop_unlock_attachment_' . $attachment_id);

        $result = mop_unlock_attachment($attachment_id);
        $args = is_wp_error($result)
            ? ['mop_unlock_error' => $result->get_error_message()]
            : ['mop_unlocked' => $attachment_id];

        wp_safe_redirect(add_query_arg($args, menu_page_url('mop', false)));
        exit;
    }
    if (isset($_POST['mop_obscure_attachment'])) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to manage Members-Only Media settings.', 'members-only-pdfs'));
        }
        $attachment_id = isset($_POST['mop_attachment_id']) ? absint(wp_unslash($_POST['mop_attachment_id'])) : 0;
        check_admin_referer('mop_obscure_attachment_' . $attachment_id);
        $file = get_attached_file($attachment_id, true);
        $result = $file && get_post_meta($attachment_id, MOP_META_PROTECT, true) === '1'
            ? mop_protect_attachment_files($attachment_id, wp_normalize_path($file))
            : new WP_Error('mop_not_protected', 'The selected attachment is not protected.');
        $args = is_wp_error($result)
            ? ['mop_obscure_error' => $result->get_error_message()]
            : ['mop_obscured' => $attachment_id];
        wp_safe_redirect(add_query_arg($args, menu_page_url('mop', false)));
        exit;
    }
    ?>
    <div class="wrap">
        <h1>Members-Only Media</h1>
        <?php mop_render_settings_notices(); ?>
        <form method="post" action="options.php">
            <?php
            settings_fields('mop_group');
            do_settings_sections('mop');
            submit_button('Save Changes');
            ?>
        </form>
        <hr />
        <h2>Maintenance</h2>
        <form method="post">
            <?php wp_nonce_field('mop_repair_routes'); ?>
            <p>If protected links 404 after a migration or plugin/theme changes, click to rebuild rewrite rules.</p>
            <p><button type="submit" name="mop_repair_routes" class="button button-secondary">Repair Routes (Flush Permalinks)</button></p>
        </form>
        <hr />
        <?php mop_render_secured_files_table(); ?>
    </div>
    <?php
}

function mop_render_settings_notices(): void {
    if (isset($_GET['mop_unlocked'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Members-Only Media: File unlocked and moved back into the regular Media Library uploads folder.</p></div>';
    }

    if (isset($_GET['mop_unlock_error'])) {
        $message = sanitize_text_field(wp_unslash($_GET['mop_unlock_error']));
        echo '<div class="notice notice-error is-dismissible"><p>Members-Only Media: ' . esc_html($message) . '</p></div>';
    }

    if (isset($_GET['mop_obscured'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Members-Only Media: The protected file and its previews now use private filenames.</p></div>';
    }
    if (isset($_GET['mop_obscure_error'])) {
        $message = sanitize_text_field(wp_unslash($_GET['mop_obscure_error']));
        echo '<div class="notice notice-error is-dismissible"><p>Members-Only Media: ' . esc_html($message) . '</p></div>';
    }
}

function mop_render_secured_files_table(): void {
    $secured = get_posts([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => mop_supported_mime_types(),
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [
            [
                'key'   => MOP_META_PROTECT,
                'value' => '1',
            ],
        ],
    ]);

    echo '<h2>Secured Media</h2>';
    echo '<p>Protected media files are listed here so they can be audited, given private storage names, or unlocked without opening each Media Library item.</p>';

    if (empty($secured)) {
        echo '<p>No supported media files are currently secured.</p>';
        return;
    }

    echo '<table class="widefat striped">';
    echo '<thead><tr><th>File</th><th>Type</th><th>Allowed Roles</th><th>Protected URL</th><th>Action</th></tr></thead><tbody>';

    foreach ($secured as $attachment) {
        $id = (int) $attachment->ID;
        $title = get_the_title($id);
        $filename = get_post_meta($id, MOP_META_ORIG, true);
        if (!$filename) {
            $file = get_attached_file($id, true);
            $filename = $file ? wp_basename($file) : ('Attachment #' . $id);
        }

        $roles = mop_get_allowed_roles($id);
        $all_roles = mop_get_all_roles();
        $role_labels = [];
        foreach ($roles as $role) {
            $role_labels[] = $all_roles[$role] ?? $role;
        }
        $role_text = $role_labels ? implode(', ', $role_labels) : 'Administrators only';

        echo '<tr>';
        echo '<td><strong>' . esc_html($title ?: $filename) . '</strong><br><span class="description">' . esc_html($filename) . ' · ID ' . esc_html((string) $id) . '</span></td>';
        echo '<td>' . esc_html(mop_get_attachment_kind($id)) . '</td>';
        echo '<td>' . esc_html($role_text) . '</td>';
        echo '<td><input type="text" class="regular-text code" readonly value="' . esc_attr(mop_build_protected_url($id)) . '" onclick="this.select();" /></td>';
        echo '<td>';
        $file = get_attached_file($id, true);
        $sidecars = get_post_meta($id, MOP_META_SIDECARS, true);
        $needs_obscuring = !$file || !mop_is_opaque_protected_name($file);
        if (is_array($sidecars)) {
            foreach (array_keys($sidecars) as $protected_name) {
                if (!mop_is_opaque_protected_name((string) $protected_name)) $needs_obscuring = true;
            }
        }
        if ($needs_obscuring) {
            echo '<form method="post">';
            wp_nonce_field('mop_obscure_attachment_' . $id);
            echo '<input type="hidden" name="mop_attachment_id" value="' . esc_attr((string) $id) . '">';
            submit_button('Hide filename', 'secondary small', 'mop_obscure_attachment', false);
            echo '</form>';
        }
        echo '<form method="post">';
        wp_nonce_field('mop_unlock_attachment_' . $id);
        echo '<input type="hidden" name="mop_attachment_id" value="' . esc_attr((string) $id) . '">';
        submit_button('Unlock', 'secondary small', 'mop_unlock_attachment', false, ['onclick' => "return confirm('Unlock this file and move it back into regular Media Library uploads?');"]);
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

function mop_sanitize_options($in) {
    if (!current_user_can('manage_options')) {
        return get_option(MOP_OPTION, []);
    }

    $in = is_array($in) ? wp_unslash($in) : [];

    return [
        'redirect_mode'  => in_array(($in['redirect_mode'] ?? 'login'), ['login','page','url'], true) ? $in['redirect_mode'] : 'login',
        'redirect_page'  => max(0, intval($in['redirect_page'] ?? 0)),
        'redirect_url'   => esc_url_raw($in['redirect_url'] ?? ''),
        'forbidden_mode' => in_array(($in['forbidden_mode'] ?? 'none'), ['none','page','url'], true) ? $in['forbidden_mode'] : 'none',
        'forbidden_page' => max(0, intval($in['forbidden_page'] ?? 0)),
        'forbidden_url'  => esc_url_raw($in['forbidden_url'] ?? ''),
    ];
}

/** -----------------------------------------------------------------------
 * Media UI: members-only toggle, Protected URL, per-file roles (checkboxes)
 * --------------------------------------------------------------------- */
add_filter('attachment_fields_to_edit', function ($form_fields, $post) {
    if (!mop_is_supported_attachment((int) $post->ID)) return $form_fields;

    $id = (int)$post->ID;
    $is_protected  = (get_post_meta($id, MOP_META_PROTECT, true) === '1');
    $all_roles     = mop_get_all_roles();            // [slug => label]
    $allowed_roles = mop_get_allowed_roles($id);     // array of slugs
    $csv_value     = implode(',', $allowed_roles);

    // Toggle
    $form_fields['mop_members_only'] = [
        'label' => __('Members-Only', 'mop'),
        'input' => 'html',
        'html'  => '<label><input type="checkbox" name="attachments[' . esc_attr($id) . '][mop_members_only]" value="1" ' . checked(true, $is_protected, false) . '> ' . esc_html__('Only logged-in users can view', 'mop') . '</label>',
        'helps' => __('Moves the file into a protected folder and serves it via a secure endpoint.', 'mop'),
    ];

    // Protected URL (copy-ready)
    if ($is_protected) {
        $protected_url = mop_build_protected_url($id);
        $form_fields['mop_members_only_url'] = [
            'label' => __('Protected URL', 'mop'),
            'input' => 'html',
            'html'  => '<input type="text" class="regular-text code" readonly value="' . esc_attr($protected_url) . '" onclick="this.select();" />' .
                       '<p class="description">Share this URL with members. It does not include the original filename.</p>',
        ];
    }

    // Per-file Allowed Roles (checkboxes) + hidden CSV synchronized by JS
    $html = '<input type="hidden" name="attachments[' . esc_attr($id) . '][mop_allowed_roles_present]" value="1">';
    $html .= '<input type="hidden" class="mop-roles-csv" name="attachments[' . esc_attr($id) . '][mop_allowed_roles_csv]" value="' . esc_attr($csv_value) . '">';
    foreach ($all_roles as $slug => $name) {
        $checked = in_array($slug, $allowed_roles, true) ? 'checked' : '';
        $html .= '<label style="display:block;margin:.15em 0;">'
              .  '<input type="checkbox" class="mop-role" name="attachments[' . esc_attr($id) . '][mop_allowed_roles][]" value="' . esc_attr($slug) . '" data-role="' . esc_attr($slug) . '" ' . $checked . '> '
              .  esc_html($name)
              .  '</label>';
    }
    $warn = ($is_protected && empty($allowed_roles))
        ? '<p style="color:#b32d2e;margin-top:.25em;"><strong>' . esc_html__('No roles selected: protected file will be inaccessible to non-admins.', 'mop') . '</strong></p>'
        : '';

    $form_fields['mop_allowed_roles'] = [
        'label' => __('Roles that can view (this file)', 'mop'),
        'input' => 'html',
        'html'  => $html . $warn . '<p class="description">Tick multiple roles; users with <em>any</em> selected role can view.</p>',
        'helps' => __('Leave all unchecked to deny everyone except administrators.', 'mop'),
    ];

    return $form_fields;
}, 10, 2);

/**
 * Admin JS: keep hidden CSV in sync with the role checkboxes
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if (!in_array($hook, ['upload.php','media.php','post.php','post-new.php','site-editor.php','widgets.php','customize.php'], true)) return;

    $js = <<<JS
(function($){
  function syncCSV(container){
    var boxes = container.querySelectorAll('input.mop-role[type="checkbox"]');
    var vals = [];
    boxes.forEach(function(b){ if(b.checked){ vals.push(b.getAttribute('data-role')); }});
    var hidden = container.querySelector('input.mop-roles-csv');
    if (hidden) hidden.value = vals.join(',');
  }
  function wire(container){
    if (!container) return;
    container.addEventListener('change', function(e){
      if (e.target && e.target.matches('input.mop-role[type="checkbox"]')){
        syncCSV(container);
      }
    });
    syncCSV(container);
  }
  document.querySelectorAll('.compat-attachment-fields').forEach(wire);
  var mo = new MutationObserver(function(muts){
    muts.forEach(function(m){
      m.addedNodes && m.addedNodes.forEach(function(n){
        if (n.nodeType !== 1) return;
        if (n.matches('.attachment-details, .edit-attachment-frame, .media-modal-content, .compat-attachment-fields')){
          n.querySelectorAll('.compat-attachment-fields').forEach(wire);
        }
      });
    });
  });
  mo.observe(document.body, {childList:true, subtree:true});
})(jQuery);
JS;
    wp_add_inline_script('media-views', $js, 'after');
});

/** Persist: protect toggle + roles CSV */
add_filter('attachment_fields_to_save', function ($post, $attachment) {
    $id = (int)$post['ID'];
    if (!mop_is_supported_attachment($id)) return $post;
    if (!current_user_can('edit_post', $id)) return $post;

    // Protect toggle
    $want_protected = isset($attachment['mop_members_only']) ? '1' : '0';
    $curr           = get_post_meta($id, MOP_META_PROTECT, true) === '1' ? '1' : '0';

    if ($want_protected !== $curr) {
        $result = mop_move_file_protection_state($id, $want_protected === '1');
        if (is_wp_error($result)) {
            $post['errors']['mop_members_only'] = $result->get_error_message();
        } else {
            update_post_meta($id, MOP_META_PROTECT, $want_protected);
        }
    }

    // Allowed roles from submitted checkbox values, with CSV kept as a fallback.
    $roles = [];
    if (!empty($attachment['mop_allowed_roles']) && is_array($attachment['mop_allowed_roles'])) {
        $roles = array_map('sanitize_text_field', wp_unslash($attachment['mop_allowed_roles']));
    } elseif (isset($attachment['mop_allowed_roles_present'])) {
        $roles = [];
    } elseif (!empty($attachment['mop_allowed_roles_csv'])) {
        $parts = array_filter(array_map('sanitize_text_field', explode(',', wp_unslash($attachment['mop_allowed_roles_csv']))));
        $roles = array_values(array_unique($parts));
    }
    // keep only valid roles actually registered on the site
    $valid = array_keys(mop_get_all_roles());
    $roles = array_values(array_unique($roles));
    $roles = array_values(array_intersect($roles, $valid));
    update_post_meta($id, MOP_META_ROLES, $roles);

    return $post;
}, 10, 2);

/** Require valid roles before accepting an automation's protected PDF upload. */
add_filter('rest_request_before_callbacks', function ($response, $handler, WP_REST_Request $request) {
    if (in_array($request->get_method(), ['GET', 'HEAD'], true) &&
        preg_match('~^/wp/v2/media/([0-9]+)/?$~', $request->get_route(), $matches) &&
        get_post_meta((int) $matches[1], MOP_META_PROTECT, true) === '1' &&
        !current_user_can('edit_post', (int) $matches[1])) {
        return new WP_Error('rest_post_invalid_id', 'Invalid post ID.', ['status' => 404]);
    }
    if (!mop_is_protected_upload_request($request) || is_wp_error($response)) return $response;

    $roles = mop_protected_upload_roles($request);
    return is_wp_error($roles) ? $roles : $response;
}, 10, 3);

/** Keep protected attachment names out of the public media collection. */
add_filter('rest_attachment_query', function ($args) {
    if (current_user_can('upload_files')) return $args;
    $exclude_protected = [
        'relation' => 'OR',
        ['key' => MOP_META_PROTECT, 'compare' => 'NOT EXISTS'],
        ['key' => MOP_META_PROTECT, 'value' => '1', 'compare' => '!='],
    ];
    if (!empty($args['meta_query'])) {
        $args['meta_query'] = ['relation' => 'AND', $args['meta_query'], $exclude_protected];
    } else {
        $args['meta_query'] = $exclude_protected;
    }
    return $args;
}, 10, 1);

/** Secure the PDF before a successful Media Library upload response is sent. */
add_filter('rest_request_after_callbacks', function ($response, $handler, WP_REST_Request $request) {
    if ($request->get_route() !== '/wp/v2/media' || $request->get_method() !== 'POST' || is_wp_error($response)) return $response;
    if (!($response instanceof WP_REST_Response) || $response->get_status() !== 201) return $response;

    $data = $response->get_data();
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    if (!mop_is_protected_upload_request($request) && !mop_is_roster_pdf_upload($id)) return $response;
    if (!$id || get_post_type($id) !== 'attachment') {
        return new WP_Error('mop_missing_upload', 'The protected upload did not create a media attachment.', ['status' => 500]);
    }

    if (!mop_is_pdf($id)) {
        wp_delete_attachment($id, true);
        return new WP_Error('mop_pdf_required', 'This protected upload must be a PDF.', ['status' => 400]);
    }

    $roles = mop_protected_upload_roles($request, $id);
    if (is_wp_error($roles)) {
        wp_delete_attachment($id, true);
        return $roles;
    }

    $result = mop_move_file_protection_state($id, true);
    $file = get_attached_file($id, true);
    if (is_wp_error($result) || !$file || !mop_path_is_in_dir($file, MOP_DIR)) {
        wp_delete_attachment($id, true);
        return new WP_Error('mop_protection_failed', 'The PDF could not be moved into protected storage.', ['status' => 500]);
    }

    update_post_meta($id, MOP_META_ROLES, $roles);
    update_post_meta($id, MOP_META_PROTECT, '1');
    if (get_post_meta($id, MOP_META_PROTECT, true) !== '1' || mop_get_allowed_roles($id) !== $roles) {
        wp_delete_attachment($id, true);
        return new WP_Error('mop_protection_failed', 'The PDF access rules could not be saved.', ['status' => 500]);
    }

    $data['source_url'] = mop_build_protected_url($id);
    $data['mop_protected'] = true;
    $data['mop_allowed_roles'] = $roles;
    $response->set_data($data);
    return $response;
}, 10, 3);

function mop_is_protected_upload_request(WP_REST_Request $request): bool {
    return $request->get_route() === '/wp/v2/media'
        && $request->get_method() === 'POST'
        && trim((string) $request->get_header('x-mop-protect-roles')) !== '';
}

function mop_is_roster_pdf_upload(int $id): bool {
    if (!$id || !mop_is_pdf($id)) return false;
    $file = get_attached_file($id, true);
    return $file && (bool) preg_match('/^seniority-roster(?:-\d{4}-\d{2}-\d{2})?(?:-\d+)?\.pdf$/i', wp_basename($file));
}

function mop_protected_upload_roles(WP_REST_Request $request, int $id = 0) {
    $raw = (string) $request->get_header('x-mop-protect-roles');
    $roles = $raw === '' && mop_is_roster_pdf_upload($id)
        ? ['administrator', 'executive_board', 'member']
        : array_map('trim', explode(',', $raw));
    $roles = array_values(array_unique($roles));
    if (!$roles || in_array('', $roles, true)) {
        return new WP_Error('mop_roles_required', 'Provide allowed WordPress role slugs.', ['status' => 400]);
    }
    $registered = array_keys(mop_get_all_roles());
    foreach ($roles as $role) {
        if ($role !== sanitize_key($role) || !in_array($role, $registered, true)) {
            return new WP_Error('mop_invalid_roles', 'Every requested role must exist on this site.', ['status' => 400]);
        }
    }
    return $roles;
}

/** -----------------------------------------------------------------------
 * URL rewriting for protected files → /members-file/{id}/
 * --------------------------------------------------------------------- */
add_filter('wp_get_attachment_url', function ($url, $post_id) {
    if (!mop_is_supported_attachment($post_id)) return $url;
    if (get_post_meta($post_id, MOP_META_PROTECT, true) !== '1') return $url;
    return mop_build_protected_url($post_id);
}, 10, 2);

function mop_build_protected_url(int $post_id, string $filename = ''): string {
    $base = home_url('/members-file/' . $post_id . '/');
    if ($filename === '') return $base;

    $requested = wp_basename($filename);
    $original = wp_basename((string) get_post_meta($post_id, MOP_META_ORIG, true));
    $file = get_attached_file($post_id, true);
    if ($requested === $original || ($file && $requested === wp_basename($file))) return $base;

    $sidecars = get_post_meta($post_id, MOP_META_SIDECARS, true);
    if (is_array($sidecars)) {
        foreach ($sidecars as $protected_name => $original_name) {
            if ($requested === wp_basename((string) $original_name) || $requested === wp_basename((string) $protected_name)) {
                return $base . rawurlencode(wp_basename((string) $protected_name));
            }
        }
    }

    return $base;
}

add_filter('image_downsize', function ($out, $id, $size) {
    if ((!mop_is_image($id) && !mop_is_pdf($id)) || get_post_meta($id, MOP_META_PROTECT, true) !== '1') return $out;

    $metadata = wp_get_attachment_metadata($id);
    if (!is_array($metadata)) return $out;

    $is_pdf = mop_is_pdf($id);
    $preview = $is_pdf ? ($metadata['sizes']['full'] ?? null) : null;
    if ($is_pdf && (!is_array($preview) || empty($preview['file']))) return $out;
    $width = $is_pdf ? (int) ($preview['width'] ?? 0) : (int) ($metadata['width'] ?? 0);
    $height = $is_pdf ? (int) ($preview['height'] ?? 0) : (int) ($metadata['height'] ?? 0);
    $filename = $is_pdf ? $preview['file'] : get_post_meta($id, MOP_META_ORIG, true);
    $is_intermediate = false;

    if ($size !== 'full') {
        $intermediate = image_get_intermediate_size($id, $size);
        if (is_array($intermediate) && !empty($intermediate['file'])) {
            $filename = $intermediate['file'];
            $width = isset($intermediate['width']) ? (int) $intermediate['width'] : $width;
            $height = isset($intermediate['height']) ? (int) $intermediate['height'] : $height;
            $is_intermediate = true;
        }
    }

    return [mop_build_protected_url($id, (string) $filename), $width, $height, $is_intermediate];
}, 10, 3);

add_filter('wp_calculate_image_srcset', function ($sources, $size_array, $image_src, $image_meta, $attachment_id) {
    $attachment_id = (int) $attachment_id;
    if ((!mop_is_image($attachment_id) && !mop_is_pdf($attachment_id)) || get_post_meta($attachment_id, MOP_META_PROTECT, true) !== '1') return $sources;

    foreach ($sources as $width => $source) {
        if (!empty($source['url'])) {
            $sources[$width]['url'] = mop_build_protected_url($attachment_id, wp_basename($source['url']));
        }
    }

    return $sources;
}, 10, 5);

/** -----------------------------------------------------------------------
 * Route: serve protected files (requires login + role match)
 * --------------------------------------------------------------------- */
add_action('template_redirect', function () {
    $id = get_query_var(MOP_URL_PARAM);
    if (!$id) return;

    $attachment_id = absint($id);
    if (!$attachment_id || !mop_is_supported_attachment($attachment_id)) {
        mop_404();
    }

    // Must be protected to use this endpoint
    if (get_post_meta($attachment_id, MOP_META_PROTECT, true) !== '1') {
        mop_404();
    }

    // Not logged in → redirect per settings
    if (!is_user_logged_in()) {
        $o = get_option(MOP_OPTION);
        $mode = $o['redirect_mode'] ?? 'login';
        switch ($mode) {
            case 'page':
                $pid = intval($o['redirect_page'] ?? 0);
                if ($pid > 0 && ($target = get_permalink($pid))) { wp_safe_redirect($target); exit; }
                auth_redirect(); exit;
            case 'url':
                $url = $o['redirect_url'] ?? '';
                if (!empty($url)) { mop_redirect_to_custom_url($url); }
                auth_redirect(); exit;
            case 'login':
            default:
                auth_redirect(); exit;
        }
    }

    // Logged in → must have one of the allowed roles (admins bypass)
    if (!mop_user_can_view($attachment_id)) {
        $o = get_option(MOP_OPTION);
        $mode = $o['forbidden_mode'] ?? 'none';
        if ($mode === 'page') {
            $pid = intval($o['forbidden_page'] ?? 0);
            if ($pid > 0 && ($target = get_permalink($pid))) { wp_safe_redirect($target); exit; }
        } elseif ($mode === 'url') {
            $url = $o['forbidden_url'] ?? '';
            if (!empty($url)) { mop_redirect_to_custom_url($url); }
        }
        // Default: 403
        status_header(403);
        nocache_headers();
        exit('Forbidden');
    }

    // Ensure file exists and is in protected dir; if not, try to move now
    $file = get_attached_file($attachment_id, true);
    if (!$file || !file_exists($file) || !mop_path_is_in_dir($file, MOP_DIR)) {
        $result = mop_move_file_protection_state($attachment_id, true);
        if (is_wp_error($result)) {
            mop_404();
        }
        $file = get_attached_file($attachment_id, true);
        if (!$file || !file_exists($file) || !mop_path_is_in_dir($file, MOP_DIR)) {
            mop_404();
        }
    }

    $orig = get_post_meta($attachment_id, MOP_META_ORIG, true);
    if (!$orig) $orig = wp_basename($file);

    $requested_name = sanitize_text_field(wp_unslash((string) get_query_var(MOP_NAME_PARAM)));
    $stream = mop_resolve_requested_protected_file($attachment_id, $file, $requested_name);
    if (is_wp_error($stream)) {
        mop_404();
    }

    // Old links keep working, but browsers switch to the filename-free URL.
    if ($requested_name !== '') {
        $canonical_name = $stream['path'] === $file ? '' : wp_basename($stream['path']);
        if ($requested_name !== $canonical_name) {
            wp_safe_redirect(mop_build_protected_url($attachment_id, $canonical_name), 302);
            exit;
        }
    }

    mop_stream_file($stream['path'], $stream['name'], $attachment_id);
    exit;
});

/** -----------------------------------------------------------------------
 * Media Grid & Modal Lock Icon (robust)
 * --------------------------------------------------------------------- */

/** Expose protection flag to Media Library JS models. */
add_filter('wp_prepare_attachment_for_js', function ($response, $attachment, $meta) {
    if (mop_is_supported_attachment((int) $attachment->ID)) {
        $response['mopProtected'] = (get_post_meta($attachment->ID, MOP_META_PROTECT, true) === '1');
    }
    return $response;
}, 10, 3);

/** Filter Media Library grid AJAX requests when a locked-files view is selected. */
add_filter('ajax_query_attachments_args', function ($query) {
    if (!current_user_can('upload_files')) return $query;

    $request = [];
    if (isset($_REQUEST['query']) && is_array($_REQUEST['query'])) {
        $request = wp_unslash($_REQUEST['query']);
    }

    $locked_type = '';
    if (!empty($request['mop_locked_type'])) {
        $locked_type = sanitize_key($request['mop_locked_type']);
    } elseif (!empty($request['mop_locked_pdfs']) && $request['mop_locked_pdfs'] !== 'false') {
        $locked_type = 'pdfs';
    }

    if (!in_array($locked_type, ['all', 'pdfs', 'images', 'documents', 'archives'], true)) {
        return $query;
    }

    if ($locked_type === 'pdfs') {
        $query['post_mime_type'] = 'application/pdf';
    } elseif ($locked_type === 'images') {
        $query['post_mime_type'] = mop_supported_image_mime_types();
    } elseif ($locked_type === 'documents') {
        $query['post_mime_type'] = mop_supported_document_mime_types();
    } elseif ($locked_type === 'archives') {
        $query['post_mime_type'] = mop_supported_archive_mime_types();
    } else {
        $query['post_mime_type'] = mop_supported_mime_types();
    }

    if (empty($query['meta_query']) || !is_array($query['meta_query'])) {
        $query['meta_query'] = [];
    }
    $query['meta_query'][] = [
        'key'   => MOP_META_PROTECT,
        'value' => '1',
    ];

    return $query;
});

/** Enqueue minimal CSS and resilient JS for lock overlay + media filters */
add_action('admin_enqueue_scripts', function ($hook) {
    $allowed_hooks = ['upload.php','media.php','post.php','post-new.php','site-editor.php','widgets.php','customize.php'];
    if (!in_array($hook, $allowed_hooks, true)) return;

    wp_enqueue_style('dashicons');

    $css = <<<CSS
.attachments .attachment { position: relative; }
.mop-lock {
  position: absolute;
  top: 6px; right: 6px;
  z-index: 1000;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px; height: 22px;
  border-radius: 50%;
  background: rgba(0,0,0,.55);
  color: #fff;
}
.mop-lock .dashicons { width: 16px; height: 16px; font-size: 16px; line-height: 1; }
CSS;
    wp_add_inline_style('dashicons', $css);

    $js = <<<JS
(function(){
  function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function installLockedMediaFilters(){
    if (!window.wp || !wp.media || !wp.media.view || !wp.media.view.AttachmentFilters || !wp.media.view.AttachmentFilters.All) return;
    var Filters = wp.media.view.AttachmentFilters.All;
    if (Filters.prototype.mopLockedMediaFiltersInstalled) return;
    var originalCreateFilters = Filters.prototype.createFilters;
    Filters.prototype.createFilters = function(){
      originalCreateFilters.apply(this, arguments);
      Object.keys(this.filters || {}).forEach(function(key){
        this.filters[key].props = this.filters[key].props || {};
        if (typeof this.filters[key].props.mop_locked_pdfs === 'undefined') {
          this.filters[key].props.mop_locked_pdfs = false;
        }
        if (typeof this.filters[key].props.mop_locked_type === 'undefined') {
          this.filters[key].props.mop_locked_type = '';
        }
      }, this);
      this.filters.mopLockedFiles = {
        text: 'Locked Media',
        props: {
          status: null,
          type: null,
          uploadedTo: null,
          mop_locked_pdfs: false,
          mop_locked_type: 'all'
        },
        priority: 84
      };
      this.filters.mopLockedPdfs = {
        text: 'Locked PDFs',
        props: {
          status: null,
          type: null,
          uploadedTo: null,
          mop_locked_pdfs: true,
          mop_locked_type: 'pdfs'
        },
        priority: 85
      };
      this.filters.mopLockedImages = {
        text: 'Locked Images',
        props: {
          status: null,
          type: null,
          uploadedTo: null,
          mop_locked_pdfs: false,
          mop_locked_type: 'images'
        },
        priority: 86
      };
      this.filters.mopLockedDocuments = {
        text: 'Locked Documents',
        props: {
          status: null,
          type: null,
          uploadedTo: null,
          mop_locked_pdfs: false,
          mop_locked_type: 'documents'
        },
        priority: 87
      };
      this.filters.mopLockedArchives = {
        text: 'Locked Archives',
        props: {
          status: null,
          type: null,
          uploadedTo: null,
          mop_locked_pdfs: false,
          mop_locked_type: 'archives'
        },
        priority: 88
      };
    };
    Filters.prototype.mopLockedMediaFiltersInstalled = true;
  }
  function isSupportedModel(m){
    if (!m) return false;
    var type = String(m.get('type')||'').toLowerCase();
    var subtype = String(m.get('subtype')||'').toLowerCase();
    var fname   = String(m.get('filename')||'');
    var mime = String(m.get('mime')||'').toLowerCase();
    return (type === 'image') || (mime.indexOf('image/') === 0) || (subtype === 'pdf') || (/\\.(pdf|docx?|xlsx?|pptx?|odt|ods|odp|rtf|txt|csv|zip|gz|gzip|tar|tgz|rar|7z)$/i.test(fname));
  }
  function isProtectedModel(m){
    return !!(m && m.get && (m.get('mopProtected') || m.get('ll706Protected') || m.get('mop_protected')));
  }
  function getAttachmentModelByEl(tile){
    var id = tile && tile.getAttribute && tile.getAttribute('data-id');
    if (!id || !window.wp || !wp.media || !wp.media.attachment) return null;
    try { return wp.media.attachment(parseInt(id,10)); } catch(e){ return null; }
  }
  function paintTile(tile){
    if (!tile) return;
    var old = tile.querySelector('.mop-lock'); if (old) old.remove();
    var model = getAttachmentModelByEl(tile);
    if (!model) return;
    if (typeof model.get !== 'function' || !model.get('filename')) {
      if (model.once) model.once('change', function(){ paintTile(tile); });
      if (model.fetch && model.fetch().always) model.fetch().always(function(){ paintTile(tile); });
      return;
    }
    var show = isSupportedModel(model) && isProtectedModel(model);
    if (show){
      var span = document.createElement('span');
      span.className = 'mop-lock';
      span.title = 'Protected';
      span.innerHTML = '<span class="dashicons dashicons-lock"></span>';
      tile.appendChild(span);
    }
  }
  function paintAll(root){
    if (!root) root = document;
    var tiles = root.querySelectorAll('.attachments .attachment');
    (tiles.forEach ? tiles : Array.prototype.slice.call(tiles)).forEach(paintTile);
  }
  function attachObserver(){
    var root = document.body;
    if (!root) return;
    var mo = new MutationObserver(function(muts){
      muts.forEach(function(m){
        if (m.type === 'childList'){
          (m.addedNodes || []).forEach(function(n){
            if (!n.querySelectorAll) return;
            var tiles = (n.matches && n.matches('.attachments .attachment')) ? [n] : n.querySelectorAll('.attachments .attachment');
            (tiles.forEach ? tiles : Array.prototype.slice.call(tiles)).forEach(paintTile);
          });
        }
      });
    });
    mo.observe(root, {childList:true, subtree:true});
  }
  installLockedMediaFilters();
  ready(function(){ installLockedMediaFilters(); paintAll(document); attachObserver(); });
})();
JS;
    wp_add_inline_script('media-views', $js, 'after');
});

/** -----------------------------------------------------------------------
 * Helpers
 * --------------------------------------------------------------------- */
function mop_get_all_roles(): array {
    $roles = [];
    foreach (wp_roles()->roles as $slug => $role) {
        $roles[$slug] = $role['name'];
    }
    return $roles;
}

function mop_get_allowed_roles(int $post_id): array {
    $val = get_post_meta($post_id, MOP_META_ROLES, true);
    return is_array($val) ? $val : [];
}

function mop_redirect_to_custom_url(string $url): void {
    $url = wp_sanitize_redirect(esc_url_raw($url));
    if ($url === '') {
        return;
    }

    wp_redirect($url);
    exit;
}

function mop_supported_image_mime_types(): array {
    return [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/bmp',
        'image/tiff',
        'image/x-icon',
        'image/heic',
        'image/heif',
    ];
}

function mop_supported_document_mime_types(): array {
    return [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'application/rtf',
        'text/rtf',
        'text/plain',
        'text/csv',
        'application/csv',
    ];
}

function mop_supported_archive_mime_types(): array {
    return [
        'application/zip',
        'application/x-zip-compressed',
        'application/x-tar',
        'application/gzip',
        'application/x-gzip',
        'application/x-rar-compressed',
        'application/vnd.rar',
        'application/x-7z-compressed',
    ];
}

function mop_supported_mime_types(): array {
    return array_merge(
        ['application/pdf'],
        mop_supported_image_mime_types(),
        mop_supported_document_mime_types(),
        mop_supported_archive_mime_types()
    );
}

function mop_unlock_attachment(int $attachment_id) {
    if (!$attachment_id || !mop_is_supported_attachment($attachment_id)) {
        return new WP_Error('mop_invalid_attachment', 'The selected attachment is not a supported media file.');
    }

    $result = mop_move_file_protection_state($attachment_id, false);
    if (is_wp_error($result)) {
        return $result;
    }

    update_post_meta($attachment_id, MOP_META_PROTECT, '0');
    return true;
}

function mop_user_can_view(int $post_id): bool {
    if (current_user_can('manage_options')) return true; // admins bypass
    $allowed = mop_get_allowed_roles($post_id);
    if (empty($allowed)) return false;
    $user = wp_get_current_user();
    if (!$user || empty($user->roles)) return false;
    return (bool) array_intersect($user->roles, $allowed); // OR logic across selected roles
}

function mop_is_pdf(int $post_id): bool {
    $mime = (string) get_post_mime_type($post_id);
    if ($mime && stripos($mime, 'pdf') !== false) return true;
    $p = get_attached_file($post_id, true);
    return $p && preg_match('/\.pdf$/i', $p);
}

function mop_is_image(int $post_id): bool {
    $mime = (string) get_post_mime_type($post_id);
    if ($mime && stripos($mime, 'image/') === 0) return true;
    $p = get_attached_file($post_id, true);
    return $p && preg_match('/\.(jpe?g|png|gif|webp|avif|bmp|tiff?|ico|heic|heif)$/i', $p);
}

function mop_is_document(int $post_id): bool {
    $mime = (string) get_post_mime_type($post_id);
    if ($mime && in_array($mime, mop_supported_document_mime_types(), true)) return true;
    $p = get_attached_file($post_id, true);
    return $p && preg_match('/\.(docx?|xlsx?|pptx?|odt|ods|odp|rtf|txt|csv)$/i', $p);
}

function mop_is_archive(int $post_id): bool {
    $mime = (string) get_post_mime_type($post_id);
    if ($mime && in_array($mime, mop_supported_archive_mime_types(), true)) return true;
    $p = get_attached_file($post_id, true);
    return $p && preg_match('/\.(zip|gz|gzip|tar|tgz|rar|7z)$/i', $p);
}

function mop_is_supported_attachment(int $post_id): bool {
    return mop_is_pdf($post_id) || mop_is_image($post_id) || mop_is_document($post_id) || mop_is_archive($post_id);
}

function mop_get_attachment_kind(int $post_id): string {
    if (mop_is_pdf($post_id)) return 'PDF';
    if (mop_is_image($post_id)) return 'Image';
    if (mop_is_document($post_id)) return 'Document';
    if (mop_is_archive($post_id)) return 'Archive';
    return 'File';
}

function mop_ensure_htaccess(): bool {
    if (!file_exists(MOP_DIR)) return false;
    $ht = MOP_DIR . '/.htaccess';
    if (is_link($ht)) return false;

    // Put this first so an older rewrite rule cannot stop processing before the deny.
    $guard = "# BEGIN Members-Only Media direct access guard\nRewriteEngine On\nRewriteRule .* - [L,F]\n# END Members-Only Media direct access guard";
    $existing = '';
    if (file_exists($ht)) {
        $existing = @file_get_contents($ht);
        if ($existing === false) return false;
        if (strpos($existing, $guard) === 0) return true;
    } else {
        $existing = <<<HTACC
# Members-Only Media
Options -Indexes

# Apache 2.4+ / LiteSpeed
<IfModule mod_authz_core.c>
  Require all denied
</IfModule>

# Apache 2.2 fallback
<IfModule !mod_authz_core.c>
  Deny from all
</IfModule>
HTACC;
    }

    $rules = $guard . "\n\n" . ltrim($existing, "\r\n");
    $temporary = @tempnam(MOP_DIR, '.mop-guard-');
    if ($temporary === false) return false;
    $permissions = file_exists($ht) ? (@fileperms($ht) & 0777) : 0644;
    if (@file_put_contents($temporary, $rules) === false ||
        !@chmod($temporary, $permissions ?: 0644) ||
        !@rename($temporary, $ht)) {
        @unlink($temporary);
        return false;
    }
    $written = @file_get_contents($ht);
    return $written !== false && strpos($written, $guard) === 0;
}

function mop_move_file_protection_state(int $attachment_id, bool $protect) {
    $file = get_attached_file($attachment_id, true);
    if (!$file || !file_exists($file)) {
        return new WP_Error('mop_file_missing', 'The file could not be found on disk.');
    }
    if (!mop_is_supported_attachment($attachment_id)) {
        return new WP_Error('mop_unsupported_attachment', 'Only PDF, image, document, and archive attachments can be secured by this plugin.');
    }

    return $protect
        ? mop_protect_attachment_files($attachment_id, wp_normalize_path($file))
        : mop_unprotect_attachment_files($attachment_id, wp_normalize_path($file));
}

function mop_upload_relative_path(string $path): string {
    $uploads = wp_get_upload_dir();
    $base = trailingslashit(wp_normalize_path((string) ($uploads['basedir'] ?? '')));
    $path = wp_normalize_path($path);
    return $base !== '/' && strpos($path, $base) === 0 ? substr($path, strlen($base)) : '';
}

function mop_valid_original_path(string $relative, string $filename): bool {
    $relative = str_replace('\\', '/', $relative);
    return $relative !== ''
        && $relative[0] !== '/'
        && strpos($relative, 'members-only/') !== 0
        && !preg_match('~(^|/)\.\.?(/|$)~', $relative)
        && wp_basename($relative) === $filename;
}

function mop_original_upload_path(int $attachment_id, string $file, string $filename): string {
    $candidates = [];
    if (!mop_path_is_in_dir($file, MOP_DIR)) {
        $candidates[] = mop_upload_relative_path($file);
    }
    $candidates[] = get_post_meta($attachment_id, MOP_META_ORIG_PATH, true);
    $metadata = wp_get_attachment_metadata($attachment_id);
    if (is_array($metadata) && !empty($metadata['file'])) {
        $candidates[] = $metadata['file'];
    }
    $uploads = wp_get_upload_dir();
    $guid_path = (string) parse_url((string) get_the_guid($attachment_id), PHP_URL_PATH);
    $uploads_url_path = (string) parse_url((string) ($uploads['baseurl'] ?? ''), PHP_URL_PATH);
    $uploads_url_path = trailingslashit($uploads_url_path);
    if ($uploads_url_path !== '/' && strpos($guid_path, $uploads_url_path) === 0) {
        $candidates[] = rawurldecode(substr($guid_path, strlen($uploads_url_path)));
    }
    foreach ($candidates as $candidate) {
        $candidate = (string) $candidate;
        if (mop_valid_original_path($candidate, $filename)) return $candidate;
    }

    // Legacy protected files may not have their original location recorded.
    return date('Y/m/', (int) (filemtime($file) ?: time())) . $filename;
}

function mop_random_protected_path(string $source) {
    $extension = pathinfo($source, PATHINFO_EXTENSION);
    try {
        do {
            $name = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
            $target = wp_normalize_path(trailingslashit(MOP_DIR) . $name);
        } while (file_exists($target));
    } catch (Throwable $error) {
        return new WP_Error('mop_random_name_failed', 'A private filename could not be generated.');
    }
    return $target;
}

function mop_is_opaque_protected_name(string $path): bool {
    return (bool) preg_match('/^[a-f0-9]{32}(?:\.[^.]+)?$/', wp_basename($path));
}

function mop_protect_attachment_files(int $attachment_id, string $file) {
    if (!file_exists(MOP_DIR) && !wp_mkdir_p(MOP_DIR)) {
        return new WP_Error('mop_protected_dir_failed', 'The protected uploads folder could not be created.');
    }

    if (!mop_ensure_htaccess()) {
        return new WP_Error('mop_htaccess_failed', 'The protected folder was created, but its .htaccess protection file could not be written.');
    }

    $was_protected = mop_path_is_in_dir($file, MOP_DIR);
    $original_name = $was_protected
        ? (string) get_post_meta($attachment_id, MOP_META_ORIG, true)
        : wp_basename($file);
    if ($original_name === '') {
        $original_name = wp_basename($file);
    }
    $original_path = mop_original_upload_path($attachment_id, $file, $original_name);

    $sidecars = mop_get_attachment_sidecar_files($attachment_id, $file);
    $old_sidecar_map = get_post_meta($attachment_id, MOP_META_SIDECARS, true);
    if (!is_array($old_sidecar_map)) $old_sidecar_map = [];
    foreach ($old_sidecar_map as $protected_name => $original_sidecar_name) {
        $path = wp_normalize_path(trailingslashit(MOP_DIR) . wp_basename((string) $protected_name));
        if (file_exists($path)) $sidecars[] = $path;
    }
    $sidecars = array_values(array_unique($sidecars));
    $moved = [];
    $sidecar_map = [];
    $protected_file = $file;

    if (!$was_protected || !mop_is_opaque_protected_name($file)) {
        $protected_file = mop_random_protected_path($file);
        if (is_wp_error($protected_file)) return $protected_file;
        $result = mop_rename_with_rollback($file, $protected_file, $moved);
        if (is_wp_error($result)) {
            return $result;
        }
    }

    foreach ($sidecars as $sidecar) {
        $sidecar_name = wp_basename($sidecar);
        $original_sidecar_name = isset($old_sidecar_map[$sidecar_name])
            ? wp_basename((string) $old_sidecar_map[$sidecar_name])
            : $sidecar_name;
        $target = $sidecar;
        if (!mop_path_is_in_dir($sidecar, MOP_DIR) || !mop_is_opaque_protected_name($sidecar)) {
            $target = mop_random_protected_path($sidecar);
            if (is_wp_error($target)) {
                mop_rollback_moves($moved);
                return $target;
            }
            $result = mop_rename_with_rollback($sidecar, $target, $moved);
            if (is_wp_error($result)) {
                mop_rollback_moves($moved);
                return new WP_Error('mop_preview_move_failed', 'The file could not be secured because a generated preview or image size could not be moved.');
            }
        }
        $sidecar_map[wp_basename($target)] = $original_sidecar_name;
    }

    update_attached_file($attachment_id, $protected_file);
    if (wp_normalize_path((string) get_attached_file($attachment_id, true)) !== $protected_file) {
        mop_rollback_moves($moved);
        update_attached_file($attachment_id, $file);
        return new WP_Error('mop_attachment_path_failed', 'The protected file path could not be saved.');
    }

    $previous_name = get_post_meta($attachment_id, MOP_META_ORIG, true);
    $previous_path = get_post_meta($attachment_id, MOP_META_ORIG_PATH, true);
    update_post_meta($attachment_id, MOP_META_ORIG, $original_name);
    update_post_meta($attachment_id, MOP_META_ORIG_PATH, $original_path);
    update_post_meta($attachment_id, MOP_META_SIDECARS, $sidecar_map);
    if (get_post_meta($attachment_id, MOP_META_ORIG, true) !== $original_name ||
        get_post_meta($attachment_id, MOP_META_ORIG_PATH, true) !== $original_path ||
        get_post_meta($attachment_id, MOP_META_SIDECARS, true) !== $sidecar_map) {
        mop_rollback_moves($moved);
        update_attached_file($attachment_id, $file);
        update_post_meta($attachment_id, MOP_META_ORIG, $previous_name);
        update_post_meta($attachment_id, MOP_META_ORIG_PATH, $previous_path);
        update_post_meta($attachment_id, MOP_META_SIDECARS, $old_sidecar_map);
        return new WP_Error('mop_protection_metadata_failed', 'The private filename details could not be saved.');
    }
    return true;
}

function mop_unprotect_attachment_files(int $attachment_id, string $file) {
    if (!mop_path_is_in_dir($file, MOP_DIR)) {
        delete_post_meta($attachment_id, MOP_META_SIDECARS);
        return true;
    }

    $uploads = wp_get_upload_dir();
    $uploads_base = wp_normalize_path($uploads['basedir']);
    $original_name = wp_basename((string) get_post_meta($attachment_id, MOP_META_ORIG, true));
    if ($original_name === '') $original_name = wp_basename($file);
    $relative_path = mop_original_upload_path($attachment_id, $file, $original_name);
    $target_path = wp_normalize_path(trailingslashit($uploads_base) . $relative_path);
    $target_dir = dirname($target_path);
    if (!file_exists($target_dir) && !wp_mkdir_p($target_dir)) {
        return new WP_Error('mop_upload_dir_failed', 'The regular uploads folder could not be created.');
    }
    if (file_exists($target_path) || is_link($target_path)) {
        return new WP_Error('mop_original_path_occupied', 'The original upload path is already occupied. Move that file before unlocking this one.');
    }

    $sidecar_map = get_post_meta($attachment_id, MOP_META_SIDECARS, true);
    if (!is_array($sidecar_map)) {
        $sidecar_map = [];
    }

    if (empty($sidecar_map)) {
        foreach (mop_get_attachment_sidecar_files($attachment_id, $file) as $sidecar) {
            if (mop_path_is_in_dir($sidecar, MOP_DIR)) {
                $sidecar_map[wp_basename($sidecar)] = wp_basename($sidecar);
            }
        }
    }

    $restore = [];
    $reserved = [$target_path => true];
    foreach ($sidecar_map as $protected_basename => $original_basename) {
        $source = wp_normalize_path(trailingslashit(MOP_DIR) . wp_basename((string) $protected_basename));
        if (!file_exists($source)) {
            continue;
        }
        if (!mop_path_is_in_dir($source, MOP_DIR)) {
            return new WP_Error('mop_preview_path_invalid', 'A protected preview path is invalid.');
        }
        $target = wp_normalize_path(trailingslashit($target_dir) . wp_basename((string) $original_basename));
        if (file_exists($target) || is_link($target) || isset($reserved[$target])) {
            return new WP_Error('mop_preview_path_occupied', 'An original preview path is already occupied. Move that file before unlocking this one.');
        }
        $restore[$source] = $target;
        $reserved[$target] = true;
    }

    $moved = [];
    $result = mop_rename_with_rollback($file, $target_path, $moved);
    if (is_wp_error($result)) {
        return new WP_Error('mop_unlock_move_failed', 'The file could not be moved back into the regular uploads folder.');
    }
    foreach ($restore as $source => $target) {
        $result = mop_rename_with_rollback($source, $target, $moved);
        if (is_wp_error($result)) {
            mop_rollback_moves($moved);
            return new WP_Error('mop_preview_restore_failed', 'The file could not be unlocked because a generated preview or image size could not be restored.');
        }
    }

    update_attached_file($attachment_id, $target_path);
    if (wp_normalize_path((string) get_attached_file($attachment_id, true)) !== $target_path) {
        mop_rollback_moves($moved);
        update_attached_file($attachment_id, $file);
        return new WP_Error('mop_attachment_path_failed', 'The original file path could not be restored in WordPress.');
    }
    $metadata = wp_get_attachment_metadata($attachment_id, true);
    if (is_array($metadata) && ($metadata['file'] ?? '') !== $relative_path) {
        $updated_metadata = $metadata;
        $updated_metadata['file'] = $relative_path;
        wp_update_attachment_metadata($attachment_id, $updated_metadata);
        $saved_metadata = wp_get_attachment_metadata($attachment_id, true);
        if (!is_array($saved_metadata) || ($saved_metadata['file'] ?? '') !== $relative_path) {
            mop_rollback_moves($moved);
            update_attached_file($attachment_id, $file);
            wp_update_attachment_metadata($attachment_id, $metadata);
            return new WP_Error('mop_attachment_metadata_failed', 'The original media metadata could not be restored.');
        }
    }
    delete_post_meta($attachment_id, MOP_META_SIDECARS);
    return true;
}

function mop_get_attachment_sidecar_files(int $attachment_id, string $main_file): array {
    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!is_array($metadata)) {
        return [];
    }

    $candidate_names = [];
    if (!empty($metadata['thumb'])) {
        $candidate_names[] = $metadata['thumb'];
    }
    if (!empty($metadata['original_image'])) {
        $candidate_names[] = $metadata['original_image'];
    }
    if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
        foreach ($metadata['sizes'] as $size) {
            if (!empty($size['file'])) {
                $candidate_names[] = $size['file'];
            }
        }
    }

    $uploads = wp_get_upload_dir();
    $dirs = [dirname($main_file)];
    if (!empty($metadata['file'])) {
        $dirs[] = wp_normalize_path(trailingslashit($uploads['basedir']) . dirname($metadata['file']));
    }

    $main_real = realpath($main_file);
    $files = [];
    foreach (array_unique(array_filter($candidate_names)) as $name) {
        foreach (array_unique($dirs) as $dir) {
            $candidate = wp_normalize_path(trailingslashit($dir) . wp_basename($name));
            if (!file_exists($candidate)) {
                continue;
            }

            $candidate_real = realpath($candidate);
            if ($candidate_real && $main_real && $candidate_real === $main_real) {
                continue;
            }

            $files[$candidate] = $candidate;
        }
    }

    return array_values($files);
}

function mop_rename_with_rollback(string $from, string $to, array &$moved) {
    if (!@rename($from, $to)) {
        return new WP_Error('mop_file_move_failed', 'A file move failed. Check uploads folder permissions.');
    }

    $moved[] = [
        'from' => $from,
        'to'   => $to,
    ];

    return true;
}

function mop_rollback_moves(array $moved): void {
    foreach (array_reverse($moved) as $move) {
        if (!empty($move['to']) && !empty($move['from']) && file_exists($move['to']) && !file_exists($move['from'])) {
            @rename($move['to'], $move['from']);
        }
    }
}

function mop_path_is_in_dir(string $path, string $dir): bool {
    $real_path = realpath($path);
    $real_dir = realpath($dir);
    if (!$real_path || !$real_dir) {
        return false;
    }

    $real_path = trailingslashit(wp_normalize_path($real_path));
    $real_dir = trailingslashit(wp_normalize_path($real_dir));

    return strpos($real_path, $real_dir) === 0;
}

function mop_resolve_requested_protected_file(int $attachment_id, string $main_file, string $requested_name) {
    $requested = wp_basename(rawurldecode($requested_name));
    $original = wp_basename((string) get_post_meta($attachment_id, MOP_META_ORIG, true));
    $main_basename = wp_basename($main_file);

    if ($requested === '' || $requested === $original || $requested === $main_basename) {
        return [
            'path' => $main_file,
            'name' => $original ?: $main_basename,
        ];
    }

    $sidecar_map = get_post_meta($attachment_id, MOP_META_SIDECARS, true);
    if (!is_array($sidecar_map)) {
        $sidecar_map = [];
    }

    foreach ($sidecar_map as $protected_basename => $original_basename) {
        $protected_basename = wp_basename((string) $protected_basename);
        $original_basename = wp_basename((string) $original_basename);
        if ($requested !== $protected_basename && $requested !== $original_basename) {
            continue;
        }

        $path = wp_normalize_path(trailingslashit(MOP_DIR) . $protected_basename);
        if (file_exists($path) && mop_path_is_in_dir($path, MOP_DIR)) {
            return [
                'path' => $path,
                'name' => $original_basename ?: $protected_basename,
            ];
        }
    }

    return new WP_Error('mop_missing_requested_file', 'The requested protected file could not be found.');
}

function mop_stream_file(string $filepath, string $display_name, int $attachment_id): void {
    nocache_headers();
    $filetype = wp_check_filetype($filepath);
    $mime = $filetype['type'] ?: get_post_mime_type($attachment_id);
    if (!$mime) {
        $mime = 'application/octet-stream';
    }

    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . mop_suggest_filename($display_name, $filepath) . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Accept-Ranges: none');

    $last_modified = gmdate('D, d M Y H:i:s', filemtime($filepath)) . ' GMT';
    $etag = '"' . md5($last_modified . '|' . basename($filepath)) . '"';
    header('Last-Modified: ' . $last_modified);
    header('ETag: ' . $etag);

    if (
        (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) ||
        (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && trim($_SERVER['HTTP_IF_MODIFIED_SINCE']) === $last_modified)
    ) {
        status_header(304);
        exit;
    }

    status_header(200);
    readfile($filepath);
}

function mop_suggest_filename(string $name, string $filepath): string {
    $n = trim($name);
    if ($n === '') $n = wp_basename($filepath);
    if ($n === '') $n = 'file';
    $n = str_replace(["\r","\n","\""], ['','',''], $n);
    return $n;
}

function mop_404(): void {
    status_header(404);
    nocache_headers();
    exit('Not Found');
}
