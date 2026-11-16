<?php

namespace Notran\Torob;

defined( 'ABSPATH' ) || exit;

final class Worker {
	const CRON = 'nttc_cron';
	const ONCE = 'nttc_cron_once';
	const SCHEDULE = 'nttc_every_minute';
	const LAST_RUN = 'nttc_last_run';
	const LAST_ERROR = 'nttc_last_error';
	const LAST_WEBHOOK = 'nttc_last_webhook';
	private const WEBHOOK_URL = 'https://api.torob.com/update/webhook/v1/';

	public static function schedules( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array( 'interval' => MINUTE_IN_SECONDS, 'display' => 'Notran Torob (one minute)' );
		return $schedules;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON );
		}
	}

	public static function kick(): void {
		if ( ! wp_next_scheduled( self::ONCE ) ) {
			wp_schedule_single_event( time() + 5, self::ONCE );
		}
	}

	public static function clear(): void {
		wp_clear_scheduled_hook( self::CRON );
		wp_clear_scheduled_hook( self::ONCE );
	}

	public static function run(): void {
		if ( ! class_exists( 'WooCommerce' ) || Store::SCHEMA_VERSION !== get_option( Store::SCHEMA ) ) {
			return;
		}
		$store = new Store();
		$locked = false;
		try {
			$locked = $store->lock_worker();
			if ( ! $locked ) {
				return;
			}

			$build_token = get_option( Settings::REBUILD );
			if ( ! $build_token ) {
				Settings::request_rebuild();
				$build_token = get_option( Settings::REBUILD );
			}
			update_option( self::LAST_RUN, time(), false );
			$settings = Settings::get();
			if ( null === Settings::price_factor( $settings ) ) {
				return;
			}

			$until = microtime( true ) + 7.0;
			$builder = new Catalog( $settings );
			$notify = Settings::webhook_enabled( $settings );

			self::refresh_dirty( $store, $builder, $notify, $until );
			self::scan( $store, $builder, $notify, $until, $build_token );

			if ( $notify && Settings::index_ready() ) {
				self::deliver( $store, Settings::webhook_token( $settings ) );
			}
		} catch ( \Throwable $error ) {
			self::record_error( $error );
		} finally {
			if ( $locked ) {
				try {
					$store->unlock_worker();
				} catch ( \Throwable $error ) {
					self::record_error( $error );
				}
			}
		}
	}

	private static function refresh_dirty( Store $store, Catalog $builder, bool $notify, float $until ): void {
		$jobs = $store->dirty_jobs( 30 );
		if ( 30 === count( $jobs ) ) {
			self::kick();
		}
		foreach ( $jobs as $job ) {
			if ( microtime( true ) >= $until ) {
				self::kick();
				break;
			}
			try {
				$id = (int) $job['root_id'];
				$store->replace_root( $id, $builder->build( $id )['rows'], $notify );
				$store->complete_job( $job );
			} catch ( \Throwable $error ) {
				self::record_error( $error );
				$store->retry_job( $job );
			}
		}
	}

	private static function scan( Store $store, Catalog $builder, bool $notify, float $until, string $token ): void {
		$state = get_option( Settings::STATE, array() );
		if ( ( $state['token'] ?? '' ) !== $token ) {
			$state = array( 'token' => $token, 'cursor' => 0, 'scanned' => 0, 'started' => time(), 'ready' => false );
			update_option( Settings::STATE, $state, false );
		}
		if ( ! empty( $state['ready'] ) ) {
			return;
		}

		$scanned = 0;
		while ( $scanned < 150 && microtime( true ) < $until ) {
			$ids = $store->scan_roots( (int) $state['cursor'], 20 );
			if ( ! $ids ) {
				$state['ready'] = true;
				$state['finished'] = time();
				update_option( Settings::STATE, $state, false );
				return;
			}

			try {
				foreach ( $ids as $id ) {
					if ( microtime( true ) >= $until ) {
						break;
					}

					$store->replace_root( $id, $builder->build( $id )['rows'], $notify );
					$state['cursor'] = $id;
					++$state['scanned'];
					++$scanned;
				}
			} finally {
				update_option( Settings::STATE, $state, false );
			}
		}

		self::kick();
	}

	private static function deliver( Store $store, string $token ): void {
		$last = get_option( self::LAST_WEBHOOK, array() );
		if ( time() - (int) ( $last['time'] ?? 0 ) < 10 ) {
			return;
		}
		$due = $store->due_notices();
		if ( ! $due ) {
			return;
		}

		$items = array();
		$notices = array();
		$old_domain = array();
		$domain = Urls::authority( home_url( '/' ) );
		foreach ( $due as $notice ) {
			if ( $domain !== Urls::authority( $notice['page_url'] ) ) {
				$old_domain[] = $notice;
				continue;
			}
			$notices[] = $notice;
			$items[] = array(
				'page_unique' => (string) $notice['wc_id'],
				'page_url'    => $notice['page_url'],
			);
		}
		if ( $old_domain ) {
			$store->retry_notices( $old_domain, 0, true );
		}
		if ( ! $items ) {
			self::webhook_status( 0, 0, 'دامنه لینک‌های صف با دامنهٔ فعلی فروشگاه متفاوت است.' );
			return;
		}

		$request = wp_safe_remote_post(
			self::WEBHOOK_URL,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => 32768,
				'headers'             => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'                => wp_json_encode( array( 'items' => $items ) ),
			)
		);
		$status = (int) wp_remote_retrieve_response_code( $request );
		$body = json_decode( wp_remote_retrieve_body( $request ), true );
		$count = count( $items );
		if ( 200 === $status && 'success' === ( $body['message'] ?? '' ) ) {
			$store->complete_notices( $notices );
			self::webhook_status( 200, $count, 'ترب درخواست بازخوانی را دریافت کرد.' );
			return;
		}

		$pause = in_array( $status, array( 400, 401, 403, 404, 422 ), true );
		$retry_after = (string) wp_remote_retrieve_header( $request, 'retry-after' );
		$delay = preg_match( '/^[0-9]+$/D', $retry_after )
			? (int) $retry_after
			: ( $retry_after ? max( 0, (int) strtotime( $retry_after ) - time() ) : 0 );
		$store->retry_notices( $notices, min( $delay, DAY_IN_SECONDS ), $pause );
		$message = $pause ? 'درخواست وب‌هوک رد شد. توکن، دامنه و تنظیمات ترب را بررسی کنید.'
			: 'ارتباط با وب‌هوک کامل نشد. صف طبق زمان‌بندی دوباره تلاش می‌کند.';
		self::webhook_status( $status, $count, $message );
	}

	private static function webhook_status( int $code, int $count, string $message ): void {
		update_option(
			self::LAST_WEBHOOK,
			array( 'time' => time(), 'code' => $code, 'count' => $count, 'message' => $message ),
			false
		);
	}

	public static function record_error( \Throwable $error ): void {
		update_option( self::LAST_ERROR, array( 'time' => time(), 'message' => Settings::text( $error->getMessage(), 500 ) ), false );
	}
}
