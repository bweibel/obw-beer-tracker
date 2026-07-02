<?php
/**
 * Custom post type registrar.
 *
 * Owns the three data-model CPTs that used to be registered by the theme
 * (`library/{beer,venue,brewery}.php`): `obw_beer`, `obw_venue`,
 * `obw_brewery`. The `obw_news` / `obw_sponsor` CPTs intentionally stay in the
 * theme and are NOT registered here.
 *
 * The arg arrays below are a faithful port of the theme definitions — labels,
 * supports, menu placement, rewrite slugs (`brew` / `venue` / `brewery`) and
 * `show_in_rest => true` are preserved exactly so existing permalinks and the
 * REST-backed finder keep working. WP-2 hangs the ACF bidirectional
 * relationship fields off these post types (and relies on `show_in_rest`).
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker;

/**
 * Registers the plugin-owned custom post types on `init` priority 0.
 */
final class PostTypes {

	/**
	 * Text domain used for CPT labels.
	 *
	 * Kept identical to the strings previously used by the theme so existing
	 * translations continue to resolve during the transition.
	 */
	private const TEXT_DOMAIN = 'obw-beer-tracker';

	/**
	 * Wire the registrar into WordPress.
	 *
	 * Hooked at priority 0 to match the theme's historical timing, ensuring the
	 * post types exist before anything on the default `init` priority runs.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ], 0 );
	}

	/**
	 * Register all plugin-owned CPTs.
	 */
	public function register(): void {
		$this->register_beer();
		$this->register_venue();
		$this->register_brewery();
	}

	/**
	 * Register the `obw_beer` (Brews) post type.
	 */
	private function register_beer(): void {
		if ( post_type_exists( 'obw_beer' ) ) {
			return;
		}

		$labels = [
			'name'                  => _x( 'Brews', 'Brew General Name', self::TEXT_DOMAIN ),
			'singular_name'         => _x( 'Brew', 'Brew Singular Name', self::TEXT_DOMAIN ),
			'menu_name'             => __( 'Brews', self::TEXT_DOMAIN ),
			'name_admin_bar'        => __( 'Brew', self::TEXT_DOMAIN ),
			'archives'              => __( 'Item Archives', self::TEXT_DOMAIN ),
			'parent_item_colon'     => __( 'Parent Item:', self::TEXT_DOMAIN ),
			'all_items'             => __( 'All Brews', self::TEXT_DOMAIN ),
			'add_new_item'          => __( 'Add New Brew', self::TEXT_DOMAIN ),
			'add_new'               => __( 'Add New', self::TEXT_DOMAIN ),
			'new_item'              => __( 'New Brew', self::TEXT_DOMAIN ),
			'edit_item'             => __( 'Edit Brew', self::TEXT_DOMAIN ),
			'update_item'           => __( 'Update Brew', self::TEXT_DOMAIN ),
			'view_item'             => __( 'View Brew', self::TEXT_DOMAIN ),
			'search_items'          => __( 'Search Brew', self::TEXT_DOMAIN ),
			'not_found'             => __( 'Not found', self::TEXT_DOMAIN ),
			'not_found_in_trash'    => __( 'Not found in Trash', self::TEXT_DOMAIN ),
			'featured_image'        => __( 'Featured Image', self::TEXT_DOMAIN ),
			'set_featured_image'    => __( 'Set featured image', self::TEXT_DOMAIN ),
			'remove_featured_image' => __( 'Remove featured image', self::TEXT_DOMAIN ),
			'use_featured_image'    => __( 'Use as featured image', self::TEXT_DOMAIN ),
			'insert_into_item'      => __( 'Insert into item', self::TEXT_DOMAIN ),
			'uploaded_to_this_item' => __( 'Uploaded to this item', self::TEXT_DOMAIN ),
			'items_list'            => __( 'Items list', self::TEXT_DOMAIN ),
			'items_list_navigation' => __( 'Items list navigation', self::TEXT_DOMAIN ),
			'filter_items_list'     => __( 'Filter items list', self::TEXT_DOMAIN ),
		];

		$args = [
			'label'               => __( 'Brew', self::TEXT_DOMAIN ),
			'description'         => __( 'Brew Description', self::TEXT_DOMAIN ),
			'labels'              => $labels,
			'supports'            => [ 'title', 'editor', 'thumbnail', 'revisions', 'comments' ],
			'taxonomies'          => [ 'post_tag' ],
			'hierarchical'        => false,
			'public'              => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_position'       => 7,
			'menu_icon'           => 'dashicons-nametag',
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => true,
			'show_in_rest'        => true,
			'can_export'          => true,
			'has_archive'         => true,
			'exclude_from_search' => false,
			'publicly_queryable'  => true,
			'capability_type'     => 'page',
			'rewrite'             => [
				'slug'       => 'brew',
				'with_front' => false,
			],
		];

		register_post_type( 'obw_beer', $args );
	}

