<?php
/**
 * Minimal Search Console API client using a service account (no Google SDK).
 */

defined( 'ABSPATH' ) || exit;

class SEOHW_GSC_Client {

	const SCOPE       = 'https://www.googleapis.com/auth/webmasters.readonly';
	const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
	const API_BASE    = 'https://www.googleapis.com/webmasters/v3/sites/';
	const CACHE_TTL   = 12 * HOUR_IN_SECONDS;
	const ERROR_TTL   = HOUR_IN_SECONDS;
	const TOKEN_CACHE = 'seohw_access_token';

	/**
	 * Dashboard data for the configured property, cached for 12 hours.
	 *
	 * Each refresh makes 4 Search Console calls: current totals, previous totals,
	 * daily clicks and top queries.
	 *
	 * @return array|WP_Error
	 */
	public static function get_report() {
		$property = SEOHW_Settings::get( 'property' );
		if ( '' === $property ) {
			return new WP_Error( 'seohw_no_property', __( 'No Search Console property is configured.', 'seo-health-widget' ) );
		}

		$ranges    = self::ranges( SEOHW_Settings::get( 'period' ) );
		$cache_key = 'seohw_report_' . md5( (int) get_option( 'seohw_cache_gen', 1 ) . '|' . $property . '|' . $ranges['current']['start'] . '|' . $ranges['current']['end'] );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return isset( $cached['error'] ) ? new WP_Error( $cached['code'], $cached['error'] ) : $cached;
		}

