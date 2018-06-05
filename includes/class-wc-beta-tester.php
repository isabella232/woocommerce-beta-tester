<?php
/**
 * Beta Tester plugin main class
 *
 * @package WC_Beta_Tester
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_Beta_Tester Main Class.
 */
class WC_Beta_Tester {

	/**
	 * Config
	 *
	 * @var array
	 */
	private $config = array();

	/**
	 * Plugin instance.
	 *
	 * @var WC_Beta_Tester
	 */
	protected static $_instance = null;

	/**
	 * Main Instance.
	 */
	public static function instance() {
		self::$_instance = is_null( self::$_instance ) ? new self() : self::$_instance;

		return self::$_instance;
	}

	/**
	 * Ran on activation to flush update cache
	 */
	public static function activate() {
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'woocommerce_latest_tag' );
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->config = array(
			'plugin_file'        => 'woocommerce/woocommerce.php',
			'slug'               => 'woocommerce',
			'proper_folder_name' => 'woocommerce',
			'api_url'            => 'https://api.wordpress.org/plugins/info/1.0/woocommerce.json',
			'repo_url'           => 'https://wordpress.org/plugins/woocommerce/',
			'requires'           => '4.4',
			'tested'             => '4.9',
		);

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'api_check' ) );
		add_filter( 'plugins_api', array( $this, 'get_plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'upgrader_source_selection' ), 10, 3 );

		$this->includes();
	}

	/**
	 * Include any classes we need within admin.
	 */
	public function includes() {
		include_once dirname( __FILE__ ) . '/class-wc-beta-tester-admin-menus.php';
	}

	/**
	 * Update args.
	 */
	public function set_update_args() {
		$plugin_data                  = $this->get_plugin_data();
		$this->config['plugin_name']  = $plugin_data['Name'];
		$this->config['version']      = $plugin_data['Version'];
		$this->config['author']       = $plugin_data['Author'];
		$this->config['homepage']     = $plugin_data['PluginURI'];
		$this->config['new_version']  = $this->get_latest_prerelease();
		$this->config['last_updated'] = $this->get_date();
		$this->config['description']  = $this->get_description();
		$this->config['zip_url']      = $this->get_download_url( $this->config['new_version'] );
	}

	/**
	 * Check wether or not the transients need to be overruled and API needs to be called for every single page load
	 *
	 * @return bool overrule or not
	 */
	public function overrule_transients() {
		return defined( 'WC_BETA_TESTER_FORCE_UPDATE' ) && WC_BETA_TESTER_FORCE_UPDATE;
	}

	/**
	 * Get New Version from WPorg
	 *
	 * @since 1.0
	 * @return int $version the version number
	 */
	public function get_latest_prerelease() {
		$tagged_version = get_site_transient( md5( $this->config['slug'] ) . '_latest_tag' );

		if ( $this->overrule_transients() || empty( $tagged_version ) ) {

			$data = $this->get_wporg_data();

			$latest_version = $data->version;
			$versions       = (array) $data->versions;

			foreach ( $versions as $version => $download_url ) {
				if ( version_compare( $version, $latest_version, '>' )
					&& preg_match( '/(.*)?-(beta|rc)(.*)/', $version ) ) {

					$tagged_version = $version;
					break;
				}
			}

			// Refresh every 6 hours.
			if ( ! empty( $tagged_version ) ) {
				set_site_transient( md5( $this->config['slug'] ) . '_latest_tag', $tagged_version, 60 * 60 * 6 );
			}
		}

		return $tagged_version;
	}

	/**
	 * Get Data from .org API.
	 *
	 * @since 1.0
	 * @return array $wporg_data The data.
	 */
	public function get_wporg_data() {
		if ( ! empty( $this->wporg_data ) ) {
			return $this->wporg_data;
		}

		$wporg_data = get_site_transient( md5( $this->config['slug'] ) . '_wporg_data' );

		if ( $this->overrule_transients() || ( ! isset( $wporg_data ) || ! $wporg_data || '' === $wporg_data ) ) {
			$wporg_data = wp_remote_get( $this->config['api_url'] );

			if ( is_wp_error( $wporg_data ) ) {
				return false;
			}

			$wporg_data = json_decode( $wporg_data['body'] );

			// Refresh every 6 hours.
			set_site_transient( md5( $this->config['slug'] ) . '_wporg_data', $wporg_data, 60 * 60 * 6 );
		}

		// Store the data in this class instance for future calls.
		$this->wporg_data = $wporg_data;

		return $wporg_data;
	}
	/**
	 * Get update date
	 *
	 * @since 1.0
	 * @return string $date the date
	 */
	public function get_date() {
		$data = $this->get_wporg_data();
		return ! empty( $data->last_updated ) ? date( 'Y-m-d', strtotime( $data->last_updated ) ) : false;
	}

	/**
	 * Get plugin description
	 *
	 * @since 1.0
	 * @return string $description the description
	 */
	public function get_description() {
		$data = $this->get_wporg_data();

		if ( empty( $data->sections->description ) ) {
			return false;
		}

		$data = $data->sections->description;

		if ( preg_match( '%(<p[^>]*>.*?</p>)%i', $data, $regs ) ) {
			$data = strip_tags( $regs[1] );
		}

		return $data;
	}

	/**
	 * Get plugin download URL.
	 *
	 * @since 1.0
	 * @param string $version The version.
	 * @return string
	 */
	public function get_download_url( $version ) {
		$data = $this->get_wporg_data();

		if ( empty( $data->versions->$version ) ) {
			return false;
		}

		return $data->versions->$version;
	}

	/**
	 * Get Plugin data.
	 *
	 * @since 1.0
	 * @return object $data The data.
	 */
	public function get_plugin_data() {
		return get_plugin_data( WP_PLUGIN_DIR . '/' . $this->config['plugin_file'] );
	}

	/**
	 * Hook into the plugin update check and connect to WPorg.
	 *
	 * @since 1.0
	 * @param object $transient The plugin data transient.
	 * @return object $transient Updated plugin data transient.
	 */
	public function api_check( $transient ) {
		// Check if the transient contains the 'checked' information,
		// If not, just return its value without hacking it.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Clear our transient.
		delete_site_transient( md5( $this->config['slug'] ) . '_latest_tag' );

		// Update tags.
		$this->set_update_args();

		// check the version and decide if it's new.
		$update = version_compare( $this->config['new_version'], $this->config['version'], '>' );

		if ( $update ) {
			$response              = new stdClass();
			$response->plugin      = $this->config['slug'];
			$response->new_version = $this->config['new_version'];
			$response->slug        = $this->config['slug'];
			$response->url         = $this->config['repo_url'];
			$response->package     = $this->config['zip_url'];

			// If response is false, don't alter the transient.
			if ( false !== $response ) {
				$transient->response[ $this->config['plugin_file'] ] = $response;
			}
		}

		return $transient;
	}

	/**
	 * Get Plugin info.
	 *
	 * @since 1.0
	 * @param bool   $false    Always false.
	 * @param string $action   The API function being performed.
	 * @param object $response The plugin info.
	 * @return object
	 */
	public function get_plugin_info( $false, $action, $response ) {
		// Check if this call API is for the right plugin.
		if ( ! isset( $response->slug ) || $response->slug !== $this->config['slug'] ) {
			return false;
		}

		// Update tags.
		$this->set_update_args();

		$response->slug          = $this->config['slug'];
		$response->plugin        = $this->config['slug'];
		$response->name          = $this->config['plugin_name'];
		$response->plugin_name   = $this->config['plugin_name'];
		$response->version       = $this->config['new_version'];
		$response->author        = $this->config['author'];
		$response->homepage      = $this->config['homepage'];
		$response->requires      = $this->config['requires'];
		$response->tested        = $this->config['tested'];
		$response->downloaded    = 0;
		$response->last_updated  = $this->config['last_updated'];
		$response->sections      = array( 'description' => $this->config['description'] );
		$response->download_link = $this->config['zip_url'];

		return $response;
	}

	/**
	 * Rename the downloaded zip
	 *
	 * @param string      $source        File source location.
	 * @param string      $remote_source Remote file source location.
	 * @param WP_Upgrader $upgrader      WordPress Upgrader instance.
	 * @return string
	 */
	public function upgrader_source_selection( $source, $remote_source, $upgrader ) {
		global $wp_filesystem;

		if ( strstr( $source, '/woocommerce-woocommerce-' ) ) {
			$corrected_source = trailingslashit( $remote_source ) . trailingslashit( $this->config['proper_folder_name'] );

			if ( $wp_filesystem->move( $source, $corrected_source, true ) ) {
				return $corrected_source;
			} else {
				return new WP_Error();
			}
		}

		return $source;
	}
}
