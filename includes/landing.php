<?php

namespace Notran\Torob;

defined( 'ABSPATH' ) || exit;

final class Landing {
	private static $product_id = 0;
	private static $variation;
	private static $selected = array();

	public static function requested(): int {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return 0;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This public link reads a strictly validated variation ID for display.
		$value = isset( $_GET[ Urls::VARIANT ] ) && is_string( $_GET[ Urls::VARIANT ] ) ? wp_unslash( $_GET[ Urls::VARIANT ] ) : '';
		if ( ! preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return 0;
		}
		$id = (int) $value;
		return (string) $id === $value ? $id : 0;
	}

	public static function select(): void {
		$id = self::requested();
		if ( ! $id || ! is_product() || post_password_required() ) {
			return;
		}
		$parent_id = get_queried_object_id();
		$variation = wc_get_product( $id );
		if ( ! $variation instanceof \WC_Product_Variation || $variation->get_parent_id() !== $parent_id
			|| 'publish' !== $variation->get_status() || ! $variation->variation_is_visible() ) {
			return;
		}
		$parent = wc_get_product( $parent_id );
		if ( ! $parent instanceof \WC_Product_Variable ) {
			return;
		}

		$specified = $variation->get_variation_attributes( false );
		$selected = array();
		$match = array();
		foreach ( Urls::variation_options( $parent ) as $key => $values ) {
			$param = 'attribute_' . $key;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A public attribute is used for display only after an exact WooCommerce option match below.
			$raw = isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ? wp_unslash( $_GET[ $param ] ) : ( $specified[ $key ] ?? '' );
			if ( '' === $raw || ! in_array( $raw, array_map( 'strval', $values ), true ) ) {
				return;
			}
			$selected[ $key ] = $raw;
			$match[ $param ] = $raw;
		}
		$matched = \WC_Data_Store::load( 'product' )->find_matching_product_variation( $parent, $match );
		if ( $id !== (int) $matched ) {
			return;
		}

		self::$product_id = $parent_id;
		self::$variation = $variation;
		self::$selected = $selected;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Page-cache plugins recognize this convention.
			define( 'DONOTCACHEPAGE', true );
		}
		add_action( 'template_redirect', 'nocache_headers', 1 );
		add_filter( 'woocommerce_product_get_default_attributes', array( self::class, 'defaults' ), 10, 2 );
		add_filter( 'woocommerce_dropdown_variation_attribute_options_args', array( self::class, 'dropdown' ), 10, 1 );
		add_filter( 'woocommerce_variable_price_html', array( self::class, 'price_html' ), 15, 2 );
		add_filter( 'woocommerce_product_get_image_id', array( self::class, 'image_id' ), 15, 2 );
	}

	public static function defaults( array $attributes, \WC_Product $product ): array {
		if ( self::$product_id === $product->get_id() ) {
			return self::$selected + $attributes;
		}
		return $attributes;
	}

	public static function dropdown( array $arguments ): array {
		$product = $arguments['product'] ?? null;
		if ( $product instanceof \WC_Product && self::$product_id === $product->get_id() ) {
			$key = sanitize_title( $arguments['attribute'] ?? '' );
			if ( isset( self::$selected[ $key ] ) ) {
				$arguments['selected'] = self::$selected[ $key ];
			}
		}
		return $arguments;
	}

	public static function price_html( string $html, \WC_Product $product ): string {
		return self::$product_id === $product->get_id() ? self::$variation->get_price_html() : $html;
	}

	public static function image_id( $image_id, \WC_Product $product ) {
		if ( self::$product_id === $product->get_id() ) {
			return self::$variation->get_image_id( 'edit' ) ?: $image_id;
		}
		return $image_id;
	}
}