	/**
	 * Register the `obw_venue` (Venues) post type.
	 */
	private function register_venue(): void {
		if ( post_type_exists( 'obw_venue' ) ) {
			return;
		}

		$labels = [
			'name'                  => _x( 'Venues', 'Venue General Name', self::TEXT_DOMAIN ),
			'singular_name'         => _x( 'Venue', 'Venue Singular Name', self::TEXT_DOMAIN ),
			'menu_name'             => __( 'Venues', self::TEXT_DOMAIN ),
			'name_admin_bar'        => __( 'Venue', self::TEXT_DOMAIN ),
			'archives'              => __( 'Item Archives', self::TEXT_DOMAIN ),
			'parent_item_colon'     => __( 'Parent Item:', self::TEXT_DOMAIN ),
			'all_items'             => __( 'All Venues', self::TEXT_DOMAIN ),
			'add_new_item'          => __( 'Add New Venue', self::TEXT_DOMAIN ),
			'add_new'               => __( 'Add New', self::TEXT_DOMAIN ),
			'new_item'              => __( 'New Venue', self::TEXT_DOMAIN ),
			'edit_item'             => __( 'Edit Venue', self::TEXT_DOMAIN ),
			'update_item'           => __( 'Update Venue', self::TEXT_DOMAIN ),
			'view_item'             => __( 'View Venue', self::TEXT_DOMAIN ),
			'search_items'          => __( 'Search Venue', self::TEXT_DOMAIN ),
			'not_found'             => __( 'Not found', self::TEXT_DOMAIN ),
			'not_found_in_trash'    => __( 'Not found in Trash', self::TEXT_DOMAIN ),
			'featured_image'        => __( 'Featured Image', self::TEXT_DOMAIN ),
			'set_featured_image'    => __( 'Set featured image', self::TEXT_DOMAIN ),
			'remove_featured_image' => __( 'Remove featured image', self::TEXT_DOMAIN ),
			'use_featured_image'    => __( 'Use as featured image', self::TEXT_DOMAIN ),
			'insert_into_item'      => __( 'Insert into item', self::TEXT_DOMAIN ),
			'uploaded_to_this_item' => __( 'Uploaded to this item', self::TEXT_DOMAIN ),
			'items_list'            => __( 'Items list', self::TEXT_DOMAIN ),
			'items_list_navigation' => __( 'Items list navigation', self::TEXT_DOMAIN ),
			'filter_items_list'     => __( 'Filter items list', self::TEXT_DOMAIN ),
		];

		$args = [
			'label'               => __( 'Venue', self::TEXT_DOMAIN ),
			'description'         => __( 'Venue Description', self::TEXT_DOMAIN ),
			'labels'              => $labels,
			'supports'            => [ 'title', 'editor', 'thumbnail', 'revisions', 'comments' ],
			'taxonomies'          => [ 'post_tag' ],
			'hierarchical'        => false,
			'public'              => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_position'       => 5,
			'menu_icon'           => 'dashicons-admin-multisite',
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => true,
			'can_export'          => true,
			'show_in_rest'        => true,
			'has_archive'         => true,
			'exclude_from_search' => false,
			'publicly_queryable'  => true,
			'capability_type'     => 'page',
			'rewrite'             => [
				'slug'       => 'venue',
				'with_front' => false,
			],
		];

		register_post_type( 'obw_venue', $args );
	}

