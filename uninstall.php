<?php
/**
 * Fired when the plugin is uninstalled (deleted) via WordPress Admin or WP-CLI.
 *
 * Leaves zero footprints on the merchant website by completely purging
 * all Cashfree settings, encrypted credentials, cached tokens, locks,
 * and webhook deduplication transients from wp_options and FluentCart's custom tables.
 *
 * If reinstalled in the future, all saved settings will be completely gone,
 * giving the merchant a 100% fresh installation.
 *
 * @package CashfreePaymentsFC
 */

// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

/**
 * Purge all Cashfree settings, options, and transients for a single site/blog.
 *
 * @param \wpdb $db WordPress database object.
 */
function cffc_purge_site_data($db) {
    // 1. Delete standard WordPress options using WP API
    $knownOptions = [
        'fluent_cart_payment_settings_cashfree',
        'fluent_cart_payment_method_cashfree',
        'fct_payment_settings_cashfree',
        'fluentcart_payment_settings_cashfree',
        'fluent_cart_gateway_settings_cashfree',
        'cashfree_fluentcart_settings',
        'cffc_settings',
    ];

    foreach ($knownOptions as $opt) {
        delete_option($opt);
        delete_site_option($opt);
    }

    // 2. Remove cashfree from FluentCart master settings arrays if present
    $masterOptions = [
        'fluent_cart_payment_settings',
        'fluent_cart_payment_methods',
        'fluent_cart_settings',
        'fluentcart_payment_settings',
        'fct_payment_settings',
    ];

    foreach ($masterOptions as $master) {
        $val = get_option($master);
        if (is_array($val) && isset($val['cashfree'])) {
            unset($val['cashfree']);
            update_option($master, $val);
        }
    }

    // 3. Comprehensive direct SQL purge from wp_options table
    $db->query(
        "DELETE FROM {$db->options} WHERE option_name LIKE '%cashfree%' OR option_name LIKE 'cffc_%' OR option_name LIKE '_cffc_%'"
    );

    // 4. Purge all transients and transient timeouts
    $db->query(
        "DELETE FROM {$db->options} WHERE option_name LIKE '_transient_%cashfree%' OR option_name LIKE '_transient_timeout_%cashfree%' OR option_name LIKE '_transient_cffc_%' OR option_name LIKE '_transient_timeout_cffc_%'"
    );

    // 5. Purge from FluentCart custom tables (fct_options, fct_meta, etc.) if present
    $fctTables = $db->get_col("SHOW TABLES LIKE '{$db->prefix}fct_%'");
    if (!empty($fctTables) && is_array($fctTables)) {
        foreach ($fctTables as $table) {
            $columns = $db->get_col("DESCRIBE {$table}", 0);
            if (!empty($columns) && is_array($columns)) {
                // Delete direct rows matching cashfree or cffc
                foreach (['option_name', 'option_key', 'setting_key', 'setting_name', 'meta_key', 'key', 'name', 'slug', 'route'] as $col) {
                    if (in_array($col, $columns, true)) {
                        $db->query("DELETE FROM {$table} WHERE {$col} LIKE '%cashfree%' OR {$col} LIKE 'cffc_%'");
                    }
                }

                // If table has key/name and value columns, check for master setting arrays
                $keyCol = null;
                $valCol = null;
                foreach (['option_name', 'key', 'name', 'setting_key'] as $k) {
                    if (in_array($k, $columns, true)) {
                        $keyCol = $k;
                        break;
                    }
                }
                foreach (['option_value', 'value', 'meta_value', 'setting_value'] as $v) {
                    if (in_array($v, $columns, true)) {
                        $valCol = $v;
                        break;
                    }
                }

                if ($keyCol && $valCol) {
                    $rows = $db->get_results("SELECT {$keyCol}, {$valCol} FROM {$table} WHERE {$valCol} LIKE '%cashfree%'");
                    if (!empty($rows)) {
                        foreach ($rows as $row) {
                            $rowKey = $row->$keyCol;
                            $rowVal = maybe_unserialize($row->$valCol);
                            if (is_array($rowVal) && isset($rowVal['cashfree'])) {
                                unset($rowVal['cashfree']);
                                $newVal = maybe_serialize($rowVal);
                                $db->update($table, [$valCol => $newVal], [$keyCol => $rowKey]);
                            }
                        }
                    }
                }
            }
        }
    }
}

// Execute cleanup on current site
cffc_purge_site_data($wpdb);

// Execute cleanup network-wide if running on WordPress Multisite
if (is_multisite()) {
    $blogIds = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
    if (!empty($blogIds) && is_array($blogIds)) {
        foreach ($blogIds as $bId) {
            switch_to_blog((int) $bId);
            cffc_purge_site_data($wpdb);
            restore_current_blog();
        }
    }

    // Also clean any network-wide sitemeta
    $wpdb->query(
        "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '%cashfree%' OR meta_key LIKE 'cffc_%'"
    );
}

// Flush object cache (Redis, Memcached, APCu, etc.) if active
if (function_exists('wp_cache_flush')) {
    wp_cache_flush();
}
