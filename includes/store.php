<?php

namespace Notran\Torob;

defined( 'ABSPATH' ) || exit;

final class Store {
	const SCHEMA = 'nttc_schema';
	const SCHEMA_VERSION = '1';
	const MAX_ATTEMPTS = 8;
	private const PAUSED_UNTIL = 9999999999;

	private $db;
	private $catalog;
	private $dirty;
	private $outbox;
	private $lock;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$this->catalog = $wpdb->prefix . 'nttc_catalog';
		$this->dirty = $wpdb->prefix . 'nttc_dirty';
		$this->outbox = $wpdb->prefix . 'nttc_outbox';
		$this->lock = 'nttc_' . sha1( DB_NAME . ':' . $wpdb->prefix );
	}

	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $this->db->get_charset_collate();

		dbDelta( "CREATE TABLE {$this->catalog} (
			wc_id bigint(20) unsigned NOT NULL,
			root_id bigint(20) unsigned NOT NULL,
			url_key char(64) NOT NULL,
			created_at bigint(20) unsigned NOT NULL,
			changed_at bigint(20) unsigned NOT NULL,
			payload longtext NOT NULL,
			PRIMARY KEY  (wc_id),
			KEY root_id (root_id),
			KEY url_key (url_key),
			KEY created (created_at,wc_id),
			KEY changed (changed_at,wc_id)
		) ENGINE=InnoDB $charset;" );

		dbDelta( "CREATE TABLE {$this->dirty} (
			root_id bigint(20) unsigned NOT NULL,
			revision bigint(20) unsigned NOT NULL DEFAULT 1,
			due_at bigint(20) unsigned NOT NULL,
			attempts tinyint unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (root_id),
			KEY due (due_at,root_id)
		) ENGINE=InnoDB $charset;" );

		dbDelta( "CREATE TABLE {$this->outbox} (
			wc_id bigint(20) unsigned NOT NULL,
			page_url text NOT NULL,
			revision bigint(20) unsigned NOT NULL DEFAULT 1,
			due_at bigint(20) unsigned NOT NULL,
			attempts tinyint unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (wc_id),
			KEY due (due_at,wc_id)
		) ENGINE=InnoDB $charset;" );

		foreach ( array( $this->catalog, $this->dirty, $this->outbox ) as $table ) {
			if ( $table !== $this->scalar( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $table ) ) ) ) {
				throw new \RuntimeException( 'Could not install the Torob catalog tables.' );
			}
		}
		update_option( self::SCHEMA, self::SCHEMA_VERSION, false );
	}

	public function lock_worker(): bool {
		return '1' === (string) $this->scalar( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $this->lock ) );
	}

	public function unlock_worker(): void {
		$this->scalar( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock ) );
	}

	public function enqueue_roots( array $ids, int $delay = 3 ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			$due = time() + $delay;
			$values = array();
			foreach ( $chunk as $id ) {
				$values[] = $this->db->prepare( '(%d,%d)', $id, $due );
			}
			$sql = "INSERT INTO {$this->dirty} (root_id,due_at) VALUES " . implode( ',', $values ) .
				' ON DUPLICATE KEY UPDATE revision=revision+1,due_at=LEAST(due_at,VALUES(due_at)),attempts=0';
			$this->write( $sql );
		}
	}

	public function dirty_jobs( int $limit ): array {
		return $this->rows(
			$this->db->prepare( "SELECT * FROM {$this->dirty} WHERE due_at<=%d ORDER BY due_at,root_id LIMIT %d", time(), $limit )
		);
	}

	public function complete_job( array $job ): void {
		$this->write(
			$this->db->prepare( "DELETE FROM {$this->dirty} WHERE root_id=%d AND revision=%d", $job['root_id'], $job['revision'] )
		);
	}

	public function retry_job( array $job ): void {
		$attempts = min( self::MAX_ATTEMPTS, (int) $job['attempts'] + 1 );
		$due = time() + min( HOUR_IN_SECONDS, 30 * ( 2 ** $attempts ) );
		$this->write(
			$this->db->prepare( "UPDATE {$this->dirty} SET attempts=%d,due_at=%d WHERE root_id=%d AND revision=%d",
				$attempts, $due, $job['root_id'], $job['revision'] )
		);
	}

	public function scan_roots( int $cursor, int $limit ): array {
		$published = $this->column(
			$this->db->prepare(
				"SELECT ID FROM {$this->db->posts} WHERE ID>%d AND post_type='product' AND post_status='publish' ORDER BY ID LIMIT %d",
				$cursor, $limit
			)
		);
		$indexed = $this->column(
			$this->db->prepare( "SELECT DISTINCT root_id FROM {$this->catalog} WHERE root_id>%d ORDER BY root_id LIMIT %d", $cursor, $limit )
		);
		$roots = array_unique( array_map( 'intval', array_merge( $published, $indexed ) ) );
		sort( $roots, SORT_NUMERIC );

		return array_slice( $roots, 0, $limit );
	}

	/**
	 * Publish the complete set for one WooCommerce product atomically.
	 * Notice entries commit together with the rows they refer to.
	 */
	public function replace_root( int $root_id, array $new_rows, bool $notify ): void {
		$previous = $this->for_root( $root_id );
		$changed = array();

		$this->write( 'START TRANSACTION' );
		try {
			foreach ( $new_rows as $id => $row ) {
				$json = wp_json_encode( $row['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( false === $json ) {
					throw new \RuntimeException( 'Could not encode catalog product ' . $id );
				}

				$old = $previous[ $id ] ?? null;
				$content_changed = ! $old || $old['payload'] !== $json;
				$source_at = $row['source_at'];
				$changed_at = $old ? max( $source_at, (int) $old['changed_at'] ) : $source_at;
				if ( $old && $content_changed ) {
					$changed_at = max( $changed_at, time() );
				}
				if ( $content_changed || $changed_at !== (int) $old['changed_at'] || $row['url_key'] !== $old['url_key'] ) {
					$this->write(
						$this->db->prepare(
							"REPLACE INTO {$this->catalog} (wc_id,root_id,url_key,created_at,changed_at,payload) VALUES (%d,%d,%s,%d,%d,%s)",
							$id, $root_id, $row['url_key'], $row['created_at'], $changed_at, $json
						)
					);
				}
				if ( $content_changed ) {
					$changed[ $id ] = $row['payload']['page_url'];
				}
			}

			foreach ( array_diff_key( $previous, $new_rows ) as $id => $row ) {
				$this->write( $this->db->prepare( "DELETE FROM {$this->catalog} WHERE wc_id=%d AND root_id=%d", $id, $root_id ) );
				$old_payload = json_decode( $row['payload'], true );
				if ( ! empty( $old_payload['page_url'] ) ) {
					$changed[ $id ] = $old_payload['page_url'];
				}
			}

			if ( $notify ) {
				$this->enqueue_notices( $changed );
			}
			$this->write( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$this->db->query( 'ROLLBACK' );
			throw $error;
		}
	}

	public function for_root( int $root_id ): array {
		$rows = $this->rows( $this->db->prepare( "SELECT * FROM {$this->catalog} WHERE root_id=%d ORDER BY wc_id", $root_id ) );
		return array_column( $rows, null, 'wc_id' );
	}

	public function count(): int {
		return (int) $this->scalar( "SELECT COUNT(*) FROM {$this->catalog}" );
	}

	public function read_page( int $page, string $sort, int $limit ): array {
		$by = array(
			'date_added_desc'   => 'created_at DESC,wc_id DESC',
			'date_updated_desc' => 'changed_at DESC,wc_id DESC',
			'product_id_desc'   => 'wc_id DESC',
		)[ $sort ];

		$this->write( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' );
		try {
			$total = $this->count();
			$offset = ( $page - 1 ) * $limit;
			$rows = $offset >= $total ? array() : $this->rows(
				$this->db->prepare( "SELECT * FROM {$this->catalog} ORDER BY $by LIMIT %d OFFSET %d", $limit, $offset )
			);
			$this->write( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$this->db->query( 'ROLLBACK' );
			throw $error;
		}

		return array( 'total' => $total, 'rows' => $rows );
	}

	public function cursor( ?int $before, int $limit ): array {
		if ( null === $before ) {
			return $this->rows( $this->db->prepare( "SELECT * FROM {$this->catalog} ORDER BY wc_id DESC LIMIT %d", $limit ) );
		}

		return $this->rows(
			$this->db->prepare( "SELECT * FROM {$this->catalog} WHERE wc_id<%d ORDER BY wc_id DESC LIMIT %d", $before, $limit )
		);
	}

	public function lookup( array $keys, bool $by_url ): array {
		$column = $by_url ? 'url_key' : 'wc_id';
		$format = $by_url ? '%s' : '%d';
		$found = array();
		foreach ( array_chunk( array_unique( $keys ), 200 ) as $batch ) {
			$markers = implode( ',', array_fill( 0, count( $batch ), $format ) );
			foreach ( $this->rows( $this->db->prepare( "SELECT * FROM {$this->catalog} WHERE $column IN ($markers)", $batch ) ) as $row ) {
				$found[ $row[ $column ] ] = $row;
			}
		}
		$rows = array();
		foreach ( $keys as $key ) {
			if ( isset( $found[ $key ] ) ) {
				$rows[ $found[ $key ]['wc_id'] ] = $found[ $key ];
			}
		}
		return array_values( $rows );
	}

	public function payload( array $row ): array {
		$data = (array) json_decode( $row['payload'] );
		$data['date_updated'] = gmdate( 'c', (int) $row['changed_at'] );
		return $data;
	}

	public function enqueue_notices( array $urls ): void {
		foreach ( array_chunk( $urls, 100, true ) as $batch ) {
			$values = array();
			$due = time();
			foreach ( $batch as $id => $url ) {
				$values[] = $this->db->prepare( '(%d,%s,%d)', $id, $url, $due );
			}
			$this->write(
				"INSERT INTO {$this->outbox} (wc_id,page_url,due_at) VALUES " . implode( ',', $values ) .
				' ON DUPLICATE KEY UPDATE page_url=VALUES(page_url),revision=revision+1,due_at=VALUES(due_at),attempts=0'
			);
		}
	}

	public function due_notices(): array {
		return $this->rows(
			$this->db->prepare( "SELECT * FROM {$this->outbox} WHERE due_at<=%d ORDER BY due_at,wc_id LIMIT 100", time() )
		);
	}

	public function complete_notices( array $notices ): void {
		$keys = array();
		foreach ( $notices as $notice ) {
			$keys[] = $this->db->prepare( '(%d,%d)', $notice['wc_id'], $notice['revision'] );
		}
		if ( $keys ) {
			$this->write( "DELETE FROM {$this->outbox} WHERE (wc_id,revision) IN (" . implode( ',', $keys ) . ')' );
		}
	}

	public function retry_notices( array $notices, int $retry_after, bool $pause ): void {
		foreach ( $notices as $notice ) {
			$attempts = min( self::MAX_ATTEMPTS, 1 + (int) $notice['attempts'] );
			$due = $pause || $attempts === self::MAX_ATTEMPTS
				? self::PAUSED_UNTIL
				: time() + max( $retry_after, min( 6 * HOUR_IN_SECONDS, 60 * ( 2 ** $attempts ) ) );

			$this->write(
				$this->db->prepare( "UPDATE {$this->outbox} SET attempts=%d,due_at=%d WHERE wc_id=%d AND revision=%d",
					$attempts, $due, $notice['wc_id'], $notice['revision'] )
			);
		}
	}

	public function resume_notices(): void {
		$this->write( $this->db->prepare( "UPDATE {$this->outbox} SET revision=revision+1,attempts=0,due_at=%d WHERE attempts>0", time() ) );
	}

	public function counts(): array {
		return array(
			'catalog' => $this->count(),
			'dirty'   => (int) $this->scalar( "SELECT COUNT(*) FROM {$this->dirty}" ),
			'notices' => (int) $this->scalar( "SELECT COUNT(*) FROM {$this->outbox}" ),
			'paused'  => (int) $this->scalar( $this->db->prepare( "SELECT COUNT(*) FROM {$this->outbox} WHERE due_at=%d", self::PAUSED_UNTIL ) ),
		);
	}

	private function write( string $sql ): void {
		if ( false === $this->db->query( $sql ) ) {
			throw new \RuntimeException( 'Torob index write failed. ' . esc_html( $this->db->last_error ) );
		}
	}

	private function rows( string $sql ): array {
		$rows = $this->db->get_results( $sql, ARRAY_A );
		$this->check_read();
		return (array) $rows;
	}

	private function scalar( string $sql ) {
		$value = $this->db->get_var( $sql );
		$this->check_read();
		return $value;
	}

	private function column( string $sql ): array {
		$values = $this->db->get_col( $sql );
		$this->check_read();
		return (array) $values;
	}

	private function check_read(): void {
		if ( '' !== $this->db->last_error ) {
			throw new \RuntimeException( 'Torob index read failed. ' . esc_html( $this->db->last_error ) );
		}
	}
}
