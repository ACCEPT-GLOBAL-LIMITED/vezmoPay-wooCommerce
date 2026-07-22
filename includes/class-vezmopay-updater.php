<?php
/**
 * Self-hosted update provider (GitHub Releases).
 *
 * WordPress only auto-updates plugins it knows how to update. This class plugs
 * VezmoPay into the core update system by reporting the latest GitHub release,
 * so the Plugins screen shows an "update available" notice, one-click update,
 * and the native "Enable auto-updates" toggle — with no external library and
 * no separate updater plugin for merchants to install.
 *
 * Releases must be published on the repo (a git tag + GitHub release). The
 * release tag is the version (a leading "v" is ignored); the update package is
 * a release asset ending in .zip if present, otherwise the source zipball.
 *
 * @package VezmoPay
 */

namespace VezmoPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * GitHub-release-backed plugin updater.
 */
class Updater {

	/**
	 * GitHub repository (owner/name).
	 */
	const REPO = 'ACCEPT-GLOBAL-LIMITED/vezmoPay-wooCommerce';

	/**
	 * Transient key for the cached release lookup.
	 */
	const CACHE_KEY = 'vezmopay_latest_release';

	/**
	 * How long to cache the GitHub lookup (also caches "no release", to avoid
	 * hammering the unauthenticated API rate limit).
	 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Plugin basename, e.g. vezmopay-woocommerce/vezmopay-woocommerce.php.
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Plugin folder slug, e.g. vezmopay-woocommerce.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Constructor: register the update hooks.
	 */
	public function __construct() {
		$this->basename = plugin_basename( VEZMOPAY_WC_PLUGIN_FILE );
		$this->slug     = dirname( $this->basename );

		// Canonical Update-URI API: because the plugin header declares
		// `Update URI: https://github.com/…`, WordPress routes update checks for
		// it through update_plugins_github.com. Registering this filter is ALSO
		// what makes core treat the plugin as "update-supported", which is the
		// condition for the Enable/Disable auto-updates link to appear in the
		// Plugins list (same as any wordpress.org plugin).
		add_filter( 'update_plugins_github.com', array( $this, 'check_update' ), 10, 3 );
		// Guarantee the plugin is present in the update_plugins transient on every
		// read (in response when an update exists, else no_update). Core shows the
		// Enable/Disable auto-updates link only for plugins it finds there — the
		// hostname filter above populates it during a scheduled check, but this
		// read-filter makes it reliable on the very first Plugins-screen load.
		add_filter( 'site_transient_update_plugins', array( $this, 'inject_transient' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_details' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );

		// Auto-updates are controlled by WordPress's native per-plugin toggle on
		// the Plugins screen. Deliberately NO auto_update_plugin filter here:
		// force-setting the value makes core replace the Enable/Disable link
		// with static "Auto-updates enabled" text (state controlled by code),
		// taking the choice away from the site owner.

		// Refresh the cached lookup right after an update completes.
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 2 );
	}

	/**
	 * Fetch (and cache) the latest release from the GitHub API.
	 *
	 * @return array|null { version, package, url, changelog, published } or null.
	 */
	private function latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null; // Empty array = cached "no release".
		}

		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'VezmoPay-WooCommerce/' . VEZMOPAY_WC_VERSION,
		);
		// Private repos (and higher rate limits) need a token. Provide one via the
		// VEZMOPAY_GITHUB_TOKEN constant in wp-config.php or the filter below.
		$token = $this->token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => $headers,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, array(), self::CACHE_TTL );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, array(), self::CACHE_TTL );
			return null;
		}

		// Prefer a .zip release asset (built with the correct folder); else the source zipball.
		$package = isset( $body['zipball_url'] ) ? (string) $body['zipball_url'] : '';
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! empty( $asset['browser_download_url'] ) && '.zip' === substr( (string) $asset['name'], -4 ) ) {
					$package = (string) $asset['browser_download_url'];
					break;
				}
			}
		}

		$release = array(
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			'package'   => $package,
			'url'       => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
			'changelog' => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
		);

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
		return $release;
	}

	/**
	 * GitHub token for API reads (only needed while the repo is private, or to
	 * raise the anonymous rate limit). Prefer a wp-config.php constant.
	 *
	 * @return string
	 */
	private function token() {
		$token = defined( 'VEZMOPAY_GITHUB_TOKEN' ) ? (string) constant( 'VEZMOPAY_GITHUB_TOKEN' ) : '';
		/** Filter the GitHub token used for update checks. */
		return (string) apply_filters( 'vezmopay_github_token', $token );
	}

	/**
	 * Ensure the plugin is listed in the update_plugins transient on every read,
	 * so the Plugins screen always renders the auto-updates toggle for it.
	 *
	 * @param mixed $transient The update_plugins site transient.
	 * @return mixed
	 */
	public function inject_transient( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		// Already handled this cycle (e.g. by the hostname check) — leave it.
		if ( isset( $transient->response[ $this->basename ] ) || isset( $transient->no_update[ $this->basename ] ) ) {
			return $transient;
		}

		$release = $this->latest_release();
		$item    = (object) array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => $this->slug,
			'plugin'       => $this->basename,
			'new_version'  => $release ? $release['version'] : VEZMOPAY_WC_VERSION,
			'url'          => $release ? $release['url'] : 'https://github.com/' . self::REPO,
			'package'      => $release ? $release['package'] : '',
			'icons'        => array( 'default' => VEZMOPAY_WC_PLUGIN_URL . 'assets/img/vezmo-mark.svg' ),
			'tested'       => '6.8',
			'requires'     => '6.0',
			'requires_php' => '7.4',
		);

		if ( ! isset( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		if ( $release && '' !== $release['package'] && version_compare( $release['version'], VEZMOPAY_WC_VERSION, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
		} else {
			$transient->no_update[ $this->basename ] = $item;
		}

		return $transient;
	}

	/**
	 * Update-check callback for the plugin's Update URI host (github.com).
	 *
	 * WordPress calls this once per update check for plugins whose `Update URI`
	 * host is github.com. Returning the update array (even when up to date) makes
	 * core file the plugin under response/no_update by version compare — and its
	 * mere registration flags the plugin "update-supported", which is what shows
	 * the Enable/Disable auto-updates link in the Plugins list. Returning the
	 * unchanged `$update` (false) only on API failure so nothing else is affected.
	 *
	 * @param array|false $update      The update offer (false by default).
	 * @param array       $plugin_data The plugin headers.
	 * @param string      $plugin_file The plugin basename being checked.
	 * @return array|false
	 */
	public function check_update( $update, $plugin_data, $plugin_file ) {
		if ( $plugin_file !== $this->basename ) {
			return $update;
		}

		$release = $this->latest_release();
		if ( ! $release || '' === $release['package'] ) {
			return $update;
		}

		// Both `version` (core's compare) and `new_version` (list/upgrader display).
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => $this->slug,
			'plugin'       => $this->basename,
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'icons'        => array(
				'default' => VEZMOPAY_WC_PLUGIN_URL . 'assets/img/vezmo-mark.svg',
			),
			'tested'       => '6.8',
			'requires'     => '6.0',
			'requires_php' => '7.4',
		);
	}

	/**
	 * Populate the "View details" modal for this plugin.
	 *
	 * @param false|object|array $result The result object/array. Default false.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Arguments for the API call.
	 * @return false|object
	 */
	public function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'VezmoPay for WooCommerce',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://vezmo.com">ACCEPT GLOBAL LIMITED</a>',
			'homepage'      => $release['url'],
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'tested'        => '6.8',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => $this->render_changelog( $release ),
			),
		);
	}

	/**
	 * Format the release notes for the details modal.
	 *
	 * @param array $release Release data.
	 * @return string
	 */
	private function render_changelog( $release ) {
		$notes = '' !== $release['changelog']
			? wp_kses_post( nl2br( $release['changelog'] ) )
			: esc_html__( 'See the GitHub release notes for details.', 'vezmopay-woocommerce' );

		return '<h4>' . esc_html( sprintf( /* translators: %s: version */ __( 'Version %s', 'vezmopay-woocommerce' ), $release['version'] ) ) . '</h4>'
			. '<p>' . $notes . '</p>'
			. '<p><a href="' . esc_url( $release['url'] ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'View full release on GitHub', 'vezmopay-woocommerce' ) . '</a></p>';
	}

	/**
	 * Rename the extracted release folder to the plugin slug.
	 *
	 * GitHub source zipballs extract to owner-repo-<sha>/, which would install
	 * the plugin into the wrong directory and orphan the update. Rename it.
	 *
	 * @param string $source        Path to the extracted source.
	 * @param string $remote_source Path to the download folder.
	 * @param object $upgrader      WP_Upgrader instance.
	 * @param array  $hook_extra    Extra args incl. the plugin basename.
	 * @return string|\WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}

		global $wp_filesystem;
		$desired = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $this->slug;

		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}
		if ( ! $wp_filesystem || ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired ), true ) ) {
			return new \WP_Error( 'vezmopay_update_rename', __( 'Could not prepare the VezmoPay update package.', 'vezmopay-woocommerce' ) );
		}

		return trailingslashit( $desired );
	}

	/**
	 * Clear the cached lookup after any plugin update completes.
	 *
	 * @param object $upgrader WP_Upgrader instance.
	 * @param array  $data     Update context.
	 */
	public function flush_cache( $upgrader, $data ) {
		if ( isset( $data['type'] ) && 'plugin' === $data['type'] ) {
			delete_transient( self::CACHE_KEY );
		}
	}
}
