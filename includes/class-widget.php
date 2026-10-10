<?php
/**
 * The "SEO Health" dashboard widget.
 */

defined( 'ABSPATH' ) || exit;

class SEOHW_Widget {

	const ID         = 'seohw_seo_health';
	const SHOW_ITEMS = 10;

	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	private static function capability() {
		return (string) apply_filters( 'seohw_widget_capability', 'edit_posts' );
	}

	public static function register() {
		if ( current_user_can( self::capability() ) ) {
			wp_add_dashboard_widget( self::ID, __( 'SEO Health', 'seo-health-widget' ), array( __CLASS__, 'render' ) );
		}
	}

	public static function enqueue( $hook ) {
		if ( 'index.php' === $hook ) {
			wp_enqueue_style( 'seohw-widget', SEOHW_URL . 'assets/widget.css', array(), SEOHW_VERSION );
		}
	}

	public static function render() {
		$is_admin = current_user_can( 'manage_options' );

		echo '<div class="seohw">';
		self::render_search( $is_admin );
		self::render_audit( $is_admin );

		if ( $is_admin ) {
			echo '<p class="seohw-footer">';
			printf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=seohw_refresh' ), 'seohw_refresh' ) ),
				esc_html__( 'Refresh search data', 'seo-health-widget' )
			);
			echo ' &middot; ';
			printf( '<a href="%s">%s</a>', esc_url( SEOHW_Settings::settings_url() ), esc_html__( 'Settings', 'seo-health-widget' ) );
			echo '</p>';
		}
		echo '</div>';
	}

	private static function render_search( $is_admin ) {
		echo '<h3 class="seohw-heading">' . esc_html__( 'Google Search', 'seo-health-widget' ) . '</h3>';

		if ( ! SEOHW_Settings::is_configured() ) {
			echo '<p class="seohw-muted">' . esc_html__( 'Search Console is not connected yet.', 'seo-health-widget' );
			if ( $is_admin ) {
				printf( ' <a href="%s">%s</a>', esc_url( SEOHW_Settings::settings_url() ), esc_html__( 'Set it up', 'seo-health-widget' ) );
			}
			echo '</p>';
			return;
		}

		$report = SEOHW_GSC_Client::get_report();
		if ( is_wp_error( $report ) ) {
			echo '<p class="seohw-error">';
			echo $is_admin
				? esc_html( $report->get_error_message() )
				: esc_html__( 'Search data is temporarily unavailable.', 'seo-health-widget' );
			echo '</p>';
			return;
		}

		$cur  = $report['current'];
		$prev = $report['previous'];

		echo '<p class="seohw-muted seohw-range">' . esc_html( self::range_label( $report['ranges'] ) ) . '</p>';

		echo '<div class="seohw-kpis">';
		self::kpi( __( 'Clicks', 'seo-health-widget' ), number_format_i18n( $cur['clicks'] ), self::pct_change( $cur['clicks'], $prev['clicks'] ) );
		self::kpi( __( 'Impressions', 'seo-health-widget' ), number_format_i18n( $cur['impressions'] ), self::pct_change( $cur['impressions'], $prev['impressions'] ) );
		self::kpi(
			__( 'CTR', 'seo-health-widget' ),
			number_format_i18n( $cur['ctr'] * 100, 1 ) . '%',
			$prev['impressions'] ? self::delta( ( $cur['ctr'] - $prev['ctr'] ) * 100, ' pts', 1 ) : null
		);
		// Lower position is better, so a negative change is good.
		self::kpi(
			__( 'Avg. position', 'seo-health-widget' ),
			$cur['impressions'] ? number_format_i18n( $cur['position'], 1 ) : '–',
			$cur['impressions'] && $prev['impressions'] ? self::delta( $cur['position'] - $prev['position'], '', 1, true ) : null
		);
		echo '</div>';

		self::sparkline( $report['daily'] );

		if ( $report['queries'] ) {
			echo '<table class="seohw-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Top queries', 'seo-health-widget' ) . '</th>';
			echo '<th class="num">' . esc_html__( 'Clicks', 'seo-health-widget' ) . '</th>';
			echo '<th class="num">' . esc_html__( 'Impr.', 'seo-health-widget' ) . '</th>';
			echo '<th class="num">' . esc_html__( 'Pos.', 'seo-health-widget' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $report['queries'] as $q ) {
				printf(
					'<tr><td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
					esc_html( $q['query'] ),
					esc_html( number_format_i18n( $q['clicks'] ) ),
					esc_html( number_format_i18n( $q['impressions'] ) ),
					esc_html( number_format_i18n( $q['position'], 1 ) )
				);
			}
			echo '</tbody></table>';
		}
	}

	private static function range_label( $ranges ) {
		$cur  = $ranges['current'];
		$prev = $ranges['previous'];
		if ( 'month' === $ranges['period'] ) {
			/* translators: 1: last month, 2: month before */
			return sprintf( __( '%1$s vs. %2$s', 'seo-health-widget' ), self::fmt( $cur['start'], 'F Y' ), self::fmt( $prev['start'], 'F Y' ) );
		}
		/* translators: 1: start date, 2: end date */
		return sprintf( __( '28 days, %1$s – %2$s, vs. the 28 days before', 'seo-health-widget' ), self::fmt( $cur['start'], 'M j' ), self::fmt( $cur['end'], 'M j' ) );
	}

	private static function fmt( $ymd, $format ) {
		return date_i18n( $format, strtotime( $ymd . ' 12:00:00 UTC' ) );
	}

	private static function pct_change( $cur, $prev ) {
		if ( ! $prev ) {
			return null;
		}
		return self::delta( ( $cur - $prev ) / $prev * 100, '%', 0 );
	}

	/**
	 * @return array{text: string, class: string}
	 */
	private static function delta( $value, $suffix, $decimals, $lower_is_better = false ) {
		$rounded = round( $value, $decimals );
		if ( 0.0 === (float) $rounded ) {
			return array( 'text' => '±0' . $suffix, 'class' => 'flat' );
		}
		$good = $lower_is_better ? $rounded < 0 : $rounded > 0;
		return array(
			'text'  => ( $rounded > 0 ? '+' : '−' ) . number_format_i18n( abs( $rounded ), $decimals ) . $suffix,
			'class' => $good ? 'up' : 'down',
		);
	}

	private static function kpi( $label, $value, $delta ) {
		echo '<div class="seohw-kpi">';
		echo '<span class="seohw-kpi-label">' . esc_html( $label ) . '</span>';
		echo '<span class="seohw-kpi-value">' . esc_html( $value ) . '</span>';
		if ( $delta ) {
			echo '<span class="seohw-delta seohw-' . esc_attr( $delta['class'] ) . '">' . esc_html( $delta['text'] ) . '</span>';
		} else {
			echo '<span class="seohw-delta seohw-flat">&nbsp;</span>';
		}
		echo '</div>';
	}

	private static function sparkline( array $daily ) {
		if ( count( $daily ) < 2 ) {
			return;
		}
		$w      = 400;
		$h      = 60;
		$pad    = 2;
		$values = array_values( $daily );
		$max    = max( 1, max( $values ) );
		$step   = ( $w - 2 * $pad ) / ( count( $values ) - 1 );

		$points = array();
		foreach ( $values as $i => $v ) {
			$points[] = round( $pad + $i * $step, 1 ) . ',' . round( $h - $pad - ( $v / $max ) * ( $h - 2 * $pad ), 1 );
		}
		$line = implode( ' ', $points );
		$area = $pad . ',' . ( $h - $pad ) . ' ' . $line . ' ' . ( $w - $pad ) . ',' . ( $h - $pad );

		$dates = array_keys( $daily );
		/* translators: %s: peak daily clicks */
		$title = sprintf( __( 'Daily clicks (peak %s)', 'seo-health-widget' ), number_format_i18n( $max ) );

		echo '<figure class="seohw-spark">';
		printf(
			'<svg viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" role="img" aria-label="%3$s"><polygon points="%4$s" class="seohw-spark-area"/><polyline points="%5$s" class="seohw-spark-line" vector-effect="non-scaling-stroke"/></svg>',
			(int) $w,
			(int) $h,
			esc_attr( $title ),
			esc_attr( $area ),
			esc_attr( $line )
		);
		printf(
			'<figcaption><span>%s</span><span>%s</span><span>%s</span></figcaption>',
			esc_html( self::fmt( $dates[0], 'M j' ) ),
			esc_html( $title ),
			esc_html( self::fmt( end( $dates ), 'M j' ) )
		);
		echo '</figure>';
	}

	private static function render_audit( $is_admin ) {
		echo '<h3 class="seohw-heading">' . esc_html__( 'Site audit', 'seo-health-widget' ) . '</h3>';

		$audit    = get_option( SEOHW_AUDIT_OPTION );
		$progress = SEOHW_Audit::progress();

		if ( $progress ) {
			echo '<p class="seohw-muted">';
			/* translators: 1: items checked, 2: total items */
			echo esc_html( sprintf( __( 'Audit in progress: %1$s of %2$s items checked.', 'seo-health-widget' ), number_format_i18n( $progress['scanned'] ), number_format_i18n( $progress['total'] ) ) );
			if ( $audit && ! empty( $audit['finished'] ) ) {
				echo ' ' . esc_html__( 'Showing the previous results until it finishes.', 'seo-health-widget' );
			}
			echo '</p>';
		}

		if ( ! $audit || empty( $audit['finished'] ) ) {
			if ( ! $progress ) {
				echo '<p class="seohw-muted">' . esc_html__( 'The first audit has not run yet. It runs once a day.', 'seo-health-widget' ) . '</p>';
			}
			return;
		}

		echo '<ul class="seohw-checks">';

		if ( ! empty( $audit['headless'] ) ) {
			// On a headless site the WordPress back end is often hidden from Google on purpose;
			// the front end decides what gets indexed.
			self::check_row(
				'na',
				$audit['discourage']
					? __( '“Discourage search engines” is on for the WordPress back end. That\'s normal for a headless site; the front end controls indexing.', 'seo-health-widget' )
					: __( '“Discourage search engines” is off for the WordPress back end. Make sure the back-end domain isn\'t being indexed alongside the front end.', 'seo-health-widget' )
			);
		} else {
			self::check_row(
				$audit['discourage'] ? 'bad' : 'ok',
				$audit['discourage']
					? __( '“Discourage search engines” is switched on. The whole site is hidden from Google.', 'seo-health-widget' )
					: __( 'Search engines are allowed to index the site', 'seo-health-widget' ),
				$audit['discourage'] && $is_admin ? '<a href="' . esc_url( admin_url( 'options-reading.php' ) ) . '">' . esc_html__( 'Reading settings', 'seo-health-widget' ) . '</a>' : ''
			);
		}

		if ( $audit['meta_checked'] ) {
			self::list_row(
				$audit['counts']['noindex'],
				'warn',
				/* translators: %s: count */
				_n( '%s page set to noindex', '%s pages set to noindex', $audit['counts']['noindex'], 'seo-health-widget' ),
				__( 'No pages set to noindex', 'seo-health-widget' ),
				self::post_items( $audit['noindex'] )
			);
			self::list_row(
				$audit['counts']['no_meta'],
				'warn',
				/* translators: %s: count */
				_n( '%s page with no meta description', '%s pages with no meta description', $audit['counts']['no_meta'], 'seo-health-widget' ),
				__( 'Every page has a meta description', 'seo-health-widget' ),
				self::post_items( $audit['no_meta'] )
			);
		} else {
			self::check_row( 'na', __( 'Noindex and meta description checks skipped: no supported SEO plugin is active.', 'seo-health-widget' ) );
		}

		$unchecked = (int) ( $audit['counts']['unchecked'] ?? 0 );

		$broken_items = array();
		foreach ( $audit['broken'] as $b ) {
			$broken_items[] = self::link_item( $b );
		}
		self::list_row(
			$audit['counts']['broken'],
			'bad',
			/* translators: %s: count */
			_n( '%s broken internal link', '%s broken internal links', $audit['counts']['broken'], 'seo-health-widget' ),
			$unchecked
				? __( 'No broken internal links among the links that could be checked', 'seo-health-widget' )
				: __( 'No broken internal links found', 'seo-health-widget' ),
			$broken_items
		);

		// Links whose status couldn't be confirmed are listed separately, so a check that
		// failed is never mistaken for a clean result.
		if ( $unchecked ) {
			$items = array();
			foreach ( $audit['unchecked'] as $u ) {
				$items[] = self::link_item( $u );
			}
			self::list_row(
				$unchecked,
				'warn',
				/* translators: %s: count */
				_n( '%s internal link couldn’t be checked', '%s internal links couldn’t be checked', $unchecked, 'seo-health-widget' ),
				'',
				$items,
				$is_admin ? self::unchecked_hint( $audit['unchecked'] ) : ''
			);
		}

		if ( $audit['orphan_types'] ) {
			self::list_row(
				$audit['counts']['orphans'],
				'warn',
				/* translators: %s: count */
				_n( '%s orphaned page (nothing links to it)', '%s orphaned pages (nothing links to them)', $audit['counts']['orphans'], 'seo-health-widget' ),
				__( 'No orphaned pages', 'seo-health-widget' ),
				self::post_items( $audit['orphans'] )
			);
		}

		echo '</ul>';

		$notes = array();
		/* translators: %s: human time diff */
		$notes[] = sprintf( __( 'Checked %s ago', 'seo-health-widget' ), human_time_diff( $audit['finished'] ) );
		if ( $audit['scanned'] < $audit['total'] ) {
			/* translators: 1: scanned, 2: total */
			$notes[] = sprintf( __( 'first %1$s of %2$s items scanned', 'seo-health-widget' ), number_format_i18n( $audit['scanned'] ), number_format_i18n( $audit['total'] ) );
		}
		echo '<p class="seohw-muted">' . esc_html( implode( ' · ', $notes ) ) . '</p>';
	}

	private static function check_row( $status, $text, $extra_html = '' ) {
		printf(
			'<li class="seohw-check seohw-%1$s"><span class="seohw-dot" aria-hidden="true"></span><span>%2$s %3$s</span></li>',
			esc_attr( $status ),
			esc_html( $text ),
			$extra_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
	}

	/**
	 * @param string[] $items Pre-escaped HTML list items.
	 * @param string   $note  Optional plain-text note shown above the items.
	 */
	private static function list_row( $count, $bad_status, $label_plural, $ok_label, array $items, $note = '' ) {
		if ( ! $count ) {
			self::check_row( 'ok', $ok_label );
			return;
		}

		printf(
			'<li class="seohw-check seohw-%1$s"><span class="seohw-dot" aria-hidden="true"></span><details><summary>%2$s</summary><ul>',
			esc_attr( $bad_status ),
			esc_html( sprintf( $label_plural, number_format_i18n( $count ) ) )
		);
		if ( '' !== $note ) {
			echo '<li class="seohw-muted">' . esc_html( $note ) . '</li>';
		}
		foreach ( array_slice( $items, 0, self::SHOW_ITEMS ) as $item ) {
			echo '<li>' . $item . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
		if ( $count > self::SHOW_ITEMS ) {
			/* translators: %s: count */
			echo '<li class="seohw-muted">' . esc_html( sprintf( __( 'and %s more', 'seo-health-widget' ), number_format_i18n( $count - self::SHOW_ITEMS ) ) ) . '</li>';
		}
		echo '</ul></details></li>';
	}

	private static function post_items( array $ids ) {
		return array_map( array( __CLASS__, 'post_link' ), $ids );
	}

	private static function post_link( $id ) {
		$title = get_the_title( $id );
		$title = '' === $title ? sprintf( '#%d', $id ) : $title;
		$url   = current_user_can( 'edit_post', $id ) ? get_edit_post_link( $id ) : get_permalink( $id );
		return $url
			? '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>'
			: esc_html( $title );
	}

	private static function short_url( $url ) {
		$parts = wp_parse_url( $url );
		$short = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		return strlen( $short ) > 60 ? substr( $short, 0, 57 ) . '…' : $short;
	}

	/**
	 * "Post → /path (reason)" for a broken or unchecked link.
	 */
	private static function link_item( array $link ) {
		return self::post_link( $link['post'] ) . ' &rarr; <code>' . esc_html( self::short_url( $link['url'] ) ) . '</code> <span class="seohw-muted">(' . esc_html( self::reason( $link['reason'] ) ) . ')</span>';
	}

	/**
	 * Admin-facing explanation of the most likely cause of unchecked links.
	 */
	private static function unchecked_hint( array $unchecked ) {
		$reasons = array_column( $unchecked, 'reason' );
		$blocked = array_intersect( $reasons, array( 'no_response', '401', '403', '429' ) );
		if ( $blocked ) {
			return __( 'The site didn’t answer, or refused, its own link checks. A security plugin, firewall (e.g. Cloudflare) or the host may be blocking requests from the server to itself. Allow the “SEO Health Widget” user agent or the server’s IP.', 'seo-health-widget' );
		}
		if ( in_array( 'limit', $reasons, true ) ) {
			return __( 'The audit reached its limit of HTTP link checks. Raise it with the seohw_max_remote_checks filter.', 'seo-health-widget' );
		}
		return __( 'These links returned a server error when checked. They may be temporarily down.', 'seo-health-widget' );
	}

	private static function reason( $reason ) {
		switch ( $reason ) {
			case 'limit':
				return __( 'check limit reached', 'seo-health-widget' );
			case 'no_response':
				return __( 'no response', 'seo-health-widget' );
			case 'unpublished':
				return __( 'unpublished', 'seo-health-widget' );
			case 'deleted':
				return __( 'deleted', 'seo-health-widget' );
			default:
				/* translators: %s: HTTP status code */
				return sprintf( __( 'returns %s', 'seo-health-widget' ), $reason );
		}
	}
}
