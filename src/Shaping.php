<?php
/**
 * Shared ACF field shaping helpers.
 *
 * Extracted from {@see Fields} (WP-2) so the exact same scalar/number/relation
 * reduction logic can be reused by both the `rest_prepare_*` normalizers AND
 * the precomputed `/obw/v1/finder` route (Phase 2 §4.1) without forking it —
 * the two payload shapes must never drift apart.
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker;

/**
 * Stateless static helpers for reading + reducing ACF field values to the
 * finder's minimal contract shapes.
 */
final class Shaping {

	/**
	 * Read a scalar ACF value.
	 *
	 * @return mixed
	 */
	public static function scalar( int $post_id, string $field ) {
		return function_exists( 'get_field' ) ? get_field( $field, $post_id ) : null;
	}

	/**
	 * Read a numeric ACF value as float, or null when empty.
	 */
	public static function number( int $post_id, string $field ): ?float {
		$value = self::scalar( $post_id, $field );
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * Read a relationship ACF value and reduce it to the finder's minimal shape:
	 * an ordered array of { ID, post_title, post_name }.
	 *
	 * Reads the raw stored value (post IDs) with `get_field( …, false )` so the
	 * result is deterministic regardless of the field's return_format. When
	 * `$with_status` is true (brewery/venue reverse relations) a `post_status`
	 * key is added so the finder's Brewery/Venue tabs can show only published
	 * beers.
	 *
	 * @param bool $with_status Include the related post's `post_status`.
	 * @return array<int,array<string,int|string>>
	 */
	public static function relation( int $post_id, string $field, bool $with_status = false ): array {
		$raw = function_exists( 'get_field' ) ? get_field( $field, $post_id, false ) : null;
		if ( empty( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( (array) $raw as $item ) {
			// Tolerate IDs, WP_Post objects, or ACF post arrays.
			if ( $item instanceof \WP_Post ) {
				$related_id = $item->ID;
			} elseif ( is_array( $item ) && isset( $item['ID'] ) ) {
				$related_id = (int) $item['ID'];
			} elseif ( is_object( $item ) && isset( $item->ID ) ) {
				$related_id = (int) $item->ID;
			} else {
				$related_id = (int) $item;
			}

			if ( $related_id <= 0 ) {
				continue;
			}

			$related = get_post( $related_id );
			if ( ! $related instanceof \WP_Post ) {
				continue;
			}

			$entry = [
				'ID'         => $related->ID,
				'post_title' => $related->post_title,
				'post_name'  => $related->post_name,
			];
			if ( $with_status ) {
				$entry['post_status'] = $related->post_status;
			}

			$out[] = $entry;
		}

		return $out;
	}
}
