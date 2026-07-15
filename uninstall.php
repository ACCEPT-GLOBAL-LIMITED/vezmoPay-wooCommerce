<?php
/**
 * Uninstall cleanup: remove plugin options and cached tokens.
 *
 * Order meta (_vezmopay_*) is intentionally preserved — it is part of the store's
 * financial audit trail.
 *
 * @package VezmoPay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_vezmopay_settings' );

// Remove cached access tokens (transients are prefixed vezmopay_token_).
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall-time cleanup of dynamically named transients.
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_vezmopay\_token\_%'
	    OR option_name LIKE '\_transient\_timeout\_vezmopay\_token\_%'"
);
