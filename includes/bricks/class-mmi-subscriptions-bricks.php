<?php
/**
 * Bricks integration for My Account → Subscriptions.
 *
 * Adds two template types alongside Bricks' own wc_account_* types, the same
 * pair Bricks ships for orders:
 *
 *   "WooCommerce - Account - Subscriptions"     (list,   like Account - Orders)
 *   "WooCommerce - Account - View subscription" (single, like Account - View order)
 *
 * plus a matching WooCommerce Account element for each
 * (bricks/element-account-subscriptions.php, element-account-view-subscription.php).
 *
 * Dynamic tag {mmi_subscription_id}: the viewed subscription's number, so a
 * native Heading element can title the View subscription template
 * ("Subscription #{mmi_subscription_id}") the way every other account
 * template uses a native Heading.
 *
 * No-code contract: publish a non-empty template of either type and it
 * replaces that endpoint's default markup — see
 * MMI_Subscriptions_My_Account::render_endpoint() / render_single_subscription().
 * No shortcode, no conditions, no setting to pick.
 *
 * Bricks hardcodes its wc_account_* types in \Bricks\Woocommerce (builder
 * preview wrapper, preview redirect, body classes, nav active state), so each
 * of those is mirrored here for the custom types — same approach as
 * mmi-admin's MMI_Bricks_Account_Licenses.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_Bricks {

	/** Template type: subscriptions list. */
	const TYPE_LIST = 'wc_account_mmi_subscriptions';

	/** Template type: single subscription. */
	const TYPE_VIEW = 'wc_account_mmi_view_subscription';

	/** Element names (also each element's $name). */
	const ELEMENT_LIST = 'mmi-account-subscriptions';
	const ELEMENT_VIEW = 'mmi-account-view-subscription';

	/** Bricks' post meta holding a template's type. */
	const BRICKS_TYPE_META = '_bricks_template_type';

	/** Bricks' post meta holding a template's element tree. */
	const BRICKS_CONTENT_META = '_bricks_page_content_2';

	/** Transient prefix caching each type's resolved template id (0 = none). */
	const TEMPLATE_CACHE_PREFIX = 'mmisub_bricks_template_';

	/** Dynamic tag (without braces): number of the subscription being viewed. */
	const TAG_ID = 'mmi_subscription_id';

	public static function init(): void {
		// Bricks registers its own elements on 'init' priority 10.
		add_action( 'init', [ self::class, 'register_elements' ], 11 );
		add_action( 'save_post_bricks_template', [ self::class, 'flush_template_cache' ] );
		add_action( 'deleted_post', [ self::class, 'flush_template_cache' ] );
		add_action( 'trashed_post', [ self::class, 'flush_template_cache' ] );
		add_action( 'updated_post_meta', [ self::class, 'maybe_flush_on_meta' ], 10, 3 );
		add_action( 'added_post_meta', [ self::class, 'maybe_flush_on_meta' ], 10, 3 );

		// Listed after Bricks' own Woo types (its filter runs at 10).
		add_filter( 'bricks/setup/control_options', [ self::class, 'add_template_types' ], 11 );
		// Bricks strips conditions from its Woo types at priority 9.
		add_filter( 'builder/settings/template/controls_data', [ self::class, 'hide_template_conditions' ], 11 );
		add_filter( 'bricks/builder/dynamic_wrapper', [ self::class, 'builder_dynamic_wrapper' ], 11 );
		add_filter( 'woocommerce_account_menu_item_classes', [ self::class, 'account_menu_item_classes' ], 11, 2 );
		add_filter( 'body_class', [ self::class, 'body_class' ], 11 );
		add_action( 'template_redirect', [ self::class, 'preview_redirect' ] );

		add_filter( 'bricks/dynamic_tags_list', [ self::class, 'register_tags' ] );
		add_filter( 'bricks/dynamic_data/render_tag', [ self::class, 'render_tag' ], 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', [ self::class, 'render_content' ], 20, 3 );
		add_filter( 'bricks/frontend/render_data', [ self::class, 'render_content' ], 20, 2 );
	}

	public static function register_tags( $tags ) {
		$tags[] = [
			'name'  => '{' . self::TAG_ID . '}',
			'label' => esc_html__( 'Subscription number (View subscription)', 'mmi-subscriptions' ),
			'group' => 'WooCommerce',
		];
		return $tags;
	}

	/**
	 * The subscription being viewed: the endpoint's, when the customer owns it,
	 * or — while designing the template — the same one the element previews.
	 *
	 * @param \WP_Post|mixed $post
	 */
	private static function viewed_subscription_id( $post ): int {
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : (int) get_the_ID();
		if ( self::TYPE_VIEW === self::current_type( $post_id ) ) {
			$subscription = self::preview_subscription( self::preview_setting( $post_id ), true );
			return $subscription ? (int) $subscription->get_id() : 0;
		}

		$subscription = mmisub_get_subscription( absint( get_query_var( 'view-mmi-subscription' ) ) );
		$user_id      = get_current_user_id();
		return ( $subscription && $user_id && (int) $subscription->get_customer_id() === $user_id ) ? (int) $subscription->get_id() : 0;
	}

	public static function render_tag( $tag, $post, $context = 'text' ) {
		if ( ! is_string( $tag ) || trim( $tag, '{} ' ) !== self::TAG_ID ) {
			return $tag;
		}
		$id = self::viewed_subscription_id( $post );
		return $id ? (string) $id : '';
	}

	public static function render_content( $content, $post, $context = 'text' ) {
		$tag = '{' . self::TAG_ID . '}';
		if ( ! is_string( $content ) || strpos( $content, $tag ) === false ) {
			return $content;
		}
		$id = self::viewed_subscription_id( $post );
		return str_replace( $tag, $id ? (string) $id : '', $content );
	}

	/** @return array<string,string> Template type => label, in Bricks' "Account - …" naming. */
	private static function types(): array {
		$prefix = 'WooCommerce - ' . esc_html__( 'Account', 'bricks' ) . ' - ';
		return [
			self::TYPE_LIST => $prefix . esc_html__( 'Subscriptions', 'mmi-subscriptions' ),
			self::TYPE_VIEW => $prefix . esc_html__( 'View subscription', 'mmi-subscriptions' ),
		];
	}

	public static function register_elements(): void {
		if ( ! class_exists( '\Bricks\Elements' ) || ! class_exists( '\Bricks\Woo_Element' ) ) {
			return;
		}
		\Bricks\Elements::register_element( MMI_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/bricks/element-account-subscriptions.php', 'mmi-account-subscriptions', 'MMI_Subscriptions_Bricks_Account_Subscriptions_Element' );
		\Bricks\Elements::register_element( MMI_SUBSCRIPTIONS_PLUGIN_DIR . 'includes/bricks/element-account-view-subscription.php', 'mmi-account-view-subscription', 'MMI_Subscriptions_Bricks_Account_View_Subscription_Element' );
	}

	/**
	 * Newest published template of $type that has content, else 0. An empty
	 * one (freshly created) never blanks the live endpoint.
	 * Filterable via 'mmi_subscriptions_bricks_template_id' to pin one explicitly.
	 */
	public static function template_id( string $type ): int {
		$cached = get_transient( self::TEMPLATE_CACHE_PREFIX . $type );
		if ( false === $cached ) {
			global $wpdb;
			$cached = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				   JOIN {$wpdb->postmeta} pt ON pt.post_id = p.ID AND pt.meta_key = %s
				   JOIN {$wpdb->postmeta} pc ON pc.post_id = p.ID AND pc.meta_key = %s
				  WHERE p.post_type = 'bricks_template' AND p.post_status = 'publish'
				    AND pt.meta_value = %s AND pc.meta_value NOT IN ( '', 'a:0:{}' )
				  ORDER BY p.post_modified_gmt DESC
				  LIMIT 1",
				self::BRICKS_TYPE_META,
				self::BRICKS_CONTENT_META,
				$type
			) );
			set_transient( self::TEMPLATE_CACHE_PREFIX . $type, $cached, DAY_IN_SECONDS );
		}
		return (int) apply_filters( 'mmi_subscriptions_bricks_template_id', (int) $cached, $type );
	}

	public static function flush_template_cache(): void {
		foreach ( array_keys( self::types() ) as $type ) {
			delete_transient( self::TEMPLATE_CACHE_PREFIX . $type );
		}
	}

	/** Bricks saves element trees and template types via update_post_meta, not always via save_post. */
	public static function maybe_flush_on_meta( $meta_id, $post_id, $meta_key ): void {
		if ( in_array( $meta_key, [ self::BRICKS_CONTENT_META, self::BRICKS_TYPE_META ], true ) && 'bricks_template' === get_post_type( $post_id ) ) {
			self::flush_template_cache();
		}
	}

	/** Our template type for $post_id (default: current post), or '' if it isn't one. */
	private static function current_type( $post_id = 0 ): string {
		$post_id = $post_id ?: get_the_ID();
		if ( ! $post_id || 'bricks_template' !== get_post_type( $post_id ) ) {
			return '';
		}
		$type = (string) get_post_meta( $post_id, self::BRICKS_TYPE_META, true );
		return isset( self::types()[ $type ] ) ? $type : '';
	}

	/**
	 * Add both types right after Bricks' "Account - View order", so they sort
	 * with the order types in every type list (template editor, Templates
	 * admin filter, template browser).
	 */
	public static function add_template_types( $control_options ) {
		if ( ! isset( $control_options['templateTypes'] ) || ! is_array( $control_options['templateTypes'] ) ) {
			return $control_options;
		}

		$ours  = self::types();
		$types = [];
		foreach ( $control_options['templateTypes'] as $key => $value ) {
			$types[ $key ] = $value;
			if ( 'wc_account_view_order' === $key ) {
				$types += $ours;
			}
		}
		$types += $ours;

		$control_options['templateTypes'] = $types;
		return $control_options;
	}

	/**
	 * Treat our types like Bricks' own Account types in template settings:
	 * no conditions or preview-post panel, just "automatically rendered".
	 * Bricks' Woocommerce::remove_template_conditions() (priority 9) builds
	 * these 'required' rules from its hardcoded type list; append ours.
	 */
	public static function hide_template_conditions( $settings ) {
		$ours   = array_keys( self::types() );
		$append = static function ( &$required ) use ( $ours ) {
			if ( is_array( $required ) && isset( $required[0], $required[2] ) && 'templateType' === $required[0] && is_array( $required[2] ) && in_array( 'wc_account_view_order', $required[2], true ) ) {
				$required[2] = array_values( array_unique( array_merge( $required[2], $ours ) ) );
			}
		};

		if ( isset( $settings['controlGroups']['template-preview']['required'] ) ) {
			$append( $settings['controlGroups']['template-preview']['required'] );
		}
		if ( isset( $settings['controls'] ) && is_array( $settings['controls'] ) ) {
			foreach ( $settings['controls'] as &$control ) {
				if ( isset( $control['required'] ) ) {
					$append( $control['required'] );
				}
			}
			unset( $control );
		}

		return $settings;
	}

	/**
	 * Builder canvas: render the real My Account page around the template so
	 * it's edited in place, as Bricks does for its own Account types in
	 * Woocommerce::builder_dynamic_wrapper() (hardcoded type list).
	 */
	public static function builder_dynamic_wrapper( $dynamic_area ) {
		if ( ! empty( $dynamic_area ) || ! self::current_type() || ! function_exists( 'wc_get_page_id' ) ) {
			return $dynamic_area;
		}

		$my_account_page_id = wc_get_page_id( 'myaccount' );
		$elements           = \Bricks\Helpers::render_with_bricks( $my_account_page_id ) ? get_post_meta( $my_account_page_id, BRICKS_DB_PAGE_CONTENT, true ) : false;

		if ( is_array( $elements ) && ! empty( $elements ) ) {
			ob_start();
			\Bricks\Frontend::render_content( $elements );
			$html = ob_get_clean();

			if ( $html ) {
				$css  = \Bricks\Templates::generate_inline_css( $my_account_page_id, $elements );
				$css .= \Bricks\Assets::generate_global_classes( 'global_classes_woocommerce_account' );
				$css .= \Bricks\Assets::$inline_css_dynamic_data;

				return [
					'css'      => $css,
					'html'     => $html,
					'selector' => '.woocommerce-MyAccount-content',
				];
			}
		}

		ob_start();
		echo '<main id="brx-content" class="wordpress">';
		echo do_shortcode( '[woocommerce_my_account]' );
		echo '</main>';

		return [
			'css'      => '',
			'html'     => ob_get_clean(),
			'selector' => '.woocommerce-MyAccount-content',
		];
	}

	/** Builder canvas: mark "Subscriptions" as the active My Account tab (for both types, as Bricks does for Orders/View order). */
	public static function account_menu_item_classes( $classes, $endpoint ) {
		if ( ! function_exists( 'bricks_is_builder_iframe' ) || ! bricks_is_builder_iframe() || ! self::current_type() ) {
			return $classes;
		}

		$classes = array_values( array_diff( (array) $classes, [ 'is-active' ] ) );
		if ( MMI_Subscriptions_My_Account::ENDPOINT === $endpoint ) {
			$classes[] = 'is-active';
		}
		return $classes;
	}

	/** Woo account styling while editing/previewing (Bricks adds this for its own types). */
	public static function body_class( $classes ) {
		if ( self::current_type() ) {
			$classes[] = 'woocommerce-account';
		}
		return $classes;
	}

	/**
	 * Frontend "Preview" of the template → the live endpoint, as Bricks does
	 * for its Account types. View subscription previews the element's
	 * "Preview subscription ID" when the previewer owns it, else their newest
	 * subscription, else falls back to the list.
	 */
	public static function preview_redirect(): void {
		if ( ! function_exists( 'bricks_is_frontend' ) || ! bricks_is_frontend() || ! is_singular( 'bricks_template' ) ) {
			return;
		}
		$type = self::current_type();
		if ( ! $type ) {
			return;
		}

		$url = wc_get_account_endpoint_url( MMI_Subscriptions_My_Account::ENDPOINT );
		if ( self::TYPE_VIEW === $type ) {
			$subscription = self::preview_subscription( self::preview_setting( get_the_ID() ), false );
			if ( $subscription ) {
				$url = MMI_Subscriptions_My_Account::get_view_url( $subscription );
			}
		}

		wp_safe_redirect( add_query_arg( 'bricks_preview', time(), $url ), 301 );
		exit;
	}

	/** "Preview subscription ID" set on the View subscription element in template $post_id, or 0. */
	private static function preview_setting( int $post_id ): int {
		$elements = get_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, true );
		foreach ( is_array( $elements ) ? $elements : [] as $element ) {
			if ( ( $element['name'] ?? '' ) === self::ELEMENT_VIEW && ! empty( $element['settings']['previewSubscriptionId'] ) ) {
				return absint( $element['settings']['previewSubscriptionId'] );
			}
		}
		return 0;
	}

	/**
	 * Subscription to show while designing: $preferred_id, else the current
	 * user's newest. With $any_customer (builder canvas only), shop managers
	 * may also preview another customer's subscription — the canvas is
	 * read-only, and every action link re-checks ownership server-side.
	 */
	public static function preview_subscription( int $preferred_id, bool $any_customer ): ?MMI_Subscription {
		$user_id = get_current_user_id();
		$manager = $any_customer && mmi_subscriptions_user_can();

		if ( $preferred_id ) {
			$subscription = mmisub_get_subscription( $preferred_id );
			if ( $subscription && ( $manager || (int) $subscription->get_customer_id() === $user_id ) ) {
				return $subscription;
			}
		}

		$query = [
			'type'    => 'shop_mmi_sub',
			'limit'   => 1,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'ids',
		];
		if ( ! $manager ) {
			if ( ! $user_id ) {
				return null;
			}
			$query['customer_id'] = $user_id;
		}
		$ids = wc_get_orders( $query );

		return $ids ? ( mmisub_get_subscription( (int) $ids[0] ) ?: null ) : null;
	}
}
