<?php

namespace Notran\Torob;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static $roots = array();

	public static function activate(): void {
		if ( ! self::supported() ) {
			wp_die( 'برای فعال‌سازی اتصال نوتران به ترب، ووکامرس نسخهٔ ۸٫۲ یا بالاتر را فعال کنید.', '', array( 'back_link' => true ) );
		}
		try {
			( new Store() )->install();
			add_filter( 'cron_schedules', array( Worker::class, 'schedules' ) );
			Worker::schedule();
			Settings::request_rebuild();
		} catch ( \Throwable $error ) {
			Worker::record_error( $error );
			wp_die( esc_html( $error->getMessage() ), '', array( 'back_link' => true ) );
		}
	}

	public static function deactivate(): void {
		Worker::clear();
	}

	private static function supported(): bool {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '8.2', '>=' );
	}

	public static function boot(): void {
		if ( ! self::supported() ) {
			add_action( 'admin_notices', array( self::class, 'requirements_notice' ) );
			return;
		}
		add_filter( 'cron_schedules', array( Worker::class, 'schedules' ) );
		add_action( 'init', array( self::class, 'initialize' ), 20 );
		add_action( 'rest_api_init', array( Feed::class, 'register' ) );
		add_action( 'wp', array( Landing::class, 'select' ) );
		add_action( Worker::CRON, array( Worker::class, 'run' ) );
		add_action( Worker::ONCE, array( Worker::class, 'run' ) );
		add_action( 'shutdown', array( self::class, 'flush_changes' ), 5 );

		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation' ) as $hook ) {
			add_action( $hook, array( self::class, 'observe' ), 10, 1 );
		}
		foreach ( array( 'save_post_product', 'save_post_product_variation', 'deleted_post' ) as $hook ) {
			add_action( $hook, array( self::class, 'observe' ), 10, 2 );
		}
		foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) as $hook ) {
			add_action( $hook, array( self::class, 'stock' ), 10, 1 );
		}
		foreach ( array( 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status' ) as $hook ) {
			add_action( $hook, array( self::class, 'observe' ), 10, 1 );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'meta' ), 10, 3 );
		}

		add_action( 'set_object_terms', array( self::class, 'terms' ), 10, 4 );
		add_action( 'edited_term', array( self::class, 'taxonomy_changed' ), 10, 3 );
		add_action( 'delete_term', array( self::class, 'taxonomy_changed' ), 10, 3 );

		foreach ( array( 'woocommerce_currency', 'woocommerce_tax_display_shop', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax',
			'woocommerce_default_country', 'woocommerce_default_customer_address', 'permalink_structure', 'woocommerce_permalinks', 'home' ) as $option ) {
			add_action( 'update_option_' . $option, array( Settings::class, 'request_rebuild' ), 10, 0 );
		}
		foreach ( array( 'woocommerce_tax_rate_added', 'woocommerce_tax_rate_updated', 'woocommerce_tax_rate_deleted', 'woocommerce_attribute_updated' ) as $hook ) {
			add_action( $hook, array( Settings::class, 'request_rebuild' ), 10, 0 );
		}

		if ( is_admin() ) {
			Admin::boot();
		}
	}

	public static function requirements_notice(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>اتصال نوتران به ترب به ووکامرس نسخهٔ ۸٫۲ یا بالاتر نیاز دارد.</p></div>';
		}
	}

	public static function initialize(): void {
		if ( Store::SCHEMA_VERSION !== get_option( Store::SCHEMA ) ) {
			try {
				( new Store() )->install();
				Settings::request_rebuild();
			} catch ( \Throwable $error ) {
				Worker::record_error( $error );
				return;
			}
		}
		Worker::schedule();
	}

	public static function observe( int $id, $post = null ): void {
		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $id );
		}
		if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true )
			|| 'auto-draft' === $post->post_status ) {
			return;
		}

		$root = 'product_variation' === $post->post_type ? (int) $post->post_parent : $id;
		if ( $root > 0 ) {
			self::$roots[ $root ] = $root;
		}
	}

	public static function stock( \WC_Product $product ): void {
		self::observe( $product->get_id() );
	}

	public static function meta( $meta, int $post_id, string $key ): void {
		$keys = array(
			'_price', '_regular_price', '_sale_price', '_stock', '_stock_status', '_backorders', '_manage_stock', '_thumbnail_id',
			'_product_image_gallery', '_default_attributes', '_product_attributes', '_tax_status', '_tax_class',
			Settings::TITLE_META, Settings::EXCLUDE_META,
		);
		if ( in_array( $key, $keys, true ) || 0 === strpos( $key, 'attribute_' ) ) {
			self::observe( $post_id );
		}
	}

	public static function terms( int $post_id, $terms, array $term_taxonomy_ids, string $taxonomy ): void {
		if ( in_array( $taxonomy, array( 'product_cat', 'product_visibility', 'product_type' ), true ) || 0 === strpos( $taxonomy, 'pa_' ) ) {
			self::observe( $post_id );
		}
	}

	public static function taxonomy_changed( $term_id, $term_taxonomy_id, string $taxonomy ): void {
		if ( 'product_cat' === $taxonomy || 0 === strpos( $taxonomy, 'pa_' ) ) {
			Settings::request_rebuild();
		}
	}

	public static function flush_changes(): void {
		if ( ! self::$roots || Store::SCHEMA_VERSION !== get_option( Store::SCHEMA ) ) {
			return;
		}

		try {
			( new Store() )->enqueue_roots( self::$roots );
			Worker::kick();
			self::$roots = array();
		} catch ( \Throwable $error ) {
			Worker::record_error( $error );
		}
	}
}
