<?php

namespace Notran\Torob;

defined( 'ABSPATH' ) || exit;

final class Admin {
	private const SLUG = 'notran-torob-connect';
	private const CAPABILITY = 'manage_woocommerce';
	private const TABS = array( 'connection' => 'اتصال و تنظیمات', 'preview' => 'پیش‌نمایش محصول', 'status' => 'وضعیت همگام‌سازی' );

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NTTC_FILE ), array( self::class, 'links' ) );
		foreach ( array( 'save', 'rebuild', 'run', 'refresh', 'retry' ) as $action ) {
			add_action( 'admin_post_nttc_' . $action, array( self::class, $action ) );
		}
		add_action( 'woocommerce_product_options_general_product_data', array( self::class, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save_product' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( self::class, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( self::class, 'save_variation' ), 10, 2 );
	}

	public static function menu(): void {
		$screen = add_submenu_page( 'woocommerce', 'اتصال نوتران به ترب', 'اتصال به ترب', self::CAPABILITY, self::SLUG, array( self::class, 'render' ) );
		if ( $screen ) {
			add_action(
				'admin_enqueue_scripts',
				static function ( $hook ) use ( $screen ) {
					if ( $hook === $screen ) {
						wp_enqueue_style( 'nttc-admin', plugins_url( 'assets/admin.css', NTTC_FILE ), array(), NTTC_VERSION );
						wp_enqueue_script( 'nttc-admin', plugins_url( 'assets/admin.js', NTTC_FILE ), array(), NTTC_VERSION, true );
					}
				}
			);
		}
	}

	public static function links( array $links ): array {
		if ( current_user_can( self::CAPABILITY ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">تنظیمات ترب</a>' );
		}
		return $links;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The tab only selects a view for an authorized administrator.
		$tab = sanitize_key( self::input( $_GET, 'tab' ) );
		$tab = isset( self::TABS[ $tab ] ) ? $tab : 'connection';
		$settings = Settings::get();
		$state = self::readiness( $settings );
		?>
		<div class="wrap nttc" dir="rtl" lang="fa">
			<header class="nttc-header">
				<div class="nttc-brand">
					<span class="dashicons dashicons-update" aria-hidden="true"></span>
					<div>
						<h1>اتصال ووکامرس به ترب</h1>
						<p>تنوع‌های محصول را بررسی کن و با عنوان، لینک و موجودی درست به ترب معرفی کن.</p>
					</div>
				</div>
				<span class="nttc-pill <?php echo esc_attr( $state['class'] ); ?>"><?php echo esc_html( $state['label'] ); ?></span>
			</header>
			<nav class="nttc-tabs" aria-label="بخش‌های اتصال به ترب">
				<?php foreach ( self::TABS as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php self::notice(); ?>
			<?php
			if ( 'preview' === $tab ) {
				self::preview( $settings );
			} elseif ( 'status' === $tab ) {
				self::status( $settings, $state );
			} else {
				self::connection( $settings, $state );
			}
			?>
		</div>
		<?php
	}

	private static function connection( array $settings, array $state ): void {
		$global_attributes = wc_get_attribute_taxonomies();
		$has_token = '' !== Settings::webhook_token( $settings );
		?>
		<section class="nttc-card">
			<h2>آدرس فید محصولات</h2>
			<p>این آدرس را برای ثبت اتصال API نسخهٔ ۳ در پنل فروشندگان ترب استفاده کن. درخواست‌های فید با امضای ترب اعتبارسنجی می‌شوند.</p>
			<div class="nttc-copy">
				<label class="screen-reader-text" for="nttc-feed-url">آدرس فید محصولات</label>
				<input id="nttc-feed-url" dir="ltr" class="large-text code" type="url" readonly value="<?php echo esc_attr( rest_url( Feed::ROUTE ) ); ?>">
				<button type="button" class="button" data-nttc-copy="nttc-feed-url">کپی آدرس</button>
				<span class="screen-reader-text" role="status" data-nttc-copy-status></span>
			</div>
			<div class="nttc-actions">
				<a href="https://panel.torob.com/" class="button" target="_blank" rel="noopener noreferrer">پنل فروشندگان ترب <span aria-hidden="true">↗</span></a>
				<a href="<?php echo esc_url( self::url( 'status' ) ); ?>">مشاهده وضعیت فید</a>
			</div>
			<p class="nttc-help"><?php echo esc_html( $state['message'] ); ?></p>
		</section>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<?php self::action_fields( 'save' ); ?>
			<div class="nttc-grid">
				<section class="nttc-card">
					<h2>رنگ، عنوان و قیمت</h2>
					<label class="nttc-field">
						<span>ویژگی رنگ</span>
						<input type="text" name="color" list="nttc-color-keys" value="<?php echo esc_attr( $settings['color'] ); ?>" placeholder="تشخیص خودکار">
						<small>برای انتخاب دستی، نام یا نامک ویژگی ووکامرس را وارد کن. مثال <code dir="ltr">pa_color</code> یا «رنگ».</small>
					</label>
					<datalist id="nttc-color-keys">
						<?php foreach ( $global_attributes as $attribute ) : ?>
							<option value="<?php echo esc_attr( wc_attribute_taxonomy_name( $attribute->attribute_name ) ); ?>"><?php echo esc_html( $attribute->attribute_label ); ?></option>
						<?php endforeach; ?>
					</datalist>
					<label class="nttc-field">
						<span>جای رنگ در عنوان</span>
						<select name="color_first">
							<option value="0" <?php selected( ! $settings['color_first'] ); ?>>بعد از نام محصول</option>
							<option value="1" <?php selected( (bool) $settings['color_first'] ); ?>>قبل از نام محصول</option>
						</select>
					</label>
					<?php self::checkbox( 'other_attrs', 'سایز، وزن و سایر ویژگی‌های متغیر هم در عنوان بیایند.', $settings ); ?>
					<label class="nttc-field">
						<span>واحد قیمت ذخیره‌شده در ووکامرس</span>
						<select name="currency">
							<?php foreach ( Settings::currency_choices() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['currency'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<small>واحد فعلی ووکامرس <code dir="ltr"><?php echo esc_html( get_woocommerce_currency() ); ?></code>. فید قیمت را به تومان ارسال می‌کند.</small>
					</label>
				</section>
				<div class="nttc-stack">
					<section class="nttc-card">
						<h2>وضعیت ارسال محصولات</h2>
						<?php self::checkbox( 'backorders', 'کالاهای قابل سفارش معوق، موجود گزارش شوند.', $settings ); ?>
						<?php self::checkbox( 'hidden', 'محصولات منتشرشده با نمایش «مخفی» در کاتالوگ هم ارسال شوند.', $settings ); ?>
						<p class="nttc-help">برای استثنا کردن یا تغییر عنوان یک محصول یا تنوع، تنظیمات «اتصال به ترب» را در صفحه ویرایش همان کالا باز کن.</p>
					</section>
					<section class="nttc-card">
						<h2>به‌روزرسانی سریع با وب‌هوک</h2>
						<p>با فعال‌سازی وب‌هوک، شناسه و نشانی ردیف تغییرکرده و توکن احراز هویت برای درخواست بازخوانی به ترب ارسال می‌شود.</p>
						<?php self::checkbox( 'webhook', 'ارسال اعلان تغییر محصول به ترب', $settings ); ?>
						<p class="nttc-help"><a href="https://torob.com/pages/terms/" target="_blank" rel="noopener noreferrer">شرایط استفادهٔ ترب</a> · <a href="https://torob.com/pages/privacy/" target="_blank" rel="noopener noreferrer">حریم خصوصی ترب</a></p>
						<?php if ( defined( 'NOTRAN_TOROB_WEBHOOK_TOKEN' ) ) : ?>
							<p class="nttc-help">توکن وب‌هوک از تنظیمات سرور خوانده می‌شود.</p>
						<?php else : ?>
							<label class="nttc-field">
								<span>توکن وب‌هوک فروشگاه</span>
								<input type="password" name="webhook_token" dir="ltr" autocomplete="new-password" placeholder="<?php echo esc_attr( $has_token ? 'توکن ذخیره شده است' : 'توکن دریافتی از ترب' ); ?>">
								<small>برای نگهداری توکن قبلی، این فیلد را خالی بگذار.</small>
							</label>
							<?php if ( $has_token ) : ?>
								<label class="nttc-check"><input type="checkbox" name="forget_token" value="1"> حذف توکن ذخیره‌شده</label>
							<?php endif; ?>
						<?php endif; ?>
					</section>
				</div>
			</div>
			<div class="nttc-actions nttc-save">
				<button type="submit" class="button button-primary">ذخیره تنظیمات</button>
				<a href="<?php echo esc_url( self::url( 'preview' ) ); ?>">بررسی یک محصول</a>
			</div>
		</form>
		<?php
	}

	private static function preview( array $settings ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a read-only product preview search.
		$query = Settings::text( self::input( $_GET, 'q' ), 150 );
		$id_query = strtr( $query, array(
			'۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4', '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9',
			'٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4', '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9',
		) );
		?>
		<section class="nttc-card">
			<h2>پیش‌نمایش محصول و تنوع‌ها</h2>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nttc-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="tab" value="preview">
				<label class="screen-reader-text" for="nttc-search-product">نام یا شناسه محصول ووکامرس</label>
				<input id="nttc-search-product" type="search" name="q" placeholder="نام یا شناسه محصول یا تنوع" value="<?php echo esc_attr( $query ); ?>" required>
				<button type="submit" class="button button-primary">بررسی محصول</button>
			</form>
			<p class="nttc-help">با شناسه تنوع، ردیف‌های محصول والد را می‌بینی. پیش‌نمایش از دادهٔ فعلی ووکامرس ساخته می‌شود.</p>
			<?php
			if ( '' !== $query && ! preg_match( '/^[1-9][0-9]*$/D', $id_query ) ) {
				self::product_search_results( $query );
			}
			?>
		</section>
		<?php
		if ( ! preg_match( '/^[1-9][0-9]*$/D', $id_query ) ) {
			return;
		}
		$product = wc_get_product( (int) $id_query );
		if ( $product instanceof \WC_Product_Variation ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		if ( ! $product instanceof \WC_Product ) {
			echo '<p class="nttc-message">محصولی با این شناسه پیدا نشد.</p>';
			return;
		}

		try {
			$result = ( new Catalog( $settings ) )->build( $product->get_id() );
			$indexed = Settings::index_ready() ? ( new Store() )->for_root( $product->get_id() ) : array();
		} catch ( \Throwable $error ) {
			Worker::record_error( $error );
			echo '<p class="nttc-message is-warn">پیش‌نمایش آماده نشد. جزئیات را در «وضعیت همگام‌سازی» بررسی کن.</p>';
			return;
		}
		$rows = $result['rows'];
		$notes = $result['notes'];
		$limit = 80;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination only selects preview rows.
		$page = max( 1, min( max( 1, (int) ceil( count( $rows ) / $limit ) ), absint( self::input( $_GET, 'view_page' ) ) ) );
		?>
		<section class="nttc-card">
			<div class="nttc-card-heading">
				<div>
					<h2><?php echo esc_html( $product->get_name() ); ?></h2>
					<p><?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?> ردیف آمادهٔ ارسال</p>
				</div>
				<div class="nttc-actions">
					<?php if ( current_user_can( 'edit_post', $product->get_id() ) ) : ?>
						<a class="button" href="<?php echo esc_url( get_edit_post_link( $product->get_id(), 'raw' ) ); ?>">ویرایش کالا</a>
					<?php endif; ?>
					<?php self::action_button( 'refresh', 'صف به‌روزرسانی محصول', $product->get_id() ); ?>
				</div>
			</div>
			<?php if ( $rows ) : ?>
				<div class="nttc-table">
					<table class="widefat striped">
						<thead>
							<tr><th>عنوان و لینک ارسالی</th><th>شناسه</th><th>قیمت به تومان</th><th>موجودی</th><th>فید</th></tr>
						</thead>
						<tbody>
						<?php foreach ( array_slice( $rows, ( $page - 1 ) * $limit, $limit, true ) as $id => $row ) : ?>
							<?php self::preview_row( $row['payload'], $indexed[ $id ] ?? array(), $notes[ $id ] ?? array() ); ?>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php if ( count( $rows ) > $limit ) : ?>
					<div class="nttc-actions" aria-label="صفحه‌بندی پیش‌نمایش">
						<?php if ( $page > 1 ) : ?><a class="button" href="<?php echo esc_url( self::url( 'preview', array( 'q' => $product->get_id(), 'view_page' => $page - 1 ) ) ); ?>">صفحه قبلی</a><?php endif; ?>
						<?php if ( $page * $limit < count( $rows ) ) : ?><a class="button" href="<?php echo esc_url( self::url( 'preview', array( 'q' => $product->get_id(), 'view_page' => $page + 1 ) ) ); ?>">صفحه بعدی</a><?php endif; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
			<?php $skipped = array_diff_key( $notes, $rows ); ?>
			<?php if ( $skipped ) : ?>
				<details class="nttc-notes" <?php echo $rows ? '' : 'open'; ?>>
					<summary><?php echo esc_html( number_format_i18n( count( $skipped ) ) ); ?> مورد خارج از خروجی</summary>
					<ul>
					<?php foreach ( array_slice( $skipped, 0, 120, true ) as $id => $reasons ) : ?>
						<li><code dir="ltr"><?php echo esc_html( $id ); ?></code> <?php echo esc_html( implode( ' ', $reasons ) ); ?></li>
					<?php endforeach; ?>
					</ul>
					<?php if ( count( $skipped ) > 120 ) : ?><p>۱۲۰ مورد اول نمایش داده شد.</p><?php endif; ?>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function product_search_results( string $query ): void {
		$results = get_posts( array(
			'post_type'        => 'product',
			'post_status'      => array( 'publish', 'draft', 'private', 'pending', 'future' ),
			'posts_per_page'   => 15,
			's'                => $query,
			'suppress_filters' => false,
		) );
		if ( ! $results ) {
			echo '<p class="nttc-message">محصولی با این نام پیدا نشد.</p>';
			return;
		}
		echo '<ul class="nttc-search-results">';
		foreach ( $results as $result ) {
			printf( '<li><a href="%s">%s <code dir="ltr">%d</code></a></li>',
				esc_url( self::url( 'preview', array( 'q' => $result->ID ) ) ), esc_html( get_the_title( $result ) ), (int) $result->ID );
		}
		echo '</ul>';
	}

	private static function preview_row( array $payload, array $cached, array $notes ): void {
		$encoded = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$image = $payload['image_links'][0] ?? '';
		$state = ( $cached['payload'] ?? '' ) === $encoded ? 'آماده در فید' : 'در انتظار همگام‌سازی';
		?>
		<tr>
			<td>
				<div class="nttc-row-product">
					<?php if ( $image ) : ?><img src="<?php echo esc_url( $image ); ?>" width="44" height="44" alt="" loading="lazy"><?php endif; ?>
					<div>
						<strong><?php echo esc_html( $payload['title'] ); ?></strong><br>
						<a href="<?php echo esc_url( $payload['page_url'] ); ?>" target="_blank" rel="noopener noreferrer">باز کردن لینک کالا <span aria-hidden="true">↗</span></a>
					</div>
				</div>
				<?php if ( $notes ) : ?><p class="nttc-help"><?php echo esc_html( implode( ' ', $notes ) ); ?></p><?php endif; ?>
				<details class="nttc-json">
					<summary>مشاهده دادهٔ ردیف</summary>
					<pre dir="ltr"><?php echo esc_html( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
				</details>
			</td>
			<td>
				<code dir="ltr"><?php echo esc_html( $payload['page_unique'] ); ?></code>
				<?php if ( isset( $payload['product_group_id'] ) ) : ?>
					<br><small>گروه <code dir="ltr"><?php echo esc_html( $payload['product_group_id'] ); ?></code></small>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( isset( $payload['old_price'] ) ) : ?><del><?php echo esc_html( number_format_i18n( $payload['old_price'] ) ); ?></del><br><?php endif; ?>
				<?php echo esc_html( number_format_i18n( $payload['current_price'] ) ); ?>
			</td>
			<td><span class="nttc-pill <?php echo $payload['availability'] ? 'is-ready' : 'is-warn'; ?>"><?php echo $payload['availability'] ? 'موجود' : 'ناموجود'; ?></span></td>
			<td><?php echo esc_html( $state ); ?></td>
		</tr>
		<?php
	}

	private static function status( array $settings, array $state ): void {
		$build = get_option( Settings::STATE, array() );
		$last_error = get_option( Worker::LAST_ERROR, array() );
		$webhook = get_option( Worker::LAST_WEBHOOK, array() );
		$counts = array();
		try {
			$counts = ( new Store() )->counts();
		} catch ( \Throwable $error ) {
			Worker::record_error( $error );
			$last_error = get_option( Worker::LAST_ERROR, array() );
		}
		?>
		<section class="nttc-card">
			<h2>فهرست و صف همگام‌سازی</h2>
			<p><?php echo esc_html( $state['message'] ); ?></p>
			<?php if ( $counts ) : ?>
				<div class="nttc-metrics">
					<?php foreach ( array( 'catalog' => 'ردیف در فهرست', 'dirty' => 'محصول در صف بازخوانی', 'notices' => 'اعلان وب‌هوک در صف', 'paused' => 'اعلان مکث‌شده' ) as $key => $label ) : ?>
						<div><strong><?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?></strong><span><?php echo esc_html( $label ); ?></span></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="nttc-actions">
				<?php self::action_button( 'run', 'پردازش یک نوبت' ); ?>
				<?php self::action_button( 'rebuild', 'بازسازی فهرست', 0, 'فید در مدت بازسازی پاسخ موقت می‌دهد. بازسازی شروع شود؟' ); ?>
				<?php if ( Settings::webhook_enabled( $settings ) && ! empty( $counts['notices'] ) ) : ?>
					<?php self::action_button( 'retry', 'تلاش دوباره برای وب‌هوک' ); ?>
				<?php endif; ?>
			</div>
		</section>
		<div class="nttc-grid">
			<section class="nttc-card">
				<h2>وضعیت پردازش</h2>
				<dl class="nttc-dl">
					<dt>کاتالوگ بررسی‌شده در آخرین ساخت</dt><dd><?php echo esc_html( number_format_i18n( (int) ( $build['scanned'] ?? 0 ) ) ); ?> محصول</dd>
					<dt>آخرین اجرای زمان‌بند</dt><dd><?php echo esc_html( self::timestamp( get_option( Worker::LAST_RUN, 0 ) ) ); ?></dd>
					<dt>آخرین درخواست معتبر فید</dt><dd><?php echo esc_html( self::timestamp( get_option( Feed::LAST_REQUEST, 0 ) ) ); ?></dd>
					<dt>اعتبارسنجی امضای ترب</dt><dd><?php echo function_exists( 'sodium_crypto_sign_verify_detached' ) ? 'Sodium فعال است' : 'Sodium لازم است'; ?></dd>
				</dl>
				<?php self::authentication_checks(); ?>
				<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
					<p class="nttc-message is-warn">اجرای WP-Cron از طریق بازدید غیرفعال است. وضعیت زمان‌بند سرور یا اجرای دستی را بررسی کن.</p>
				<?php endif; ?>
				<?php if ( ! empty( $last_error['message'] ) ) : ?>
					<details class="nttc-notes">
						<summary>آخرین خطای ثبت‌شده در <?php echo esc_html( self::timestamp( $last_error['time'] ) ); ?></summary>
						<p dir="auto"><?php echo esc_html( $last_error['message'] ); ?></p>
					</details>
				<?php endif; ?>
			</section>
			<section class="nttc-card">
				<h2>وضعیت وب‌هوک</h2>
				<p><?php echo Settings::webhook_enabled( $settings ) ? 'ارسال اعلان تغییر فعال است.' : 'برای فعال‌سازی، توکن وب‌هوک فروشگاه را در برگه تنظیمات وارد کن.'; ?></p>
				<?php if ( $webhook ) : ?>
					<dl class="nttc-dl">
						<dt>آخرین تلاش</dt><dd><?php echo esc_html( self::timestamp( $webhook['time'] ) ); ?></dd>
						<dt>پاسخ ترب</dt><dd><?php echo esc_html( $webhook['code'] ? (string) $webhook['code'] : 'بدون پاسخ HTTP' ); ?></dd>
						<dt>تعداد ردیف‌ها</dt><dd><?php echo esc_html( number_format_i18n( (int) $webhook['count'] ) ); ?></dd>
					</dl>
					<p class="nttc-help"><?php echo esc_html( $webhook['message'] ); ?></p>
				<?php endif; ?>
				<p class="nttc-help">وضعیت نمایش و ادغام هر ردیف در پنل فروشندگان ترب قابل بررسی است.</p>
			</section>
		</div>
		<?php
	}

	private static function authentication_checks(): void {
		$labels = array(
			'accepted'            => 'توکن معتبر پذیرفته شد',
			'missing_token'       => 'هدر X-Torob-Token به افزونه نرسید',
			'unsupported_version' => 'نسخهٔ توکن پشتیبانی نمی‌شود',
			'invalid_format'      => 'قالب یا الگوریتم توکن نامعتبر بود',
			'invalid_signature'   => 'امضا با کلید عمومی ترب تأیید نشد',
			'audience_mismatch'   => 'دامنهٔ توکن با دامنهٔ فید مطابقت نداشت',
			'invalid_time_claims' => 'بازهٔ اعتبار توکن نامعتبر بود',
			'not_yet_valid'       => 'زمان شروع اعتبار توکن هنوز نرسیده بود',
			'expired'             => 'زمان اعتبار توکن گذشته بود',
			'configuration'       => 'اعتبارسنجی روی سرور در دسترس نبود',
		);
		$observations = array();
		foreach ( Auth::RESULTS as $result ) {
			$time = (int) get_option( Auth::STATUS_PREFIX . $result, 0 );
			if ( $time > 0 ) {
				$observations[ $result ] = $time;
			}
		}
		arsort( $observations, SORT_NUMERIC );
		?>
		<details class="nttc-notes">
			<summary>گزارش احراز هویت درخواست‌های ترب</summary>
			<?php if ( $observations ) : ?>
				<dl class="nttc-dl">
					<?php foreach ( $observations as $result => $time ) : ?>
						<dt><?php echo esc_html( $labels[ $result ] ); ?> <code dir="ltr"><?php echo esc_html( $result ); ?></code></dt>
						<dd><?php echo esc_html( self::timestamp( $time ) ); ?></dd>
					<?php endforeach; ?>
				</dl>
			<?php else : ?>
				<p class="nttc-help">هنوز نتیجهٔ احراز هویتی ثبت نشده است.</p>
			<?php endif; ?>
		</details>
		<?php
	}

	private static function readiness( array $settings ): array {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return array( 'label' => 'نیاز به Sodium', 'class' => 'is-warn', 'message' => 'افزونهٔ Sodium در PHP وب‌سایت باید فعال باشد تا امضای ترب بررسی شود.' );
		}
		if ( null === Settings::price_factor( $settings ) ) {
			return array( 'label' => 'نیاز به تنظیم واحد پول', 'class' => 'is-warn', 'message' => 'واحد قیمت‌های ذخیره‌شده در ووکامرس را در تنظیمات همین صفحه انتخاب کن.' );
		}
		if ( ! Settings::index_ready() ) {
			return array( 'label' => 'فهرست در حال آماده‌سازی', 'class' => 'is-warn', 'message' => 'پس از تکمیل ساخت فهرست، فید آمادهٔ ثبت در ترب می‌شود. پیش‌نمایش محصولات همین حالا در دسترس است.' );
		}
		return array( 'label' => 'فید آماده', 'class' => 'is-ready', 'message' => 'فهرست آماده است. بعد از ثبت آدرس در ترب، آخرین درخواست معتبر را در وضعیت همگام‌سازی ببین.' );
	}

	public static function save(): void {
		self::require_capability();
		check_admin_referer( 'nttc_save' );
		$previous = Settings::get();
		$settings = $previous;
		$currency = self::input( $_POST, 'currency' );
		$settings['currency'] = array_key_exists( $currency, Settings::currency_choices() ) ? $currency : 'auto';
		$settings['color'] = Settings::text( rawurldecode( self::input( $_POST, 'color' ) ), 150 );
		foreach ( array( 'color_first', 'other_attrs', 'backorders', 'hidden', 'webhook' ) as $key ) {
			$settings[ $key ] = '1' === self::input( $_POST, $key );
		}
		if ( ! defined( 'NOTRAN_TOROB_WEBHOOK_TOKEN' ) ) {
			$token = trim( self::input( $_POST, 'webhook_token' ) );
			if ( '' !== $token && ! preg_match( '/^[\x21-\x7E]{1,4096}$/D', $token ) ) {
				self::redirect( 'connection', 'token_invalid' );
			}
			if ( '1' === self::input( $_POST, 'forget_token' ) ) {
				$settings['webhook_token'] = '';
			} elseif ( '' !== $token ) {
				$settings['webhook_token'] = $token;
			}
		}
		update_option( Settings::OPTION, $settings, false );

		foreach ( array( 'currency', 'color', 'color_first', 'other_attrs', 'backorders', 'hidden' ) as $key ) {
			if ( $settings[ $key ] !== $previous[ $key ] ) {
				Settings::request_rebuild();
				break;
			}
		}
		if ( $settings['webhook'] !== $previous['webhook'] || $settings['webhook_token'] !== $previous['webhook_token'] ) {
			if ( Settings::webhook_enabled( $settings ) ) {
				try {
					( new Store() )->resume_notices();
				} catch ( \Throwable $error ) {
					Worker::record_error( $error );
				}
			}
			Worker::kick();
		}
		self::redirect( 'connection', 'saved' );
	}

	public static function rebuild(): void {
		self::require_capability();
		check_admin_referer( 'nttc_rebuild' );
		Settings::request_rebuild();
		self::redirect( 'status', 'rebuild' );
	}

	public static function run(): void {
		self::require_capability();
		check_admin_referer( 'nttc_run' );
		Worker::run();
		self::redirect( 'status', 'ran' );
	}

	public static function refresh(): void {
		self::require_capability();
		check_admin_referer( 'nttc_refresh' );
		$id = absint( self::input( $_POST, 'product_id' ) );
		if ( $id > 0 && 'product' === get_post_type( $id ) ) {
			try {
				( new Store() )->enqueue_roots( array( $id ), 0 );
				Worker::kick();
				self::redirect( 'preview', 'queued', array( 'q' => $id ) );
			} catch ( \Throwable $error ) {
				Worker::record_error( $error );
			}
		}
		self::redirect( 'preview', 'queue_error', array( 'q' => $id ) );
	}

	public static function retry(): void {
		self::require_capability();
		check_admin_referer( 'nttc_retry' );
		try {
			( new Store() )->resume_notices();
			Worker::kick();
		} catch ( \Throwable $error ) {
			Worker::record_error( $error );
			self::redirect( 'status', 'queue_error' );
		}
		self::redirect( 'status', 'retry' );
	}

	public static function product_fields(): void {
		echo '<div class="options_group show_if_simple show_if_variable">';
		woocommerce_wp_text_input( array(
			'id' => Settings::TITLE_META, 'label' => 'عنوان پایه در ترب', 'desc_tip' => true,
			'description' => 'در صورت خالی بودن، عنوان ووکامرس استفاده می‌شود. رنگ و سایر ویژگی‌ها طبق تنظیمات افزونه افزوده می‌شوند.',
		) );
		woocommerce_wp_checkbox( array( 'id' => Settings::EXCLUDE_META, 'label' => 'مستثنی از فید ترب' ) );
		echo '</div>';
	}

	public static function variation_fields( int $index, array $data, \WP_Post $post ): void {
		woocommerce_wp_text_input( array(
			'id' => 'nttc_var_title_' . $index, 'name' => 'nttc_var_title[' . $index . ']', 'wrapper_class' => 'form-row form-row-first',
			'value' => get_post_meta( $post->ID, Settings::TITLE_META, true ), 'label' => 'عنوان پایه در ترب',
			'desc_tip' => true, 'description' => 'برای استفاده از عنوان والد، خالی بگذار. نام رنگ به عنوان افزوده می‌شود.',
		) );
		woocommerce_wp_checkbox( array(
			'id' => 'nttc_var_exclude_' . $index, 'name' => 'nttc_var_exclude[' . $index . ']', 'wrapper_class' => 'form-row form-row-last',
			'value' => get_post_meta( $post->ID, Settings::EXCLUDE_META, true ), 'label' => 'مستثنی از فید ترب',
		) );
	}

	public static function save_product( \WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product edit nonce before invoking this hook.
		if ( isset( $_POST[ Settings::TITLE_META ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the edit nonce and save_meta sanitizes the title.
			self::save_meta( $product, self::input( $_POST, Settings::TITLE_META ), isset( $_POST[ Settings::EXCLUDE_META ] ) );
		}
	}

	public static function save_variation( \WC_Product_Variation $product, int $index ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product or variation save nonce before invoking this hook.
		if ( ! is_array( $_POST['nttc_var_title'] ?? null ) || ! isset( $_POST['nttc_var_title'][ $index ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce authenticates the request before saving variations.
		$title = sanitize_text_field( wp_unslash( $_POST['nttc_var_title'][ $index ] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The checkbox is read from the authenticated WooCommerce variation form.
		$excluded = is_array( $_POST['nttc_var_exclude'] ?? null ) && isset( $_POST['nttc_var_exclude'][ $index ] );
		self::save_meta( $product, $title, $excluded );
	}

	private static function save_meta( \WC_Product $product, string $title, bool $excluded ): void {
		$title = Settings::text( $title, 350 );
		if ( '' !== $title ) {
			$product->update_meta_data( Settings::TITLE_META, $title );
		} else {
			$product->delete_meta_data( Settings::TITLE_META );
		}
		if ( $excluded ) {
			$product->update_meta_data( Settings::EXCLUDE_META, 'yes' );
		} else {
			$product->delete_meta_data( Settings::EXCLUDE_META );
		}
	}

	private static function notice(): void {
		$messages = array(
			'saved' => 'تنظیمات ذخیره شد.',
			'rebuild' => 'بازسازی فهرست در صف قرار گرفت.',
			'ran' => 'درخواست پردازش اجرا شد. نتیجه در وضعیت همگام‌سازی نمایش داده می‌شود.',
			'queued' => 'محصول در صف به‌روزرسانی قرار گرفت.',
			'retry' => 'تلاش دوباره برای اعلان‌های متوقف‌شده در صف قرار گرفت.',
			'queue_error' => 'عملیات صف کامل نشد. وضعیت همگام‌سازی را بررسی کن.',
			'token_invalid' => 'توکن باید بدون فاصله و شکست خط باشد. تنظیمات قبلی معتبر است.',
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This key selects a predefined display message.
		$key = sanitize_key( self::input( $_GET, 'nttc_note' ) );
		if ( isset( $messages[ $key ] ) ) {
			printf( '<p class="nttc-message%s" role="status">%s</p>', in_array( $key, array( 'queue_error', 'token_invalid' ), true ) ? ' is-warn' : '', esc_html( $messages[ $key ] ) );
		}
	}

	private static function checkbox( string $name, string $label, array $settings ): void {
		printf( '<label class="nttc-check"><input type="checkbox" name="%s" value="1" %s>%s</label>',
			esc_attr( $name ), checked( ! empty( $settings[ $name ] ), true, false ), esc_html( $label ) );
	}

	private static function action_button( string $action, string $label, int $product_id = 0, string $confirmation = '' ): void {
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<?php self::action_fields( $action ); ?>
			<?php if ( $product_id ) : ?><input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>"><?php endif; ?>
			<button class="button" type="submit" data-nttc-confirm="<?php echo esc_attr( $confirmation ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function action_fields( string $action ): void {
		echo '<input type="hidden" name="action" value="nttc_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'nttc_' . $action );
	}

	private static function require_capability(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'دسترسی مدیریت ووکامرس لازم است.', '', array( 'response' => 403 ) );
		}
	}

	private static function input( array $source, string $key ): string {
		return isset( $source[ $key ] ) && is_string( $source[ $key ] ) ? wp_unslash( $source[ $key ] ) : '';
	}

	private static function url( string $tab = 'connection', array $params = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ) + $params, admin_url( 'admin.php' ) );
	}

	private static function redirect( string $tab, string $notice, array $params = array() ): void {
		wp_safe_redirect( self::url( $tab, array( 'nttc_note' => $notice ) + $params ) );
		exit;
	}

	private static function timestamp( $time ): string {
		return $time ? wp_date( 'Y/m/d H:i', (int) $time ) : 'هنوز ثبت نشده';
	}
}
