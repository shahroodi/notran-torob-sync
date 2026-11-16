<?php

namespace Notran\Torob;

defined( 'ABSPATH' ) || exit;

final class Auth {
	// Ed25519 SubjectPublicKeyInfo, published in the Torob Product API v3 token guide.
	private const TOROB_PUBLIC_KEY = 'MCowBQYDK2VwAyEAt6Mu4T0pBORY11W+QeM35UsmLO3vsf+6yKpFDEImFk0=';
	private const ED25519_DER_PREFIX = '302a300506032b6570032100';
	public const STATUS_PREFIX = 'nttc_auth_';
	public const RESULTS = array(
		'accepted', 'missing_token', 'unsupported_version', 'invalid_format', 'invalid_signature',
		'audience_mismatch', 'invalid_time_claims', 'not_yet_valid', 'expired', 'configuration',
	);

	public static function authorize( \WP_REST_Request $request ) {
		$now = time();
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			self::record( 'configuration', $now );
			return new \WP_Error( 'nttc_sodium', 'PHP sodium is required for Torob authentication.', array( 'status' => 503 ) );
		}

		$audience = self::audience( $request );
		$key = self::public_key();
		if ( '' === $key || '' === $audience ) {
			self::record( 'configuration', $now );
			return new \WP_Error( 'nttc_auth_configuration', 'Torob feed authentication is unavailable.', array( 'status' => 503 ) );
		}

		$token = self::read_header( $request, 'x-torob-token' );
		$version = self::read_header( $request, 'x-torob-token-version' );
		$result = self::check( $token, $audience, $key, $now, $version );
		self::record( $result, $now );
		if ( 'accepted' !== $result ) {
			return new \WP_Error( 'nttc_unauthorized', 'Invalid or expired Torob token.', array( 'status' => 401 ) );
		}

		return true;
	}

	private static function read_header( \WP_REST_Request $request, string $name ): string {
		$value = trim( (string) $request->get_header( $name ) );
		if ( '' !== $value ) {
			return $value;
		}

		// Apache internal redirects can retain custom request headers with this prefix.
		$server_key = 'REDIRECT_HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve the token bytes for signature verification.
		$redirected = isset( $_SERVER[ $server_key ] ) && is_string( $_SERVER[ $server_key ] ) ? wp_unslash( $_SERVER[ $server_key ] ) : '';
		return trim( $redirected );
	}

	private static function audience( \WP_REST_Request $request ): string {
		$url = rest_url( Feed::ROUTE );
		$canonical = Urls::authority( $url );
		$incoming = trim( (string) $request->get_header( 'host' ) );
		if ( '' === $canonical || '' === $incoming ) {
			return $canonical;
		}

		$parts = wp_parse_url( $url );
		$hostname = strtolower( $parts['host'] );
		$default_port = 'https' === strtolower( $parts['scheme'] ) ? 443 : 80;
		$port = $parts['port'] ?? $default_port;
		$hosts = array( $hostname );

		if ( false !== strpos( $hostname, '.' ) && ! filter_var( trim( $hostname, '[]' ), FILTER_VALIDATE_IP ) ) {
			$hosts[] = 0 === strpos( $hostname, 'www.' ) ? substr( $hostname, 4 ) : 'www.' . $hostname;
		}

		// The requested authority must belong to the configured site. Never trust a forwarded host.
		$normalized = strtolower( $incoming );
		foreach ( $hosts as $host ) {
			if ( $normalized === $host . ':' . $port || ( $port === $default_port && $normalized === $host ) ) {
				return $incoming;
			}
		}

		// A reverse proxy can supply an internal Host. Keep the public REST authority in that case.
		return $canonical;
	}

	private static function public_key(): string {
		$der = base64_decode( self::TOROB_PUBLIC_KEY, true );
		$prefix = hex2bin( self::ED25519_DER_PREFIX );

		if ( ! $der || strlen( $der ) !== strlen( $prefix ) + SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
			|| 0 !== strncmp( $der, $prefix, strlen( $prefix ) ) ) {
			return '';
		}

		return substr( $der, strlen( $prefix ) );
	}

	public static function verify( string $jwt, string $audience, string $public_key, int $now ): bool {
		return 'accepted' === self::check( $jwt, $audience, $public_key, $now );
	}

	private static function check( string $jwt, string $audience, string $public_key, int $now, string $version = '' ): string {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) || strlen( $public_key ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
			return 'configuration';
		}
		if ( '' === $jwt ) {
			return 'missing_token';
		}
		if ( '' !== $version && '1' !== $version ) {
			return 'unsupported_version';
		}
		if ( strlen( $jwt ) > 8192 ) {
			return 'invalid_format';
		}
		$parts = explode( '.', $jwt );
		if ( 3 !== count( $parts ) ) {
			return 'invalid_format';
		}

		$header = json_decode( self::decode( $parts[0] ), false, 8 );
		$claims = json_decode( self::decode( $parts[1] ), false, 8 );
		$signature = self::decode( $parts[2] );
		if ( ! $header instanceof \stdClass || ! $claims instanceof \stdClass
			|| 'EdDSA' !== ( $header->alg ?? null ) || property_exists( $header, 'crit' )
			|| ( isset( $header->typ ) && ( ! is_string( $header->typ ) || ! in_array( strtolower( $header->typ ), array( 'jwt', 'application/jwt' ), true ) ) )
			|| strlen( $signature ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
			return 'invalid_format';
		}
		if ( ! sodium_crypto_sign_verify_detached( $signature, $parts[0] . '.' . $parts[1], $public_key ) ) {
			return 'invalid_signature';
		}
		if ( property_exists( $header, 'v' ) && ! in_array( $header->v, array( 1, '1' ), true ) ) {
			return 'unsupported_version';
		}

		$aud = $claims->aud ?? null;
		$audience_matches = $audience === $aud
			|| ( is_array( $aud ) && $aud === array_filter( $aud, 'is_string' ) && in_array( $audience, $aud, true ) );
		if ( '' === $audience || ! $audience_matches ) {
			return 'audience_mismatch';
		}
		$exp = $claims->exp ?? null;
		$nbf = $claims->nbf ?? null;
		if ( ! is_int( $exp ) || ! is_int( $nbf ) || $nbf >= $exp ) {
			return 'invalid_time_claims';
		}
		if ( $exp <= $now ) {
			return 'expired';
		}
		if ( $nbf > $now ) {
			return 'not_yet_valid';
		}

		return 'accepted';
	}

	private static function record( string $result, int $now ): void {
		$option = self::STATUS_PREFIX . $result;
		$previous = (int) get_option( $option, 0 );
		// Keep one timestamp per outcome and limit frequent writes from public requests.
		if ( $now - $previous >= MINUTE_IN_SECONDS || $now < $previous ) {
			update_option( $option, $now, false );
		}
	}

	private static function decode( string $encoded ): string {
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/D', $encoded ) || 1 === strlen( $encoded ) % 4 ) {
			return '';
		}
		$decoded = base64_decode( strtr( $encoded, '-_', '+/' ), true );
		if ( false === $decoded || $encoded !== rtrim( strtr( base64_encode( $decoded ), '+/', '-_' ), '=' ) ) {
			return '';
		}
		return $decoded;
	}
}
