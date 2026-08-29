<?php
/**
 * Hidden admin screen: edit stored website ID and secret WordPress options only.
 * Does not call Supabase or any remote API — only updates options in this site’s database.
 *
 * @package LovedByAI
 */

defined('ABSPATH') || exit;

if (!defined('GEOGURU_MANUAL_CREDENTIALS_PAGE_SLUG')) {
    define('GEOGURU_MANUAL_CREDENTIALS_PAGE_SLUG', 'ai-optimization-manual-credentials');
}

/**
 * Render the hidden manual credentials page (admin.php?page=…).
 */
function geoguru_render_manual_credentials_page() {
    geoguru_prune_expired_iframe_tokens();

    if (!current_user_can('manage_options')) {
        wp_die(esc_html(__('You do not have sufficient permissions to access this page.', 'lovedbyai-seo-for-llms-and-ai-search')));
    }

    $saved = false;
    if (isset($_POST['geoguru_manual_credentials_save'])) {
        if (!isset($_POST['geoguru_manual_credentials_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['geoguru_manual_credentials_nonce'])), 'geoguru_manual_credentials_save')) {
            wp_die(esc_html(__('Security check failed.', 'lovedbyai-seo-for-llms-and-ai-search')));
        }

        $site_id = isset($_POST['geoguru_site_id']) ? sanitize_text_field(wp_unslash($_POST['geoguru_site_id'])) : '';
        update_option('geoguru_site_id', $site_id);

        $clear_secret = !empty($_POST['geoguru_clear_secret']);
        if ($clear_secret) {
            delete_option('geoguru_secret_token');
        } elseif (isset($_POST['geoguru_secret_token']) && $_POST['geoguru_secret_token'] !== '') {
            update_option('geoguru_secret_token', sanitize_text_field(wp_unslash($_POST['geoguru_secret_token'])));
        }

        $saved = true;
    }

    $site_id = get_option('geoguru_site_id', '');
    $has_secret = (get_option('geoguru_secret_token', '') !== '');
    $page_url = admin_url('admin.php?page=' . GEOGURU_MANUAL_CREDENTIALS_PAGE_SLUG);
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Manual website credentials (options only)', 'lovedbyai-seo-for-llms-and-ai-search'); ?></h1>

        <p class="description">
            <?php echo esc_html__('This page is not linked from the plugin menu. It only reads and writes the WordPress options geoguru_site_id and geoguru_secret_token on this site. Nothing is sent to the remote service or database from this form.', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
        </p>

        <?php if ($saved) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Options saved.', 'lovedbyai-seo-for-llms-and-ai-search'); ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url($page_url); ?>" style="max-width: 48rem; margin-top: 1.5em;">
            <?php wp_nonce_field('geoguru_manual_credentials_save', 'geoguru_manual_credentials_nonce'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="geoguru_site_id"><?php echo esc_html__('Website ID', 'lovedbyai-seo-for-llms-and-ai-search'); ?></label>
                    </th>
                    <td>
                        <input name="geoguru_site_id" id="geoguru_site_id" type="text" class="regular-text code" value="<?php echo esc_attr($site_id); ?>" autocomplete="off" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="geoguru_secret_token"><?php echo esc_html__('Website secret', 'lovedbyai-seo-for-llms-and-ai-search'); ?></label>
                    </th>
                    <td>
                        <input name="geoguru_secret_token" id="geoguru_secret_token" type="password" class="regular-text code" value="" autocomplete="new-password" />
                        <p class="description">
                            <?php
                            if ($has_secret) {
                                echo esc_html__('A secret is already stored. Leave this field empty to keep it unchanged.', 'lovedbyai-seo-for-llms-and-ai-search');
                            } else {
                                echo esc_html__('Optional until you paste a secret from your dashboard or onboarding flow.', 'lovedbyai-seo-for-llms-and-ai-search');
                            }
                            ?>
                        </p>
                        <p>
                            <label>
                                <input name="geoguru_clear_secret" type="checkbox" value="1" />
                                <?php echo esc_html__('Remove stored secret (delete option)', 'lovedbyai-seo-for-llms-and-ai-search'); ?>
                            </label>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save options', 'lovedbyai-seo-for-llms-and-ai-search'), 'primary', 'geoguru_manual_credentials_save'); ?>
        </form>
    </div>
    <?php
}
