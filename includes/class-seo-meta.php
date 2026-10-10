<?php
/**
 * Reads per-post noindex and meta description from whichever SEO plugin is active.
 */

defined( 'ABSPATH' ) || exit;

class SEOHW_SEO_Meta {

	/**
	 * Slug of the active SEO plugin, or null when none is detected.
	 */
	public static function active_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			return 'aioseo';
		}
		return null;
	}

	public static function active_plugin_label() {
		$labels = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'seopress' => 'SEOPress',
			'aioseo'   => 'All in One SEO',
		);
		$slug   = self::active_plugin();
		return $slug ? $labels[ $slug ] : null;
	}

	/**
	 * Per-post SEO data for a batch of post IDs.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, array{noindex: bool, description: string}>
	 */
	public static function for_posts( array $post_ids ) {
		$plugin = self::active_plugin();
		$out    = array();
		if ( ! $plugin || ! $post_ids ) {
			return $out;
		}

		if ( 'aioseo' === $plugin ) {
			$rows = self::aioseo_rows( $post_ids );
		} else {
			update_meta_cache( 'post', $post_ids );
		}

		foreach ( $post_ids as $id ) {
			switch ( $plugin ) {
				case 'yoast':
					$out[ $id ] = array(
						'noindex'     => '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ),
						'description' => (string) get_post_meta( $id, '_yoast_wpseo_metadesc', true ),
					);
					break;

				case 'rankmath':
					$robots     = get_post_meta( $id, 'rank_math_robots', true );
					$out[ $id ] = array(
						'noindex'     => is_array( $robots ) && in_array( 'noindex', $robots, true ),
						'description' => (string) get_post_meta( $id, 'rank_math_description', true ),
					);
					break;

				case 'seopress':
					$out[ $id ] = array(
						'noindex'     => 'yes' === get_post_meta( $id, '_seopress_robots_index', true ),
						'description' => (string) get_post_meta( $id, '_seopress_titles_desc', true ),
					);
					break;

				case 'aioseo':
					if ( isset( $rows[ $id ] ) ) {
						$row        = $rows[ $id ];
						$out[ $id ] = array(
							// robots_noindex only applies when the post overrides the default robots settings.
							'noindex'     => empty( $row->robots_default ) && ! empty( $row->robots_noindex ),
							'description' => (string) $row->description,
						);
					} else {
						// Legacy (pre-v4) post meta.
						$out[ $id ] = array(
							'noindex'     => 'on' === get_post_meta( $id, '_aioseop_noindex', true ),
							'description' => (string) get_post_meta( $id, '_aioseop_description', true ),
						);
					}
					break;
			}

			$out[ $id ]['description'] = trim( $out[ $id ]['description'] );
		}

		return $out;
	}

	private static function aioseo_rows( array $post_ids ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			update_meta_cache( 'post', $post_ids );
			return array();
		}

		$ids  = implode( ',', array_map( 'absint', $post_ids ) );
		$rows = $wpdb->get_results( "SELECT post_id, description, robots_default, robots_noindex FROM {$table} WHERE post_id IN ({$ids})", OBJECT_K ); // phpcs:ignore
		$missing = array_diff( $post_ids, array_keys( $rows ) );
		if ( $missing ) {
			update_meta_cache( 'post', $missing );
		}
		return $rows;
	}
}