		$report = self::fetch_report( $property, $ranges );
		if ( is_wp_error( $report ) ) {
			set_transient(
				$cache_key,
				array( 'code' => $report->get_error_code(), 'error' => $report->get_error_message() ),
				self::ERROR_TTL
			);
		} else {
			set_transient( $cache_key, $report, self::CACHE_TTL );
		}
		return $report;
	}

	private static function fetch_report( $property, $ranges ) {
		$current = self::query( $property, $ranges['current'] );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$previous = self::query( $property, $ranges['previous'] );
		if ( is_wp_error( $previous ) ) {
			return $previous;
		}
		$daily = self::query( $property, $ranges['current'], array( 'dimensions' => array( 'date' ) ) );
		if ( is_wp_error( $daily ) ) {
			return $daily;
		}
		$queries = self::query(
			$property,
			$ranges['current'],
			array(
				'dimensions' => array( 'query' ),
				'rowLimit'   => 5,
			)
		);
		if ( is_wp_error( $queries ) ) {
			return $queries;
		}

		// Fill missing days with zero so the trend line has an even x-axis.
		$by_date = array();
		foreach ( $daily as $row ) {
			$by_date[ $row['keys'][0] ] = (int) $row['clicks'];
		}
		$days   = array();
		$cursor = new DateTimeImmutable( $ranges['current']['start'] );
		$end    = new DateTimeImmutable( $ranges['current']['end'] );
		while ( $cursor <= $end ) {
			$date          = $cursor->format( 'Y-m-d' );
			$days[ $date ] = $by_date[ $date ] ?? 0;
			$cursor        = $cursor->modify( '+1 day' );
		}

		$top = array();
		foreach ( $queries as $row ) {
			$top[] = array(
				'query'       => $row['keys'][0],
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'position'    => (float) $row['position'],
			);
		}

		return array(
			'ranges'   => $ranges,
			'current'  => self::totals( $current ),
			'previous' => self::totals( $previous ),
			'daily'    => $days,
			'queries'  => $top,
			'fetched'  => time(),
		);
	}

	private static function totals( $rows ) {
		$row = $rows[0] ?? array();
		return array(
			'clicks'      => (int) ( $row['clicks'] ?? 0 ),
			'impressions' => (int) ( $row['impressions'] ?? 0 ),
			'ctr'         => (float) ( $row['ctr'] ?? 0 ),
			'position'    => (float) ( $row['position'] ?? 0 ),
		);
	}

	/**
	 * Current and previous date ranges. Search Console reports in Pacific time and
	 * lags by 2-3 days, so rolling ranges end 3 days ago.
	 */
	public static function ranges( $period ) {
		$today = new DateTimeImmutable( 'today', new DateTimeZone( 'America/Los_Angeles' ) );

		if ( '28d' === $period ) {
			$cur_end   = $today->modify( '-3 days' );
			$cur_start = $cur_end->modify( '-27 days' );
			$prev_end   = $cur_start->modify( '-1 day' );
			$prev_start = $prev_end->modify( '-27 days' );
		} else {
			$cur_start  = $today->modify( 'first day of last month' );
			$cur_end    = $today->modify( 'last day of last month' );
			$prev_start = $cur_start->modify( 'first day of last month' );
			$prev_end   = $cur_start->modify( '-1 day' );
		}

		return array(
			'period'   => '28d' === $period ? '28d' : 'month',
			'current'  => array(
				'start' => $cur_start->format( 'Y-m-d' ),
				'end'   => $cur_end->format( 'Y-m-d' ),
			),
			'previous' => array(
				'start' => $prev_start->format( 'Y-m-d' ),
				'end'   => $prev_end->format( 'Y-m-d' ),
			),
		);
	}

	/**
	 * @return array|WP_Error Rows from searchAnalytics.query.
	 */
	private static function query( $property, $range, $extra = array() ) {
		$body = array_merge(
			array(
				'startDate' => $range['start'],
				'endDate'   => $range['end'],
				'type'      => 'web',
			),
			$extra
		);

		$response = self::request( 'POST', rawurlencode( $property ) . '/searchAnalytics/query', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $response['rows'] ?? array();
	}

	/**
	 * Checks the key works and the service account can see the property.
	 *
	 * @return string|WP_Error Permission level on success.
	 */
	public static function test_connection() {
		$property = SEOHW_Settings::get( 'property' );
		if ( '' === $property ) {
			return new WP_Error( 'seohw_no_property', __( 'Enter a property first.', 'seo-health-widget' ) );
		}
		delete_transient( self::TOKEN_CACHE );

		$response = self::request( 'GET', rawurlencode( $property ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $response['permissionLevel'] ?? 'unknown';
	}

	private static function request( $method, $path, $body = null ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = $data['error']['message'] ?? wp_remote_retrieve_response_message( $response );
			if ( 403 === $code || 404 === $code ) {
				$message .= ' ' . __( 'Check the property matches Search Console exactly and the service account has been added as a user.', 'seo-health-widget' );
			}
			/* translators: 1: HTTP status, 2: error message */
			return new WP_Error( 'seohw_api_error', sprintf( __( 'Search Console API error (%1$d): %2$s', 'seo-health-widget' ), $code, $message ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * OAuth access token via the JWT bearer grant, cached until shortly before expiry.
	 */
	private static function access_token() {
		$cached = get_transient( self::TOKEN_CACHE );
		if ( $cached ) {
			return $cached;
		}

		$key = SEOHW_Settings::service_account();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'seohw_no_openssl', __( 'The PHP OpenSSL extension is required.', 'seo-health-widget' ) );
		}

		$now    = time();
		$header = self::base64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::base64url(
			wp_json_encode(
				array(
					'iss'   => $key['client_email'],
					'scope' => self::SCOPE,
					'aud'   => self::TOKEN_URL,
					'iat'   => $now,
					'exp'   => $now + HOUR_IN_SECONDS,
				)
			)
		);

		$signature = '';
		$private   = openssl_pkey_get_private( $key['private_key'] );
		if ( ! $private || ! openssl_sign( $header . '.' . $claims, $signature, $private, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'seohw_sign_failed', __( 'Could not sign the token request. Is the private key intact?', 'seo-health-widget' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $header . '.' . $claims . '.' . self::base64url( $signature ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			$message = $data['error_description'] ?? ( $data['error'] ?? wp_remote_retrieve_response_message( $response ) );
			/* translators: %s: error message */
			return new WP_Error( 'seohw_token_error', sprintf( __( 'Google token error: %s', 'seo-health-widget' ), $message ) );
		}

		$ttl = max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 300 );
		set_transient( self::TOKEN_CACHE, $data['access_token'], $ttl );
		return $data['access_token'];
	}

	private static function base64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Invalidates cached reports by bumping the generation in the cache key; old
	 * transients expire on their own. Works with or without a persistent object cache.
	 */
	public static function flush_cache() {
		delete_transient( self::TOKEN_CACHE );
		update_option( 'seohw_cache_gen', (int) get_option( 'seohw_cache_gen', 1 ) + 1, false );
	}
}
