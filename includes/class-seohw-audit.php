<?php
/**
 * Daily on-site SEO audit: indexing switch, noindex, meta descriptions, broken internal links, orphans.
 */

defined( 'ABSPATH' ) || exit;

class SEOHW_Audit {

	const LOCK          = 'seohw_audit_lock';
	const STATE_OPTION  = 'seohw_audit_state';
	const CONTINUE_HOOK = 'seohw_audit_continue';
	const BATCH         = 50;
	const TIME_BUDGET   = 20; // Seconds of work per request before handing off to the next one.
	const STALE_AFTER   = HOUR_IN_SECONDS; // A run with no progress for this long is restarted.
	const LIST_LIMIT    = 100; // Max items stored per issue list; totals are kept separately.

	/** @var array Progress of the current run, saved between requests. */
	private $state;

	/** @var string[] */
	private $internal_hosts = array();

	private $remote_budget = 0;

	public static function init() {
		add_action( SEOHW_CRON_HOOK, array( __CLASS__, 'start' ) );
		add_action( self::CONTINUE_HOOK, array( __CLASS__, 'resume' ) );
		// Covers installs where the activation hook didn't fire (e.g. must-use or bulk-deployed).
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( SEOHW_CRON_HOOK ) ) {
					self::schedule();
				}
			}
		);
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( SEOHW_CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', SEOHW_CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( SEOHW_CRON_HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CONTINUE_HOOK );
		}
		delete_option( self::STATE_OPTION );
	}

	/**
	 * Starts a new audit, unless one is already making progress, and runs its first chunk.
	 *
	 * @param bool $restart Discard any audit in progress and start over.
	 * @return string 'done' when finished in this request, otherwise 'running' or 'locked'.
	 */
	public static function start( $restart = false ) {
		$state = get_option( self::STATE_OPTION );
		if ( ! $restart && $state && time() - $state['updated'] < self::STALE_AFTER ) {
			self::schedule_continue(); // In case the hand-off was lost.
			return 'running';
		}

		update_option( self::STATE_OPTION, self::new_state(), false );
		return self::resume();
	}

	/**
	 * Runs the next chunk of the current audit. Hooked to the continuation event.
	 */
	public static function resume() {
		if ( get_transient( self::LOCK ) ) {
			return 'locked';
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$state = get_option( self::STATE_OPTION );
			if ( ! $state ) {
				return 'done';
			}

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$audit = new self( $state );
			if ( $audit->process( microtime( true ) + (float) apply_filters( 'seohw_audit_time_budget', self::TIME_BUDGET ) ) ) {
				update_option( SEOHW_AUDIT_OPTION, $audit->results(), false );
				delete_option( self::STATE_OPTION );
				return 'done';
			}

			$audit->state['updated'] = time();
			update_option( self::STATE_OPTION, $audit->state, false );
		} finally {
			delete_transient( self::LOCK );
		}

		self::schedule_continue();
		return 'running';
	}

	/**
	 * Progress of a running audit for display, or null when none is running.
	 *
	 * @return array{scanned: int, total: int, started: int}|null
	 */
	public static function progress() {
		$state = get_option( self::STATE_OPTION );
		if ( ! $state ) {
			return null;
		}
		return array(
			'scanned' => (int) $state['scanned'],
			'total'   => (int) min( $state['total'], $state['max_items'] ),
			'started' => (int) $state['started'],
		);
	}

	/**
	 * Queues the next chunk a few seconds from now. Action Scheduler (bundled with WooCommerce
	 * and many other plugins) runs it more reliably than WP-Cron on low-traffic sites.
	 */
	private static function schedule_continue() {
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::CONTINUE_HOOK ) ) {
				as_schedule_single_action( time() + 5, self::CONTINUE_HOOK, array(), 'seo-health-widget' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CONTINUE_HOOK );
		}
	}

	private static function new_state() {
		$post_types = self::audit_post_types();
		return array(
			'started'           => time(),
			'updated'           => time(),
			'post_types'        => $post_types,
			'orphan_types'      => array_values( array_intersect( (array) apply_filters( 'seohw_orphan_post_types', array( 'page' ) ), $post_types ) ),
			'max_items'         => (int) SEOHW_Settings::get( 'max_items' ),
			'total'             => self::count_items( $post_types ),
			'cursor'            => 0, // Highest post ID processed so far.
			'scanned'           => 0,
			'counts'            => array( 'noindex' => 0, 'no_meta' => 0, 'broken' => 0 ),
			'noindex'           => array(),
			'no_meta'           => array(),
			'broken'            => array(),
			'linked'            => array(), // Post IDs that something links to, as keys.
			'orphan_candidates' => array(),
			'remote_cache'      => array(), // URL => status code, or false when unreachable.
			'remote_checked'    => 0,
			'remote_skipped'    => 0,
		);
	}

	private function __construct( array $state ) {
		$this->state          = $state;
		$this->internal_hosts = SEOHW_Settings::internal_hosts();
		$this->remote_budget  = max( 0, (int) apply_filters( 'seohw_max_remote_checks', 50 ) );
	}

	/**
	 * Audits posts in ID order until the deadline passes or every item is done. Progress is
	 * tracked per post, so the next request picks up exactly where this one stopped.
	 *
	 * @return bool True when the audit is complete.
	 */
	private function process( $deadline ) {
		$seo_plugin = SEOHW_SEO_Meta::active_plugin();

		while ( $this->state['scanned'] < $this->state['max_items'] ) {
			$ids = $this->next_ids( min( self::BATCH, $this->state['max_items'] - $this->state['scanned'] ) );
			if ( ! $ids ) {
				break;
			}

			_prime_post_caches( $ids, false, false );
			$meta = $seo_plugin ? SEOHW_SEO_Meta::for_posts( $ids ) : array();

			foreach ( $ids as $id ) {
				$this->audit_post( get_post( $id ), $meta[ $id ] ?? null );
				$this->state['cursor'] = $id;
				++$this->state['scanned'];

				if ( microtime( true ) >= $deadline ) {
					return false;
				}
			}
		}

		return true;
	}

	private function next_ids( $limit ) {
		global $wpdb;
		$types = $this->state['post_types'];
		if ( ! $types ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders) AND ID > %d ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( $types, array( (int) $this->state['cursor'], (int) $limit ) )
				)
			)
		);
	}

	private static function count_items( array $types ) {
		global $wpdb;
		if ( ! $types ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$types
			)
		);
	}

	private function audit_post( $post, $meta ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( $meta ) {
			if ( $meta['noindex'] ) {
				$this->add_issue( 'noindex', $post->ID );
			} elseif ( '' === $meta['description'] ) {
				// Noindexed pages don't need a description, so they're only reported once.
				$this->add_issue( 'no_meta', $post->ID );
			}
		}

		if ( in_array( $post->post_type, $this->state['orphan_types'], true ) ) {
			$this->state['orphan_candidates'][] = $post->ID;
		}

		foreach ( $this->check_links( $post ) as $result ) {
			if ( $result['target'] && $result['target'] !== $post->ID ) {
				$this->state['linked'][ $result['target'] ] = true;
			}
			if ( $result['broken'] ) {
				$this->add_issue(
					'broken',
					array(
						'post'   => $post->ID,
						'url'    => $result['url'],
						'reason' => $result['broken'],
					)
				);
			}
		}
	}

	private function add_issue( $type, $item ) {
		++$this->state['counts'][ $type ];
		if ( count( $this->state[ $type ] ) < self::LIST_LIMIT ) {
			$this->state[ $type ][] = $item;
		}
	}

	private function results() {
		$linked = $this->state['linked'];
		foreach ( $this->menu_targets() as $id ) {
			$linked[ $id ] = true;
		}

		$exempt  = array_filter( array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );
		$orphans = array_values(
			array_filter(
				$this->state['orphan_candidates'],
				static function ( $id ) use ( $linked, $exempt ) {
					return ! isset( $linked[ $id ] ) && ! in_array( $id, $exempt, true );
				}
			)
		);

		return array(
			'started'        => $this->state['started'],
			'finished'       => time(),
			'seo_plugin'     => SEOHW_SEO_Meta::active_plugin_label(),
			'scanned'        => $this->state['scanned'],
			'total'          => $this->state['total'],
			'headless'       => SEOHW_Settings::is_headless(),
			'discourage'     => '0' === (string) get_option( 'blog_public' ),
			'meta_checked'   => (bool) SEOHW_SEO_Meta::active_plugin(),
			'orphan_types'   => $this->state['orphan_types'],
			'remote_checked' => $this->state['remote_checked'],
			'remote_skipped' => $this->state['remote_skipped'],
			'counts'         => $this->state['counts'] + array( 'orphans' => count( $orphans ) ),
			'noindex'        => $this->state['noindex'],
			'no_meta'        => $this->state['no_meta'],
			'broken'         => $this->state['broken'],
			'orphans'        => array_slice( $orphans, 0, self::LIST_LIMIT ),
		);
	}

	private static function audit_post_types() {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		$types = (array) apply_filters( 'seohw_audit_post_types', array_values( $types ) );
		return array_values( array_filter( $types, 'post_type_exists' ) );
	}

	/**
	 * Checks each unique internal link in a post's content.
	 *
	 * @return array[] Each with url, target (post ID or 0) and broken (reason or '').
	 */
	private function check_links( WP_Post $post ) {
		if ( false === stripos( $post->post_content, 'href' ) ) {
			return array();
		}
		preg_match_all( '/<a\s[^>]*?href\s*=\s*(["\'])(.*?)\1/is', $post->post_content, $matches );

		$results = array();
		$base    = get_permalink( $post );
		foreach ( array_unique( $matches[2] ) as $href ) {
			$url = $this->normalize( html_entity_decode( trim( $href ), ENT_QUOTES ), $base );
			if ( ! $url || isset( $results[ $url ] ) ) {
				continue;
			}
			$results[ $url ] = $this->check_url( $url );
		}
		return array_values( $results );
	}

	/**
	 * Absolute internal URL without fragment, or null for external / non-HTTP links.
	 */
	private function normalize( $href, $base ) {
		if ( '' === $href || '#' === $href[0] || preg_match( '#^(mailto|tel|sms|javascript|data|ftp):#i', $href ) ) {
			return null;
		}

		if ( 0 === strpos( $href, '//' ) ) {
			$href = 'https:' . $href;
		} elseif ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) ) {
			$home = wp_parse_url( home_url() );
			$root = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
			if ( '/' === $href[0] ) {
				$href = $root . $href;
			} else {
				$dir  = preg_replace( '#[^/]*$#', '', (string) $base );
				$href = $dir . $href;
			}
		}

		$parts = wp_parse_url( $href );
		if ( empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		if ( ! in_array( preg_replace( '/^www\./', '', strtolower( $parts['host'] ) ), $this->internal_hosts, true ) ) {
			return null;
		}

		return strtok( $href, '#' );
	}

	private function check_url( $url ) {
		$result = array(
			'url'    => $url,
			'target' => 0,
			'broken' => '',
		);

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#/(wp-admin|wp-login\.php|wp-json|feed)(/|$)#', $path ) ) {
			return $result;
		}

		$id = $this->resolve_id( $url );

		if ( $id ) {
			$status = get_post_status( $id );
			if ( false === $status ) {
				$result['broken'] = 'deleted';
			} elseif ( 'publish' !== $status ) {
				$result['broken'] = 'unpublished';
			} else {
				$result['target'] = $id;
			}
			return $result;
		}

		$home_path = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' === trim( substr( $path, strlen( $home_path ) ), '/' ) ) {
			return $result; // Home page.
		}

		if ( $this->is_unpublished_slug( $path ) ) {
			$result['broken'] = 'unpublished';
			return $result;
		}

		$status = $this->remote_status( $url );
		if ( 404 === $status || 410 === $status ) {
			$result['broken'] = (string) $status;
		}
		return $result;
	}

	/**
	 * Post ID for an internal URL. Front-end hosts are mapped onto the WordPress host
	 * first, since url_to_postid() only recognises the home URL's host.
	 */
	private function resolve_id( $url ) {
		$home   = wp_parse_url( home_url() );
		$origin = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		return (int) url_to_postid( preg_replace( '#^https?://[^/]+#i', $origin, $url ) );
	}

	/**
	 * True when the last path segment matches a draft, pending, private, scheduled or trashed
	 * post and no published post. Catches links to content taken down after it was linked.
	 */
	private function is_unpublished_slug( $path ) {
		global $wpdb;
		$slug = sanitize_title( basename( untrailingslashit( $path ) ) );
		if ( '' === $slug ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$statuses = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_status FROM {$wpdb->posts} WHERE post_name IN (%s, %s) AND post_type NOT IN ('revision', 'nav_menu_item', 'attachment')",
				$slug,
				$slug . '__trashed'
			)
		);
		return $statuses && ! in_array( 'publish', $statuses, true );
	}

	/**
	 * HTTP status for a URL, limited by the seohw_max_remote_checks budget per audit.
	 *
	 * Checks go to the public site: on a headless site, the URL is rewritten onto the
	 * front-end URL, since the WordPress back end may not serve pages at all. The safe
	 * HTTP functions refuse private and internal addresses, including after redirects.
	 *
	 * @return int|false Status code, or false when unchecked or unreachable.
	 */
	private function remote_status( $url ) {
		$url = preg_replace( '#^https?://[^/]+#i', SEOHW_Settings::public_origin(), $url );

		if ( array_key_exists( $url, $this->state['remote_cache'] ) ) {
			return $this->state['remote_cache'][ $url ];
		}
		if ( $this->state['remote_checked'] >= $this->remote_budget ) {
			++$this->state['remote_skipped'];
			return false;
		}
		++$this->state['remote_checked'];

		$args = array(
			'timeout'     => 5,
			'redirection' => 3,
			'user-agent'  => 'SEO Health Widget/' . SEOHW_VERSION . '; ' . home_url(),
		);

		$response = wp_safe_remote_head( $url, $args );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( ! $code || 405 === $code || 501 === $code ) {
			$response = wp_safe_remote_get( $url, $args + array( 'limit_response_size' => 1024 ) );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		}

		$this->state['remote_cache'][ $url ] = $code ? $code : false;
		return $this->state['remote_cache'][ $url ];
	}

	/**
	 * Post IDs linked from classic menus and block-theme navigation.
	 *
	 * @return int[]
	 */
	private function menu_targets() {
		$ids = array();

		foreach ( wp_get_nav_menus() as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
				if ( 'post_type' === $item->type ) {
					$ids[] = (int) $item->object_id;
				} elseif ( 'custom' === $item->type ) {
					$url = $this->normalize( $item->url, home_url( '/' ) );
					if ( $url ) {
						$ids[] = $this->resolve_id( $url );
					}
				}
			}
		}

		if ( post_type_exists( 'wp_navigation' ) ) {
			$navs = get_posts(
				array(
					'post_type'      => 'wp_navigation',
					'post_status'    => 'publish',
					'posts_per_page' => 50,
				)
			);
			foreach ( $navs as $nav ) {
				if ( preg_match_all( '#<!--\s*wp:navigation-(?:link|submenu)\s+(\{.*?\})\s*/?-->#s', $nav->post_content, $m ) ) {
					foreach ( $m[1] as $json ) {
						$attrs = json_decode( $json, true );
						if ( ! empty( $attrs['id'] ) ) {
							$ids[] = (int) $attrs['id'];
						} elseif ( ! empty( $attrs['url'] ) ) {
							$url = $this->normalize( $attrs['url'], home_url( '/' ) );
							if ( $url ) {
								$ids[] = $this->resolve_id( $url );
							}
						}
					}
				}
			}
		}

		return array_filter( array_unique( $ids ) );
	}
}
