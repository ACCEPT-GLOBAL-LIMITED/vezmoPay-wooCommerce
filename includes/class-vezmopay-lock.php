<?php
/**
 * A mutex two PHP workers can actually share.
 *
 * WordPress offers no cross-process lock. add_option() looks like one and is
 * not: it calls get_option() first — through the object cache — and inserts
 * only if that came back empty, so two workers (or one worker and a warm Redis)
 * can both read "absent" and both proceed. The plugin had two places built on
 * that mistaken assumption: the reconcile lock, which decides whether an order
 * may be completed twice, and the webhook event claim, which decides whether a
 * delivery is a duplicate.
 *
 * What does work is the options table's own unique index on option_name:
 * INSERT IGNORE cannot insert a duplicate, so exactly one caller sees a row
 * affected. Everything here is built on that.
 *
 * A claim carries a TOKEN (`<uuid>|<unix time>`), and that matters as much as
 * the atomicity: a release that deletes by NAME will happily delete a lock
 * somebody else now holds — which is how a webhook delivery that had been told
 * "busy" could free the claim of the delivery that was doing the work, letting
 * the platform's retry drive the whole reconcile a second time.
 *
 * @package VezmoPay
 */

namespace VezmoPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * One claim on one named lock.
 */
final class Lock {

	/**
	 * Option name this claim is held under.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * The token that makes this claim ours.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Whether this claim was taken over from an abandoned one.
	 *
	 * @var bool
	 */
	private $stolen;

	/**
	 * Hold a claim on an option-backed lock.
	 *
	 * @param string $name   Option name.
	 * @param string $token  Claim token.
	 * @param bool   $stolen Whether an abandoned claim was broken to get it.
	 */
	private function __construct( $name, $token, $stolen ) {
		$this->name   = $name;
		$this->token  = $token;
		$this->stolen = $stolen;
	}

	/**
	 * Take the lock, or return null because someone else holds it.
	 *
	 * @param string $name Option name (include everything that makes it unique).
	 * @param int    $ttl  Seconds after which an unreleased claim may be broken.
	 * @return Lock|null
	 */
	public static function claim( $name, $ttl ) {
		$token = wp_generate_uuid4();

		if ( self::insert( $name, $token ) ) {
			return new self( $name, $token, false );
		}

		$held = self::read( $name );
		if ( '' === $held ) {
			// The row vanished between the failed insert and this read — the
			// holder released it. One more attempt rather than reporting busy
			// when the lock is free.
			if ( self::insert( $name, $token ) ) {
				return new self( $name, $token, false );
			}
			return null;
		}

		if ( ! self::is_stale( self::claimed_at( $held ), time(), $ttl ) ) {
			return null;
		}

		// Compare-and-swap against the exact value we judged stale, so when two
		// passes read the same abandoned claim only one of them wins.
		if ( ! self::swap( $name, $held, $token ) ) {
			return null;
		}
		return new self( $name, $token, true );
	}

	/**
	 * Whether this claim was taken over from one that was abandoned.
	 *
	 * @return bool
	 */
	public function was_stolen() {
		return $this->stolen;
	}

	/**
	 * Release — ours only. A claim we no longer hold is left alone.
	 */
	public function release() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see the class docblock: the unique index is the mutex, and a cached read cannot decide who owns it.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
				$this->name,
				$wpdb->esc_like( $this->token . '|' ) . '%'
			)
		);
		self::forget( $this->name );
	}

	/**
	 * Drop claims left behind by deliveries that are long finished.
	 *
	 * Event claims are deliberately KEPT for their TTL — that is what makes a
	 * replayed delivery a duplicate — so nothing removes them at the end of a
	 * request, and a busy store accumulated one options row per webhook event
	 * for the life of the install. Bounded, so the five-minute cron can call it
	 * without ever becoming the slow part of that job.
	 *
	 * @param string $prefix Option-name prefix, e.g. 'vezmopay_evt_'.
	 * @param int    $ttl    Age in seconds beyond which a claim protects nothing.
	 * @param int    $limit  Most rows to delete in one pass.
	 * @return int Rows deleted.
	 */
	public static function sweep( $prefix, $ttl, $limit = 200 ) {
		global $wpdb;
		$cutoff = time() - (int) $ttl;
		// The timestamp is the tail of "<uuid>|<unix time>". A row written by an
		// older version of the plugin holds a bare timestamp, which this reads
		// just as well.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- bounded maintenance delete; every value is prepared.
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				   AND CAST( SUBSTRING_INDEX( option_value, '|', -1 ) AS UNSIGNED ) < %d
				 LIMIT %d",
				$wpdb->esc_like( $prefix ) . '%',
				$cutoff,
				(int) $limit
			)
		);
		if ( $deleted > 0 ) {
			wp_cache_delete( 'notoptions', 'options' );
		}
		return $deleted;
	}

	/**
	 * Whether a claim made at `$since` may be broken at `$now`.
	 *
	 * A claim with no readable timestamp is stale by definition: it cannot be
	 * aged, and a claim that can never be aged is exactly the one that wedges a
	 * lock for good. A timestamp in the future (a clock correction between the
	 * claim and this read) is treated as live rather than immortal.
	 *
	 * Pure, so the decision is testable without WordPress.
	 *
	 * @param int $since Unix time the claim was made, or 0 when unreadable.
	 * @param int $now   Unix time now.
	 * @param int $ttl   Seconds a claim stays live.
	 * @return bool
	 */
	public static function is_stale( $since, $now, $ttl ) {
		if ( $since <= 0 ) {
			return true;
		}
		return ( $now - $since ) >= (int) $ttl;
	}

	/**
	 * The timestamp inside a stored claim value.
	 *
	 * @param string $value Stored value.
	 * @return int
	 */
	public static function claimed_at( $value ) {
		$parts = explode( '|', (string) $value );
		return (int) ( isset( $parts[1] ) ? $parts[1] : $parts[0] );
	}

	/**
	 * The one write that decides ownership.
	 *
	 * @param string $name  Option name.
	 * @param string $token Claim token.
	 * @return bool
	 */
	private static function insert( $name, $token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see the class docblock.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				$token . '|' . time()
			)
		);
		$won = ( 1 === (int) $wpdb->rows_affected );
		self::forget( $name );
		return $won;
	}

	/**
	 * Read a claim straight from storage, never from the object cache.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	private static function read( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see the class docblock.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	/**
	 * Swap an abandoned claim for ours, atomically.
	 *
	 * @param string $name  Option name.
	 * @param string $held  The exact value read and judged stale.
	 * @param string $token Our token.
	 * @return bool
	 */
	private static function swap( $name, $held, $token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see the class docblock.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$token . '|' . time(),
				$name,
				$held
			)
		);
		$won = ( 1 === (int) $wpdb->rows_affected );
		self::forget( $name );
		return $won;
	}

	/**
	 * Keep the object cache out of the mutex's way.
	 *
	 * @param string $name Option name.
	 */
	private static function forget( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
