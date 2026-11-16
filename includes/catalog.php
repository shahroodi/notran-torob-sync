<?php

namespace Notran\Torob;

defined( 'ABSPATH' ) || exit;

final class Catalog {
	private $settings;
	private $price_factor;

	public function __construct( array $settings ) {
		$this->settings = $settings;
		$this->price_factor = Settings::price_factor( $settings );
	}

	/**
	 * Build the rows from WooCommerce. The administration preview uses the same builder.
	 *
	 * @return array{rows:array,notes:array}
	 */
	public function build( int $root_id ): array {
		$result = array( 'rows' => array(), 'notes' => array() );
		$product = wc_get_product( $root_id );
		$reason = $this->root_exclusion( $product );
		if ( '' !== $reason ) {
			$result['notes'][ $root_id ][] = $reason;
			return $result;
		}
		if ( null === $this->price_factor ) {
			$result['notes'][ $root_id ][] = 'واحد پول فروشگاه را در تنظیمات افزونه انتخاب کنید.';
			return $result;
		}

		$category = $this->category( $product );
		if ( $product->is_type( 'simple' ) ) {
			$this->add_row( $result, $product, $product, array(), '', $category );
			return $result;
		}

		$variation_options = Urls::variation_options( $product );
		$color = $this->color_attribute( $product, array_keys( $variation_options ) );
		$ids = $product->get_children();
		if ( ! $ids ) {
			$result['notes'][ $root_id ][] = 'برای این محصول هنوز تنوعی تعریف نشده است.';
			return $result;
		}

		_prime_post_caches( $ids );
		$earlier = array();
		$defaults = $product->get_default_attributes( 'edit' );
		foreach ( $ids as $id ) {
			$variation = wc_get_product( $id );
			if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() ) {
				$result['notes'][ $id ][] = 'تنوع غیرفعال است.';
				continue;
			}

			$assigned = $variation->get_variation_attributes( false );
			$options = $this->selection_options( $variation_options, $assigned, $defaults );
			$selection = $options ? $this->choose_selection( $options, $earlier ) : null;

			// A published sibling can win WooCommerce's matching even when excluded from the feed.
			$earlier[] = array_filter(
				array_intersect_key( $assigned, $variation_options ),
				static function ( $value ) {
					return '' !== $value;
				}
			);

			if ( 'yes' === $variation->get_meta( Settings::EXCLUDE_META ) ) {
				$result['notes'][ $id ][] = 'این تنوع از خروجی ترب مستثنی شده است.';
				continue;
			}

			if ( null === $selection ) {
				$result['notes'][ $id ][] = 'ترکیب قابل انتخابِ اختصاصی پیدا نشد. گزینه‌های تنوع و ترتیب تنوع‌های هم‌پوشان را در ووکامرس بررسی کنید.';
				continue;
			}

			if ( '' !== $color && '' === ( $assigned[ $color ] ?? '' ) ) {
				$result['notes'][ $id ][] = 'رنگ این تنوع «هر رنگ» است. لینک و عنوان از یک گزینهٔ قابل انتخاب همان تنوع استفاده می‌کنند.';
			}
			if ( '' === $color ) {
				$result['notes'][ $id ][] = 'ویژگی رنگ پیدا نشد. اگر محصول رنگی است، نام یا نامک ویژگی رنگ را در تنظیمات افزونه وارد کنید.';
			}
			$this->add_row( $result, $variation, $product, $selection, $color, $category );
		}
		return $result;
	}

	private function root_exclusion( $product ): string {
		if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() ) {
			return 'محصول منتشر نشده یا حذف شده است.';
		}
		if ( '' !== (string) get_post_field( 'post_password', $product->get_id() ) ) {
			return 'محصول با رمز محافظت می‌شود.';
		}
		if ( ! $product->is_type( array( 'simple', 'variable' ) ) ) {
			return 'خروجی ترب برای محصولات ساده و متغیر ووکامرس آماده می‌شود.';
		}
		if ( 'yes' === $product->get_meta( Settings::EXCLUDE_META ) ) {
			return 'محصول از خروجی ترب مستثنی شده است.';
		}
		if ( empty( $this->settings['hidden'] ) && 'hidden' === $product->get_catalog_visibility() ) {
			return 'نمایش محصول در کاتالوگ ووکامرس روی «مخفی» تنظیم شده است.';
		}
		return '';
	}

	private function add_row( array &$result, \WC_Product $item, \WC_Product $root, array $selected, string $color, string $category ): void {
		$id = $item->get_id();
		$is_variation = $item instanceof \WC_Product_Variation;
		$price = $item->get_price();

		if ( '' === $price || ! is_numeric( $price ) || ( $is_variation && ! $item->variation_is_visible() ) ) {
			$result['notes'][ $id ][] = 'قیمت معتبر یا تنوع قابل نمایش در ووکامرس وجود ندارد.';
			return;
		}

		$url = $is_variation ? Urls::variation( $root, $id, $selected ) : $root->get_permalink();
		if ( strlen( $url ) > 1500 || ! Urls::authority( $url ) || Urls::authority( $url ) !== Urls::authority( home_url( '/' ) ) ) {
			$result['notes'][ $id ][] = 'لینک صفحه محصول معتبر نیست یا با دامنه فروشگاه مطابقت ندارد.';
			return;
		}

		$spec = $this->specs( $root, $selected );
		$title = $this->title( $item, $root, $selected, $color );
		if ( '' === $title ) {
			$result['notes'][ $id ][] = 'عنوان محصول خالی است.';
			return;
		}

		$available = $item->is_in_stock() && $item->is_purchasable();
		if ( $available && empty( $this->settings['backorders'] ) && $item->is_on_backorder( 1 ) ) {
			$available = false;
		}

		$current = $available ? $this->price( $item, (float) $price ) : 0;
		if ( null === $current || ( $available && (float) $price > 0 && 0 === $current ) ) {
			$result['notes'][ $id ][] = 'قیمت پس از تبدیل به تومان قابل ارسال نیست. واحد پول یا قیمت محصول را بررسی کنید.';
			return;
		}

		$created = $item->get_date_created() ?: $root->get_date_created();
		$created_at = $created ? $created->getTimestamp() : time();
		$modified = $item->get_date_modified();
		$root_modified = $root->get_date_modified();
		$images = $this->images( $item, $root, $result['notes'] );

		$payload = array(
			'page_unique'   => (string) $id,
			'page_url'      => $url,
			'title'         => $title,
			'current_price' => $current,
			'availability'  => $available,
			'image_links'   => $images,
			'spec'          => (object) $spec,
			'date_added'    => gmdate( 'c', $created_at ),
		);
		if ( $is_variation ) {
			$payload['product_group_id'] = (string) $root->get_id();
		}
		if ( '' !== $category ) {
			$payload['category_name'] = $category;
		}
		$description = $item->get_description();
		if ( ! $is_variation || '' === $description ) {
			$description = $root->get_short_description();
		}
		$description = Settings::text( strip_shortcodes( $description ), 500 );
		if ( '' !== $description ) {
			$payload['short_desc'] = $description;
		}
		$regular = $item->get_regular_price();
		if ( $available && $item->is_on_sale() && is_numeric( $regular ) ) {
			$old_price = $this->price( $item, (float) $regular );
			if ( null !== $old_price && $old_price > $current ) {
				$payload['old_price'] = $old_price;
			}
		}

		$result['rows'][ $id ] = array(
			'wc_id'      => $id,
			'root_id'    => $root->get_id(),
			'url_key'    => Urls::key( $url ),
			'created_at' => $created_at,
			'source_at'  => max( $created_at, $modified ? $modified->getTimestamp() : 0, $root_modified ? $root_modified->getTimestamp() : 0 ),
			'payload'    => $payload,
		);
	}

	private function price( \WC_Product $item, float $raw ): ?int {
		$price = round( wc_get_price_to_display( $item, array( 'price' => $raw ) ) * $this->price_factor );
		if ( ! is_finite( $price ) || $price < 0 || $price >= PHP_INT_MAX ) {
			return null;
		}
		return (int) $price;
	}

	private function category( \WC_Product $product ): string {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}
		$deepest = -1;
		$name = '';
		foreach ( $terms as $term ) {
			$depth = count( get_ancestors( $term->term_id, 'product_cat' ) );
			if ( $depth > $deepest ) {
				$deepest = $depth;
				$name = $term->name;
			}
		}
		return Settings::text( $name, 200 );
	}

	private function images( \WC_Product $item, \WC_Product $root, array &$notes ): array {
		$own_image = $item->get_image_id( 'edit' );
		$ids = array_merge( array( $own_image, $root->get_image_id() ), $root->get_gallery_image_ids() );
		if ( is_callable( array( $item, 'get_gallery_image_ids' ) ) ) {
			$ids = array_merge( array( $own_image ), $item->get_gallery_image_ids(), $ids );
		}
		$ids = array_unique( array_filter( $ids ) );

		$images = array();
		foreach ( $ids as $id ) {
			$image = wp_get_attachment_image_src( $id, 'full' );
			if ( ! $image || ! Urls::authority( $image[0] ) || strlen( $image[0] ) > 1000 ) {
				continue;
			}
			if ( ! $images ) {
				if ( $item instanceof \WC_Product_Variation && (int) $id !== (int) $own_image ) {
					$notes[ $item->get_id() ][] = 'تصویر قابل استفادهٔ اختصاصی برای این تنوع پیدا نشد. تصویر محصول در خروجی استفاده شده است.';
				}
				if ( (int) $image[1] < 600 || (int) $image[2] < 600 ) {
					$notes[ $item->get_id() ][] = 'تصویر اول کوچک‌تر از حداقل ۶۰۰×۶۰۰ پیکسل ترب است.';
				}
			}
			$images[] = $image[0];
		}
		if ( ! $images ) {
			$notes[ $item->get_id() ][] = 'تصویری برای ارسال پیدا نشد.';
		}
		return array_values( array_unique( $images ) );
	}

	private function title( \WC_Product $item, \WC_Product $root, array $attributes, string $color ): string {
		$base = (string) $item->get_meta( Settings::TITLE_META );
		if ( '' === $base ) {
			$base = (string) $root->get_meta( Settings::TITLE_META );
		}
		if ( '' === $base ) {
			$base = $root->get_name();
		}
		$color_value = isset( $attributes[ $color ] ) ? $this->attribute_value( $color, $attributes[ $color ] ) : '';
		$color_value = Settings::text( $color_value, 200 );
		$base = Settings::text( $base, '' === $color_value ? 500 : 497 - mb_strlen( $color_value ) );
		$pieces = empty( $this->settings['color_first'] ) ? array( $base, $color_value ) : array( $color_value, $base );

		if ( ! empty( $this->settings['other_attrs'] ) ) {
			foreach ( $attributes as $key => $value ) {
				if ( $key !== $color ) {
					$pieces[] = $this->attribute_value( $key, $value );
				}
			}
		}
		return Settings::text( implode( ' – ', array_filter( $pieces, 'strlen' ) ), 500 );
	}

	private function specs( \WC_Product $root, array $selected ): array {
		$spec = array();
		foreach ( $root->get_attributes() as $attribute ) {
			$key = sanitize_title( $attribute->get_name() );
			$label = Settings::text( wc_attribute_label( $attribute->get_name(), $root ), 150 );
			if ( '' === $label ) {
				continue;
			}
			if ( isset( $selected[ $key ] ) ) {
				$spec[ $label ] = $this->attribute_value( (string) $key, $selected[ $key ] );
				continue;
			}
			if ( $attribute->get_visible() && ! $attribute->get_variation() ) {
				$spec[ $label ] = Settings::text( $root->get_attribute( $attribute->get_name() ), 500 );
			}
		}
		return array_filter( $spec, 'strlen' );
	}

	private function color_attribute( \WC_Product_Variable $root, array $keys ): string {
		$configured = $this->normalized_attribute( $this->settings['color'] );
		$names = array( 'color', 'colour', 'rang', 'رنگ', 'رنگبندی', 'تنوعرنگ', 'رنگمحصول' );
		foreach ( $keys as $key ) {
			$alias = $this->normalized_attribute( $key );
			$label = $this->normalized_attribute( wc_attribute_label( $key, $root ) );
			if ( '' !== $configured ? in_array( $configured, array( $alias, $label ), true )
				: ( in_array( $alias, $names, true ) || in_array( $label, $names, true ) ) ) {
				return $key;
			}
		}
		return '';
	}

	private function normalized_attribute( string $name ): string {
		$name = strtr( rawurldecode( $name ), array( 'ي' => 'ی', 'ك' => 'ک' ) );
		$name = preg_replace( '/^pa_/i', '', trim( $name ) );
		return strtolower( preg_replace( '/[\s_\-\x{200C}]+/u', '', $name ) ?? '' );
	}

	private function attribute_value( string $key, string $value ): string {
		if ( taxonomy_exists( $key ) ) {
			$term = get_term_by( 'slug', $value, $key );
			if ( $term ) {
				return Settings::text( $term->name, 500 );
			}
			$value = rawurldecode( $value );
		}
		return Settings::text( $value, 500 );
	}

	private function selection_options( array $options, array $assigned, array $defaults ): array {
		$allowed = array();
		foreach ( $options as $key => $values ) {
			$value = $assigned[ $key ] ?? '';
			$values = array_values( array_filter( array_map( 'strval', $values ), 'strlen' ) );
			if ( '' !== $value ) {
				if ( ! in_array( $value, $values, true ) ) {
					return array();
				}
				$allowed[ $key ] = array( $value );
				continue;
			}
			if ( ! $values ) {
				return array();
			}
			$default = $defaults[ $key ] ?? '';
			if ( in_array( $default, $values, true ) ) {
				$values = array_merge( array( $default ), array_diff( $values, array( $default ) ) );
			}
			$allowed[ $key ] = $values;
		}
		return $allowed;
	}

	/**
	 * WooCommerce resolves overlaps by menu order and ID. Choose the first valid combination
	 * of this variation that an earlier published sibling does not already match.
	 */
	private function choose_selection( array $options, array $blockers ): ?array {
		if ( in_array( array(), $blockers, true ) ) {
			return null;
		}
		if ( ! $options ) {
			return array();
		}
		$key = array_key_first( $options );
		$values = $options[ $key ];
		unset( $options[ $key ] );

		foreach ( $values as $value ) {
			$remaining = array();
			foreach ( $blockers as $blocker ) {
				if ( ! isset( $blocker[ $key ] ) || $blocker[ $key ] === $value ) {
					unset( $blocker[ $key ] );
					$remaining[] = $blocker;
				}
			}
			$tail = $this->choose_selection( $options, $remaining );
			if ( null !== $tail ) {
				return array( $key => $value ) + $tail;
			}
		}

		return null;
	}
}
