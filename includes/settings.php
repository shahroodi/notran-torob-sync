<?php

namespace Notran\Torob;

defined( 'ABSPATH' ) || exit;

final class Settings {
	const OPTION = 'nttc_settings';
	const REBUILD = 'nttc_rebuild_token';
	const STATE = 'nttc_build_state';
	const TITLE_META = '_nttc_title';
	const EXCLUDE_META = '_nttc_exclude';

	public static function defaults(): array {
		return array(
			'currency'      => 'auto',
			'color'         => '',
			'color_first'   => false,
			'other_attrs'   => true,
			'backorders'    => false,
			'hidden'        => false,
			'webhook'       => false,
			'webhook_token' => '',
		);
	}

	public static function get(): array {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function currency_choices(): array {
		return array(
			'auto'       => 'تشخیص از واحد پول ووکامرس',
			'toman'      => 'تومان',
			'rial'       => 'ریال',
			'k_toman'    => 'هزار تومان',
			'k_rial'     => 'هزار ریال',
		);
	}

	public static function price_factor( array $settings ): ?float {
		$currency = $settings['currency'];
		if ( 'auto' === $currency ) {
			$currency = array(
				'IRT'  => 'toman',
				'TOMAN' => 'toman',
				'IRR'  => 'rial',
				'IRHT' => 'k_toman',
				'IRHR' => 'k_rial',
			)[ strtoupper( get_woocommerce_currency() ) ] ?? '';
		}

		return array(
			'toman'   => 1.0,
			'rial'    => 0.1,
			'k_toman' => 1000.0,
			'k_rial'  => 100.0,
		)[ $currency ] ?? null;
	}

	public static function webhook_token( array $settings ): string {
		return defined( 'NOTRAN_TOROB_WEBHOOK_TOKEN' )
			? trim( (string) NOTRAN_TOROB_WEBHOOK_TOKEN )
			: trim( (string) $settings['webhook_token'] );
	}

	public static function webhook_enabled( array $settings ): bool {
		return ! empty( $settings['webhook'] ) && '' !== self::webhook_token( $settings );
	}

	public static function request_rebuild(): void {
		update_option( self::REBUILD, wp_generate_uuid4(), false );
		Worker::kick();
	}

	public static function index_ready(): bool {
		$state = get_option( self::STATE, array() );

		return ! empty( $state['ready'] )
			&& ! empty( $state['token'] )
			&& $state['token'] === get_option( self::REBUILD );
	}

	public static function text( string $value, int $limit ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = trim( sanitize_text_field( $value ) );

		return mb_substr( $value, 0, $limit );
	}
}