	/**
	 * Register the `obw_brewery` (Breweries) post type.
	 *
	 * Note: the theme intentionally registers this CPT with an empty `supports`
	 * array; that is preserved here rather than "fixed".
	 */
	private function register_brewery(): void {
		if ( post_type_exists( 'obw_brewery' ) ) {
			return;
		}

		$labels = [
			'name'                  => _x( 'Breweries', 'Brewery General Name', self::TEXT_DOMAIN ),
			'singular_name'         => _x( 'Brewery', 'Brewery Singular Name', self::TEXT_DOMAIN ),
			'menu_name'             => __( 'Breweries', self::TEXT_DOMAIN ),
			'name_admin_bar'        => __( 'Brewery', self::TEXT_DOMAIN ),
			'archives'              => __( 'Item Archives', self::TEXT_DOMAIN ),
			'parent_item_colon'     => __( 'Parent Item:', self::TEXT_DOMAIN ),
			'all_items'             => __( 'All Breweries', self::TEXT_DOMAIN ),
			'add_new_item'          => __( 'Add New Brewery', self::TEXT_DOMAIN ),
			'add_new'               => __( 'Add New', self::TEXT_DOMAIN ),
			'new_item'              => __( 'New Brewery', self::TEXT_DOMAIN ),
			'edit_item'             => __( 'Edit Brewery', self::TEXT_DOMAIN ),
			'update_item'           => __( 'Update Brewery', self::TEXT_DOMAIN ),
			'view_item'             => __( 'View Brewery', self::TEXT_DOMAIN ),
			'search_items'          => __( 'Search Brewery', self::TEXT_DOMAIN ),
			'not_found'             => __( 'Not found', self::TEXT_DOMAIN ),
			'not_found_in_trash'    => __( 'Not found in Trash', self::TEXT_DOMAIN ),
			'featured_image'        => __( 'Featured Image', self::TEXT_DOMAIN ),
			'set_featured_image'    => __( 'Set featured image', self::TEXT_DOMAIN ),
			'remove_featured_image' => __( 'Remove featured image', self::TEXT_DOMAIN ),
			'use_featured_image'    => __( 'Use as featured image', self::TEXT_DOMAIN ),
			'insert_into_item'      => __( 'Insert into item', self::TEXT_DOMAIN ),
			'uploaded_to_this_item' => __( 'Uploaded to this item', self::TEXT_DOMAIN ),
			'items_list'            => __( 'Items list', self::TEXT_DOMAIN ),
			'items_list_navigation' => __( 'Items list navigation', self::TEXT_DOMAIN ),
			'filter_items_list'     => __( 'Filter items list', self::TEXT_DOMAIN ),
		];

		$args = [
			'label'               => __( 'Brewery', self::TEXT_DOMAIN ),
			'description'         => __( 'Brewery Description', self::TEXT_DOMAIN ),
			'labels'              => $labels,
			'supports'            => [],
			'taxonomies'          => [ 'post_tag' ],
			'hierarchical'        => false,
			'public'              => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_position'       => 6,
			'menu_icon'           => 'dashicons-networking',
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => true,
			'show_in_rest'        => true,
			'can_export'          => true,
			'has_archive'         => true,
			'exclude_from_search' => false,
			'publicly_queryable'  => true,
			'capability_type'     => 'page',
			'rewrite'             => [
				'slug'       => 'brewery',
				'with_front' => false,
			],
		];

		register_post_type( 'obw_brewery', $args );
	}
}
