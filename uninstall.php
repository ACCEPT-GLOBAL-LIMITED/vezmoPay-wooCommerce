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

// Remove EVERY transient this plugin creates, not just the access tokens: the
// release lookup, the trusted-origin and capability answers, the account panel,
// the connect state nonces and the webhook throttles were all left behind.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall-time cleanup of dynamically named transients.
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_vezmopay\_%'
	    OR option_name LIKE '\_transient\_timeout\_vezmopay\_%'"
);

// Reconciliation locks and webhook event claims are plain options.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall-time cleanup of dynamically named options.
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'vezmopay\_recon\_%'
	    OR option_name LIKE 'vezmopay\_evt\_%'"
);
