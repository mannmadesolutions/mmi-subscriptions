<?php
/**
 * Bricks element: Account - Subscriptions.
 *
 * The My Account → Subscriptions list (MMI_Subscriptions_My_Account::
 * render_subscription_list()) as a native-style WooCommerce Account element,
 * with the same Table controls as Bricks' own "Account - Orders" element — the
 * markup is WooCommerce's orders table, so its selectors match one to one.
 * Loaded only through MMI_Subscriptions_Bricks::register_elements().
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_Bricks_Account_Subscriptions_Element extends \Bricks\Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'mmi-account-subscriptions';
	public $icon            = 'ti-reload';
	public $panel_condition = [ [ 'templateType', '=', 'wc_account_mmi_subscriptions' ], [ 'wooPage', '=', 'myaccount' ] ];

	public function get_label() {
		return esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Subscriptions', 'mmi-subscriptions' );
	}

	public function get_keywords() {
		return [ 'account', 'subscription', 'subscriptions', 'renewal', 'mmi', 'woocommerce' ];
	}

	public function set_control_groups() {
		$this->control_groups['content'] = [ 'title' => esc_html__( 'Content', 'bricks' ) ];
		$this->control_groups['table']   = [ 'title' => esc_html__( 'Table', 'bricks' ) ];
		$this->control_groups['empty']   = [ 'title' => esc_html__( 'Empty state', 'mmi-subscriptions' ) ];
	}

	public function set_controls() {
		$d     = MMI_Subscriptions_My_Account::markup_defaults();
		$table = '.woocommerce-orders-table';

		/* ── Content ───────────────────────────────────────────────────── */

		$this->controls['title'] = [
			'group'       => 'content',
			'label'       => esc_html__( 'Title', 'bricks' ),
			'type'        => 'text',
			'placeholder' => $d['title'],
		];
		// Account templates title the page with a native Heading element, so a
		// newly added element starts with its own title hidden.
		$this->controls['hideTitle'] = [
			'group'   => 'content',
			'label'   => esc_html__( 'Hide title', 'mmi-subscriptions' ),
			'type'    => 'checkbox',
			'default' => true,
		];
		$this->controls = array_merge(
			$this->controls,
			$this->controls_grouping( $this->generate_standard_controls( 'heading', '.mmi-subs-title', [ 'typography', 'margin' ] ), 'content' )
		);

		/* ── Table (same selectors as Bricks' Account - Orders) ────────── */

		$table_controls = $this->generate_standard_controls( 'table', $table );
		unset( $table_controls['tableMargin'], $table_controls['tablePadding'] );
		$this->controls = array_merge( $this->controls, $this->controls_grouping( $table_controls, 'table' ) );

		foreach ( [
			'thead'        => [ esc_html__( 'Head', 'bricks' ), "{$table} thead th, {$table} tbody td::before, {$table} tbody th::before" ],
			'tbody'        => [ esc_html__( 'Body', 'bricks' ), "{$table} tbody td" ],
			'tbodyHeading' => [ esc_html__( 'Body', 'bricks' ) . ' - ' . esc_html__( 'Heading', 'bricks' ), "{$table} tbody th" ],
		] as $key => [ $label, $selector ] ) {
			$this->controls[ "{$key}Sep" ] = [ 'group' => 'table', 'type' => 'separator', 'label' => $label ];

			$controls = $this->generate_standard_controls( $key, $selector );
			unset( $controls[ "{$key}Margin" ], $controls[ "{$key}BoxShadow" ] );
			$this->controls = array_merge( $this->controls, $this->controls_grouping( $controls, 'table' ) );
		}

		$this->controls['tbodyLinksSep'] = [ 'group' => 'table', 'type' => 'separator', 'label' => esc_html__( 'Links', 'bricks' ) ];
		$this->controls = array_merge(
			$this->controls,
			$this->controls_grouping( $this->generate_standard_controls( 'tbodyLinks', "{$table} tbody td a:not(.woocommerce-button), {$table} tbody th a", [ 'typography' ] ), 'table' )
		);

		$this->controls['buttonSeparator'] = [ 'group' => 'table', 'type' => 'separator', 'label' => esc_html__( 'Button', 'bricks' ) ];
		$button_controls = $this->generate_standard_controls( 'button', "{$table} a.woocommerce-button" );
		unset( $button_controls['buttonMargin'] );
		$this->controls = array_merge( $this->controls, $this->controls_grouping( $button_controls, 'table' ) );

		$this->controls['destructiveButtonSep'] = [ 'group' => 'table', 'type' => 'separator', 'label' => esc_html__( 'Destructive button (Cancel)', 'mmi-subscriptions' ) ];
		$this->controls = array_merge(
			$this->controls,
			$this->controls_grouping( $this->generate_standard_controls( 'destructiveButton', "{$table} a.woocommerce-button.mmi-sub-action-cancel", [ 'background-color', 'border', 'box-shadow', 'typography' ] ), 'table' )
		);

		/* ── Empty state ───────────────────────────────────────────────── */

		foreach ( [
			'emptyText'      => [ esc_html__( 'Text', 'bricks' ), $d['empty_text'] ],
			'emptyButton'    => [ esc_html__( 'Button text', 'bricks' ), $d['empty_button'] ],
			'emptyButtonUrl' => [ esc_html__( 'Button URL', 'mmi-subscriptions' ), esc_html__( 'Shop page', 'mmi-subscriptions' ) ],
		] as $key => [ $label, $placeholder ] ) {
			$this->controls[ $key ] = [
				'group'       => 'empty',
				'label'       => $label,
				'type'        => 'text',
				'placeholder' => $placeholder,
			];
		}
		$this->controls = array_merge(
			$this->controls,
			$this->controls_grouping( $this->generate_standard_controls( 'emptyBox', '.woocommerce-info', [ 'typography', 'padding', 'background-color', 'border' ] ), 'empty' ),
			[ 'emptyButtonSep' => [ 'group' => 'empty', 'type' => 'separator', 'label' => esc_html__( 'Button', 'bricks' ) ] ],
			$this->controls_grouping( $this->generate_standard_controls( 'emptyButtonStyle', '.woocommerce-info .button', [ 'typography', 'padding', 'background-color', 'border' ] ), 'empty' )
		);
	}

	public function render() {
		$s = $this->settings;

		if ( ! is_user_logged_in() ) {
			if ( bricks_is_builder() || bricks_is_builder_call() ) {
				$this->render_element_placeholder( [ 'title' => esc_html__( 'Subscriptions show here for the logged-in customer.', 'mmi-subscriptions' ) ] );
			}
			return;
		}

		ob_start();
		MMI_Subscriptions_My_Account::render_subscription_list(
			mmisub_get_subscriptions_for_user( get_current_user_id() ),
			[
				'title'            => ! empty( $s['hideTitle'] ) ? false : ( $s['title'] ?? '' ),
				'empty_text'       => $s['emptyText'] ?? '',
				'empty_button'     => $s['emptyButton'] ?? '',
				'empty_button_url' => $s['emptyButtonUrl'] ?? '',
			]
		);
		$markup = ob_get_clean();

		echo "<div {$this->render_attributes( '_root' )}>{$markup}</div>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_subscription_list().
	}
}
