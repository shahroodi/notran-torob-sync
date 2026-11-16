<?php

namespace Notran\Torob;

defined( 'ABSPATH' ) || exit;

final class Feed {
	const NAMESPACE = 'notran-torob/v3';
	const ROUTE = self::NAMESPACE . '/products';
	const LAST_REQUEST = 'nttc_last_feed_request';
	private const PAGE_SIZE = 100;
	private const SORTS = array( 'date_added_desc', 'date_updated_desc', 'product_id_desc' );
	private const CACHE_CONTROL = 'private, no-store, max-age=0';

	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/products',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => array( Auth::class, 'authorize' ),
				'callback'            => array( self::class, 'respond' ),
			)
		);
		add_filter( 'rest_post_dispatch', array( self::class, 'exclude_from_cache' ), 10, 3 );
	}

	public static function exclude_from_cache( \WP_HTTP_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_HTTP_Response {
		if ( 'POST' === $request->get_method() && '/' . self::ROUTE === untrailingslashit( $request->get_route() ) ) {
			$response->header( 'Cache-Control', self::CACHE_CONTROL );
		}
		return $response;
	}

	public static function respond( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $request->is_json_content_type() || strlen( $request->get_body() ) > 2 * MB_IN_BYTES ) {
			return self::error( 'Send a JSON request body.', 400 );
		}
		$body = json_decode( $request->get_body(), false, 12 );
		if ( ! $body instanceof \stdClass || ! (array) $body ) {
			return self::error( 'Send a JSON object with a non-empty lookup or pagination request.', 400 );
		}

		$parsed = self::parse( (array) $body );
		if ( is_string( $parsed ) ) {
			return self::error( $parsed, 400 );
		}
		if ( ! Settings::index_ready() || null === Settings::price_factor( Settings::get() ) ) {
			return self::error( 'The Torob product catalog is being prepared. Please retry shortly.', 503 );
		}

		try {
			$response = self::read( new Store(), $parsed );
		} catch ( \Throwable $error ) {
			Worker::record_error( $error );
			return self::error( 'The Torob product catalog is temporarily unavailable.', 503 );
		}

		if ( time() - (int) get_option( self::LAST_REQUEST, 0 ) >= MINUTE_IN_SECONDS ) {
			update_option( self::LAST_REQUEST, time(), false );
		}
		return self::response( $response, 200 );
	}

	private static function parse( array $body ) {
		$lookup_fields = array( 'page_uniques' => 200, 'page_urls' => 1500 );
		foreach ( $lookup_fields as $field => $limit ) {
			if ( ! array_key_exists( $field, $body ) ) {
				continue;
			}
			if ( 1 !== count( $body ) || ! is_array( $body[ $field ] ) || ! $body[ $field ] ) {
				return "Provide one non-empty $field list.";
			}

			$by_url = 'page_urls' === $field;
			$keys = array();
			foreach ( $body[ $field ] as $value ) {
				if ( ! is_string( $value ) || '' === trim( $value ) || mb_strlen( $value ) > $limit ) {
					return "Invalid value in $field.";
				}
				if ( $by_url ) {
					$key = Urls::key( $value );
					if ( '' === $key ) {
						return 'Each product URL must be absolute.';
					}
					$keys[] = $key;
					continue;
				}
				$id = self::read_id( $value );
				if ( null !== $id ) {
					$keys[] = $id;
				}
			}
			return array( 'type' => 'lookup', 'keys' => $keys, 'by_url' => $by_url );
		}

		$sort = $body['sort'] ?? null;
		if ( ! in_array( $sort, self::SORTS, true ) ) {
			return 'Provide a valid sort parameter.';
		}
		if ( array_key_exists( 'page', $body ) ) {
			if ( 2 !== count( $body ) || ! is_int( $body['page'] ) || $body['page'] < 1
				|| $body['page'] > intdiv( PHP_INT_MAX, self::PAGE_SIZE ) ) {
				return 'Provide page as a positive integer together with sort.';
			}
			return array( 'type' => 'page', 'page' => $body['page'], 'sort' => $sort );
		}

		if ( 'product_id_desc' !== $sort || array_diff( array_keys( $body ), array( 'sort', 'cursor' ) ) ) {
			return 'Cursor pagination requires product_id_desc and supports only sort and cursor.';
		}

		$before = null;
		if ( array_key_exists( 'cursor', $body ) ) {
			$before = is_string( $body['cursor'] ) ? self::read_id( $body['cursor'] ) : null;
			if ( null === $before ) {
				return 'Use the next_cursor string from the previous response.';
			}
		}
		return array( 'type' => 'cursor', 'before' => $before );
	}

	private static function read_id( string $value ): ?int {
		$id = (int) $value;
		return $id > 0 && (string) $id === $value ? $id : null;
	}

	private static function read( Store $store, array $request ): array {
		$response = array( 'api_version' => 'torob_api_v3', 'total' => null, 'max_pages' => null, 'next_cursor' => null );

		if ( 'lookup' === $request['type'] ) {
			$rows = $store->lookup( $request['keys'], $request['by_url'] );
			$response['current_page'] = 1;
			$response['total'] = count( $rows );
			$response['max_pages'] = 1;
		} elseif ( 'page' === $request['type'] ) {
			$page = $request['page'];
			$listing = $store->read_page( $page, $request['sort'], self::PAGE_SIZE );
			$rows = $listing['rows'];
			$response['current_page'] = $page;
			$response['total'] = $listing['total'];
			$response['max_pages'] = max( 1, (int) ceil( $listing['total'] / self::PAGE_SIZE ) );
		} else {
			$rows = $store->cursor( $request['before'], self::PAGE_SIZE + 1 );
			if ( count( $rows ) > self::PAGE_SIZE ) {
				array_pop( $rows );
				$response['next_cursor'] = (string) $rows[ self::PAGE_SIZE - 1 ]['wc_id'];
			}
		}
		$response['products'] = array_map( array( $store, 'payload' ), $rows );
		return $response;
	}

	private static function error( string $message, int $status ): \WP_REST_Response {
		$response = self::response( array( 'error' => $message ), $status );
		if ( 503 === $status ) {
			$response->header( 'Retry-After', '60' );
		}
		return $response;
	}

	private static function response( array $body, int $status ): \WP_REST_Response {
		$response = new \WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', self::CACHE_CONTROL );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}
}
