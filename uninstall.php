<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

function cffc_purge_site_data(): void {
    global $wpdb;

    $known = [
        'fluent_cart_payment_settings_cashfree',
        'fluent_cart_payment_method_cashfree',
        'fluent_cart_gateway_settings_cashfree',
        'fluentcart_payment_settings_cashfree',
        'fct_payment_settings_cashfree',
        'cashfree_fluentcart_settings',
        'cffc_settings',
    ];
    foreach ($known as $opt) {
        delete_option($opt);
        delete_site_option($opt);
    }

    // Purge Cashfree credentials from FluentCart's fct_meta table (never touches orders/transactions)
    $fct_meta = $wpdb->prefix . 'fct_meta';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of gateway credentials.
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fct_meta)) === $fct_meta) {
        $table = esc_sql($fct_meta);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}`
                WHERE object_type = %s
                AND (
                    meta_key IN (%s, %s, %s, %s, %s, %s)
                    OR meta_key LIKE %s
                    OR meta_key LIKE %s
                )",
                'option',
                'fluent_cart_payment_settings_cashfree',
                'fluent_cart_payment_method_cashfree',
                'fluent_cart_gateway_settings_cashfree',
                'fluentcart_payment_settings_cashfree',
                'fct_payment_settings_cashfree',
                'cashfree',
                '%' . $wpdb->esc_like('cashfree') . '%',
                $wpdb->esc_like('cffc_') . '%'
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    // Clean cashfree key from FluentCart master option arrays
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of gateway keys from FluentCart options.
    $fc_options = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '%' . $wpdb->esc_like('fluent') . '%' . $wpdb->esc_like('cart') . '%',
            '%' . $wpdb->esc_like('fct_') . '%'
        )
    );
    if (is_array($fc_options)) {
        foreach ($fc_options as $opt) {
            $val = maybe_unserialize($opt->option_value);
            if (is_array($val) && isset($val['cashfree'])) {
                unset($val['cashfree']);
                update_option($opt->option_name, $val);
            }
        }
    }

    // Strictly scoped to cffc_* only (never wipes other Cashfree plugins on the same WP site)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of plugin options and transients.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('cffc_') . '%',
            $wpdb->esc_like('_transient_cffc_') . '%',
            $wpdb->esc_like('_transient_timeout_cffc_') . '%'
        )
    );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of plugin user meta.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like('cffc_') . '%'
        )
    );

    if (function_exists('wp_cache_flush')) wp_cache_flush();
}

cffc_purge_site_data();

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $cffc_blog_id) {
        switch_to_blog((int)$cffc_blog_id);
        cffc_purge_site_data();
        restore_current_blog();
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of multisite plugin meta and transients.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s",
            $wpdb->esc_like('cffc_') . '%',
            $wpdb->esc_like('_site_transient_cffc_') . '%',
            $wpdb->esc_like('_site_transient_timeout_cffc_') . '%'
        )
    );
}
