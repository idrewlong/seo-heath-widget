<?php
/**
 * Settings > SEO Health page, plus the Test connection / Run audit now / Refresh actions.
 */

defined( 'ABSPATH' ) || exit;

class SEOHW_Settings {

	const PAGE = 'seohw-settings';

	/** Prefix marking a service account key encrypted at rest. */
	const KEY_PREFIX = 'seohw1:';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_seohw_test_connection', array( __CLASS__, 'handle_test_connection' ) );
		add_action( 'admin_post_seohw_run_audit', array( __CLASS__, 'handle_run_audit' ) );
		add_action( 'admin_post_seohw_refresh', array( __CLASS__, 'handle_refresh' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SEOHW_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function defaults() {
		return array(
			'property'        => '',
			'service_account' => '',
			'period'          => 'month',
			'site_type'       => 'standard',
			'frontend_hosts'  => '',
			'max_items'       => 5000,
		);
	}

	public static function get( $key = null ) {
		$settings = wp_parse_args( get_option( SEOHW_OPTION, array() ), self::defaults() );
		return null === $key ? $settings : ( $settings[ $key ] ?? null );
	}

	public static function settings_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	/**
	 * Service account JSON, from the wp-config constant if set, else from the saved option.
	 *
	 * @return array|WP_Error Decoded key with client_email and private_key.
	 */
	public static function service_account() {
		if ( defined( 'SEOHW_SERVICE_ACCOUNT_FILE' ) && SEOHW_SERVICE_ACCOUNT_FILE ) {
			if ( ! is_readable( SEOHW_SERVICE_ACCOUNT_FILE ) ) {
				return new WP_Error( 'seohw_key_unreadable', __( 'The file in SEOHW_SERVICE_ACCOUNT_FILE is missing or not readable.', 'seo-health-widget' ) );
			}
			$json = file_get_contents( SEOHW_SERVICE_ACCOUNT_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} else {
			$json = self::decrypt( self::get( 'service_account' ) );
			if ( is_wp_error( $json ) ) {
				return $json;
			}
		}

		if ( ! $json ) {
			return new WP_Error( 'seohw_no_key', __( 'No service account key is configured.', 'seo-health-widget' ) );
		}

		return self::parse_key( $json );
	}

	/**
	 * Encryption key derived from the AUTH_KEY and AUTH_SALT secrets in wp-config.php, so a
	 * copy of the database alone (a dump, a backup, SQL injection) can't reveal the key.
	 */
	private static function crypto_key() {
		return sodium_crypto_generichash( 'seohw-service-account|' . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	private static function encrypt( $plaintext ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::KEY_PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, self::crypto_key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @return string|WP_Error Plaintext JSON, or '' when nothing is saved.
	 */
	private static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, self::KEY_PREFIX ) ) {
			return $stored; // Empty, or saved before keys were encrypted.
		}

		$raw   = base64_decode( substr( $stored, strlen( self::KEY_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$plain = false;
		if ( $raw && strlen( $raw ) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::crypto_key()
			);
		}
		if ( false === $plain ) {
			return new WP_Error( 'seohw_key_decrypt', __( 'The saved service account key can\'t be decrypted, usually because the security keys in wp-config.php changed. Paste the key again.', 'seo-health-widget' ) );
		}
		return $plain;
	}

	private static function parse_key( $json ) {
		$key = json_decode( $json, true );
		if ( ! is_array( $key ) || empty( $key['client_email'] ) || empty( $key['private_key'] ) ) {
			return new WP_Error( 'seohw_bad_key', __( 'The service account key is not valid JSON with client_email and private_key.', 'seo-health-widget' ) );
		}
		return $key;
	}

	public static function is_configured() {
		return '' !== self::get( 'property' ) && ! is_wp_error( self::service_account() );
	}

	public static function is_headless() {
		return 'headless' === self::get( 'site_type' );
	}

	/**
	 * Front-end origins (scheme://host[:port]) for a headless site, in the order entered.
	 * Empty for a standard site.
	 *
	 * @return string[]
	 */
	public static function frontend_origins() {
		if ( ! self::is_headless() ) {
			return array();
		}
		$origins = array();
		foreach ( preg_split( '/[\s,]+/', (string) self::get( 'frontend_hosts' ) ) as $entry ) {
			$origin = self::origin( false === strpos( $entry, '//' ) ? 'https://' . $entry : $entry );
			if ( $origin ) {
				$origins[] = $origin;
			}
		}
		return array_values( array_unique( $origins ) );
	}

	/**
	 * Where visitors see the site: the first front-end URL on a headless site, else the home URL.
	 */
	public static function public_origin() {
		$frontends = self::frontend_origins();
		return $frontends ? $frontends[0] : self::origin( home_url() );
	}

	/**
	 * scheme://host[:port] for a URL, or '' if it has no host.
	 */
	public static function origin( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		return strtolower( $parts['scheme'] ?? 'https' ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * Hosts whose links count as internal (WordPress plus any front ends), lower-cased, without "www.".
	 */
	public static function internal_hosts() {
		$hosts = array( wp_parse_url( home_url(), PHP_URL_HOST ) );
		foreach ( self::frontend_origins() as $origin ) {
			$hosts[] = wp_parse_url( $origin, PHP_URL_HOST );
		}
		$hosts = array_map(
			static function ( $host ) {
				return preg_replace( '/^www\./', '', strtolower( (string) $host ) );
			},
			$hosts
		);
		return array_values( array_unique( array_filter( $hosts ) ) );
	}

	public static function add_page() {
		add_options_page(
			__( 'SEO Health', 'seo-health-widget' ),
			__( 'SEO Health', 'seo-health-widget' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'seo-health-widget' ) . '</a>' );
		return $links;
	}

	public static function register() {
		register_setting(
			'seohw',
			SEOHW_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$old   = self::get();
		$clean = self::defaults();
		$input = is_array( $input ) ? $input : array();

		$property = trim( sanitize_text_field( $input['property'] ?? '' ) );
		if ( '' !== $property && 0 !== strpos( $property, 'sc-domain:' ) ) {
			$property = esc_url_raw( $property );
			if ( '' !== $property && '/' !== substr( $property, -1 ) ) {
				$property .= '/';
			}
		}
		$clean['property'] = $property;

		// The form posts a new key as service_account_new; a blank field keeps the saved key, which
		// is never printed back into the form. An already-encrypted service_account value is kept
		// as is, since WordPress runs this callback a second time when the option is first created.
		$stored                   = (string) ( $input['service_account'] ?? '' );
		$clean['service_account'] = 0 === strpos( $stored, self::KEY_PREFIX ) ? $stored : $old['service_account'];
		$new_key                  = trim( (string) ( $input['service_account_new'] ?? '' ) ); // options.php has already unslashed this.
		if ( ! empty( $input['clear_key'] ) ) {
			$clean['service_account'] = '';
		} elseif ( '' !== $new_key ) {
			$parsed = self::parse_key( $new_key );
			if ( is_wp_error( $parsed ) ) {
				add_settings_error( SEOHW_OPTION, 'seohw_bad_key', $parsed->get_error_message() );
			} else {
				$clean['service_account'] = self::encrypt( wp_json_encode( $parsed ) );
			}
		}

		$clean['period']         = in_array( $input['period'] ?? '', array( 'month', '28d' ), true ) ? $input['period'] : 'month';
		$clean['site_type']      = 'headless' === ( $input['site_type'] ?? '' ) ? 'headless' : 'standard';
		$clean['frontend_hosts'] = sanitize_textarea_field( $input['frontend_hosts'] ?? '' );
		$clean['max_items']      = max( 10, min( 100000, absint( $input['max_items'] ?? 5000 ) ) );

		if ( 'headless' === $clean['site_type'] && '' === trim( $clean['frontend_hosts'] ) ) {
			add_settings_error( SEOHW_OPTION, 'seohw_no_frontend', __( 'Headless sites need a front-end URL, or link checks will test the WordPress back end instead of the public site.', 'seo-health-widget' ), 'warning' );
		}

		if ( $clean['property'] !== $old['property'] || $clean['service_account'] !== $old['service_account'] || $clean['period'] !== $old['period'] ) {
			SEOHW_GSC_Client::flush_cache();
		}

		return $clean;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings    = self::get();
		$from_file   = defined( 'SEOHW_SERVICE_ACCOUNT_FILE' ) && SEOHW_SERVICE_ACCOUNT_FILE;
		$key         = self::service_account();
		$test_result = get_transient( 'seohw_test_result' );
		$audit       = get_option( SEOHW_AUDIT_OPTION );
		$seo_plugin  = SEOHW_SEO_Meta::active_plugin_label();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SEO Health', 'seo-health-widget' ); ?></h1>

			<?php if ( $test_result ) : ?>
				<?php delete_transient( 'seohw_test_result' ); ?>
				<div class="notice notice-<?php echo $test_result['ok'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $test_result['message'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['seohw_audit'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					'done' === $_GET['seohw_audit'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						? esc_html_e( 'Audit complete.', 'seo-health-widget' )
						: esc_html_e( 'Audit started. It continues in the background; progress shows below and on the dashboard.', 'seo-health-widget' );
					?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'seohw' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="seohw-property"><?php esc_html_e( 'Property', 'seo-health-widget' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="seohw-property" name="<?php echo esc_attr( SEOHW_OPTION ); ?>[property]" value="<?php echo esc_attr( $settings['property'] ); ?>" placeholder="sc-domain:example.com" />
							<p class="description"><?php esc_html_e( 'Exactly as Search Console shows it: sc-domain:example.com or https://www.example.com/', 'seo-health-widget' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="seohw-key"><?php esc_html_e( 'Service account key', 'seo-health-widget' ); ?></label></th>
						<td>
							<?php if ( $from_file ) : ?>
								<p>
									<?php
									if ( is_wp_error( $key ) ) {
										echo esc_html( $key->get_error_message() );
									} else {
										/* translators: %s: service account email */
										printf( esc_html__( 'Loaded from SEOHW_SERVICE_ACCOUNT_FILE (%s).', 'seo-health-widget' ), '<code>' . esc_html( $key['client_email'] ) . '</code>' );
									}
									?>
								</p>
							<?php else : ?>
								<?php if ( ! is_wp_error( $key ) ) : ?>
									<p>
										<?php
										/* translators: %s: service account email */
										printf( esc_html__( 'A key is saved for %s. Paste a new one to replace it.', 'seo-health-widget' ), '<code>' . esc_html( $key['client_email'] ) . '</code>' );
										?>
									</p>
								<?php endif; ?>
								<textarea class="large-text code" rows="6" id="seohw-key" name="<?php echo esc_attr( SEOHW_OPTION ); ?>[service_account_new]" autocomplete="off" spellcheck="false" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, ... }"></textarea>
								<?php if ( ! is_wp_error( $key ) ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( SEOHW_OPTION ); ?>[clear_key]" value="1" /> <?php esc_html_e( 'Remove saved key', 'seo-health-widget' ); ?></label>
								<?php endif; ?>
								<p class="description">
									<?php esc_html_e( 'Preferably, store the file outside the web root and add this to wp-config.php instead:', 'seo-health-widget' ); ?><br />
									<code>define( 'SEOHW_SERVICE_ACCOUNT_FILE', '/path/outside/webroot/seohw-key.json' );</code>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Comparison period', 'seo-health-widget' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( SEOHW_OPTION ); ?>[period]" value="month" <?php checked( $settings['period'], 'month' ); ?> /> <?php esc_html_e( 'Last full month vs. the month before', 'seo-health-widget' ); ?></label><br />
								<label><input type="radio" name="<?php echo esc_attr( SEOHW_OPTION ); ?>[period]" value="28d" <?php checked( $settings['period'], '28d' ); ?> /> <?php esc_html_e( 'Rolling 28 days vs. the 28 days before', 'seo-health-widget' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Site type', 'seo-health-widget' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( SEOHW_OPTION ); ?>[site_type]" value="standard" <?php checked( $settings['site_type'], 'standard' ); ?> /> <?php esc_html_e( 'Standard: WordPress serves the public site', 'seo-health-widget' ); ?></label><br />
								<label><input type="radio" name="<?php echo esc_attr( SEOHW_OPTION ); ?>[site_type]" value="headless" <?php checked( $settings['site_type'], 'headless' ); ?> /> <?php esc_html_e( 'Headless: a separate front end (Next, Nuxt, etc.) serves the public site', 'seo-health-widget' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="seohw-hosts"><?php esc_html_e( 'Front-end URL', 'seo-health-widget' ); ?></label></th>
						<td>
							<textarea class="regular-text code" rows="2" id="seohw-hosts" name="<?php echo esc_attr( SEOHW_OPTION ); ?>[frontend_hosts]" placeholder="https://www.example.com"><?php echo esc_textarea( $settings['frontend_hosts'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Headless sites only. The public site URL. Links to it count as internal, and link checks run against it. Add any extra front-end domains on new lines.', 'seo-health-widget' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="seohw-max"><?php esc_html_e( 'Max items scanned', 'seo-health-widget' ); ?></label></th>
						<td>
							<input type="number" min="10" max="100000" class="small-text" id="seohw-max" name="<?php echo esc_attr( SEOHW_OPTION ); ?>[max_items]" value="<?php echo esc_attr( $settings['max_items'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Upper limit on published items checked per audit. Large sites are checked in batches in the background.', 'seo-health-widget' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'SEO plugin', 'seo-health-widget' ); ?></th>
						<td>
							<?php
							echo $seo_plugin
								? esc_html( $seo_plugin )
								: esc_html__( 'None detected. Noindex and meta description checks are skipped.', 'seo-health-widget' );
							?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
				<input type="hidden" name="action" value="seohw_test_connection" />
				<?php wp_nonce_field( 'seohw_test_connection' ); ?>
				<?php submit_button( __( 'Test connection', 'seo-health-widget' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
				<input type="hidden" name="action" value="seohw_run_audit" />
				<?php wp_nonce_field( 'seohw_run_audit' ); ?>
				<?php submit_button( __( 'Run audit now', 'seo-health-widget' ), 'secondary', 'submit', false ); ?>
			</form>

			<p class="description">
				<?php
				$progress = SEOHW_Audit::progress();
				if ( $progress ) {
					/* translators: 1: items checked, 2: total items */
					printf( esc_html__( 'Audit in progress: %1$s of %2$s items checked.', 'seo-health-widget' ), esc_html( number_format_i18n( $progress['scanned'] ) ), esc_html( number_format_i18n( $progress['total'] ) ) );
					echo ' ';
				}
				if ( $audit && ! empty( $audit['finished'] ) ) {
					/* translators: %s: human time diff */
					printf( esc_html__( 'Last audit: %s ago.', 'seo-health-widget' ), esc_html( human_time_diff( $audit['finished'] ) ) );
					echo ' ';
				}
				$next = wp_next_scheduled( SEOHW_CRON_HOOK );
				if ( $next ) {
					/* translators: %s: human time diff */
					printf( esc_html__( 'Next scheduled audit in %s.', 'seo-health-widget' ), esc_html( human_time_diff( $next ) ) );
				}
				?>
			</p>
		</div>
		<?php
	}

	public static function handle_test_connection() {
		self::guard( 'seohw_test_connection' );

		$result = SEOHW_GSC_Client::test_connection();
		if ( is_wp_error( $result ) ) {
			$test = array( 'ok' => false, 'message' => $result->get_error_message() );
		} else {
			SEOHW_GSC_Client::flush_cache();
			/* translators: %s: permission level */
			$test = array( 'ok' => true, 'message' => sprintf( __( 'Connected. Permission level: %s.', 'seo-health-widget' ), $result ) );
		}
		set_transient( 'seohw_test_result', $test, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( self::settings_url() );
		exit;
	}

	public static function handle_run_audit() {
		self::guard( 'seohw_run_audit' );
		$status = SEOHW_Audit::start( true );
		wp_safe_redirect( add_query_arg( 'seohw_audit', 'done' === $status ? 'done' : 'started', self::settings_url() ) );
		exit;
	}

	public static function handle_refresh() {
		self::guard( 'seohw_refresh' );
		SEOHW_GSC_Client::flush_cache();
		SEOHW_GSC_Client::refresh();
		wp_safe_redirect( admin_url( 'index.php' ) );
		exit;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'seo-health-widget' ), 403 );
		}
		check_admin_referer( $action );
	}
}
