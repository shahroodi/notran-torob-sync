<?php

namespace Notran\Torob;

defined( 'ABSPATH' ) || exit;

final class Urls {
	const VARIANT = 'nttc_variant';

	public static function authority( string $url ): string {
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || isset( $parsed['user'] ) || isset( $parsed['pass'] )
			|| ! in_array( strtolower( $parsed['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		return strtolower( $parsed['host'] ) . ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' );
	}

	// Match reordered query parameters and recognized tracking parameters without fetching the URL.
	public static function key( string $url ): string {
		$authority = self::authority( $url );
		if ( '' === $authority ) {
			return '';
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$segments = array_map( 'rawurldecode', explode( '/', rtrim( $path, '/' ) ) );
		$segments = array_map( 'rawurlencode', $segments );
		$parts = self::query( $url );
		foreach ( $parts as $key => $value ) {
			if ( 0 === stripos( (string) $key, 'utm_' ) || in_array( $key, array( 'gclid', 'fbclid' ), true ) ) {
				unset( $parts[ $key ] );
			}
		}
		ksort( $parts );

		return hash( 'sha256', $authority . '/' . implode( '/', $segments ) . '?' . http_build_query( $parts, '', '&', PHP_QUERY_RFC3986 ) );
	}

	public static function query( string $url ): array {
		$parsed = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $parsed );
		return $parsed;
	}

	public static function variation_options( \WC_Product_Variable $product ): array {
		$options = array();
		foreach ( $product->get_variation_attributes() as $name => $values ) {
			$options[ sanitize_title( $name ) ] = $values;
		}
		return $options;
	}

	public static function variation( \WC_Product_Variable $parent, int $id, array $attributes ): string {
		// Values from WooCommerce are sometimes already URL-encoded (localized taxonomy slugs).
		$query = array();
		foreach ( $attributes as $name => $value ) {
			$query[ urlencode( 'attribute_' . $name ) ] = urlencode( $value );
		}
		$query[ self::VARIANT ] = $id;

		return add_query_arg( $query, $parent->get_permalink() );
	}
}
