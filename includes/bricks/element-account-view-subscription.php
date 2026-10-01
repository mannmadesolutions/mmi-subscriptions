<?php
/**
 * Bricks element: Account - View subscription.
 *
 * The single-subscription page (MMI_Subscriptions_My_Account::
 * render_subscription_details()) as a native-style WooCommerce Account
 * element, modeled on Bricks' own "Account - View order": a "Preview
 * subscription ID" for the builder, and style groups per section.
 * Loaded only through MMI_Subscriptions_Bricks::register_elements().
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_Bricks_Account_View_Subscription_Element extends \Bricks\Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'mmi-account-view-subscription';
	public $icon            = 'ti-receipt';
	public $panel_condition = [ [ 'templateType', '=', 'wc_account_mmi_view_subscription' ], [ 'wooPage', '=', 'myaccount' ] ];

	public function get_label() {
		return esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'View subscription', 'mmi-subscriptions' );
	}

	public function get_keywords() {
		return [ 'account', 'subscription', 'renewal', 'payment method', 'mmi', 'woocommerce' ];
	}

	public function set_control_groups() {
		$groups = [
			'title'         => esc_html__( 'Title', 'bricks' ),
			'notice'        => esc_html__( 'Payment due notice', 'mmi-subscriptions' ),
			'details'       => esc_html__( 'Details', 'mmi-subscriptions' ),
			'actions'       => esc_html__( 'Actions', 'mmi-subscriptions' ),
			'paymentMethod' => esc_html__( 'Payment method', 'mmi-subscriptions' ),
			'relatedOrders' => esc_html__( 'Related orders', 'mmi-subscriptions' ),
		];
		foreach ( $groups as $key => $title ) {
			$this->control_groups[ $key ] = [ 'title' => $title ];
		}
	}

	public function set_controls() {
		$this->controls['previewSubscriptionId'] = [
			'type'     => 'number',
			'label'    => esc_html__( 'Preview subscription ID', 'mmi-subscriptions' ),
			'info'     => esc_html__( 'Fallback', 'bricks' ) . ': ' . esc_html__( 'Last subscription', 'mmi-subscriptions' ),
			'rerender' => true,
		];

		/* ── Title ─────────────────────────────────────────────────────── */

		// Account templates title the page with a native Heading element
		// ("Subscription #{mmi_subscription_id}"), so a newly added element
		// starts with its own title hidden.
		$this->controls['hideTitle'] = [
			'group'   => 'title',
			'label'   => esc_html__( 'Hide', 'bricks' ),
			'type'    => 'checkbox',
			'default' => true,
		];
		$this->controls = array_merge(
			$this->controls,
			$this->controls_grouping( $this->generate_standard_controls( 'heading', '.mmi-sub-title', [ 'typography', 'margin' ] ), 'title' )
		);

		/* ── Payment due notice ────────────────────────────────────────── */

		$this->controls = array_merge(
			$this->controls,
			$this->controls_grouping( $this->generate_standard_controls( 'notice', '.mmi-sub-payment-due', [ 'typography', 'padding', 'background-color', 'border' ] ), 'notice' ),
			[ 'noticeButtonSep' => [ 'group' => 'notice', 'type' => 'separator', 'label' => esc_html__( 'Button', 'bricks' ) ] ],
			$this->controls_grouping( $this->generate_standard_controls( 'noticeButton', '.mmi-sub-payment-due .button', [ 'typography', 'padding', 'background-color', 'border' ] ), 'notice' )
		);

		/* ── Details table ─────────────────────────────────────────────── */

		$details = $this->generate_standard_controls( 'details', '.mmi-sub-details' );
		unset( $details['detailsPadding'] );
		$this->controls = array_merge( $this->controls, $this->controls_grouping( $details, 'details' ) );
		foreach ( [
			'detailsTh' => [ esc_html__( 'Table', 'bricks' ) . ' - ' . esc_html__( 'Heading', 'bricks' ), '.mmi-sub-details th' ],
			'detailsTd' => [ esc_html__( 'Table', 'bricks' ) . ' - ' . esc_html__( 'Body', 'bricks' ), '.mmi-sub-details td' ],
		] as $key => [ $label, $selector ] ) {
			$this->controls[ "{$key}Sep" ] = [ 'group' => 'details', 'type' => 'separator', 'label' => $label ];

			$controls = $this->generate_standard_controls( $key, $selector );
			unset( $controls[ "{$key}Margin" ], $controls[ "{$key}BoxShadow" ] );
			$this->controls = array_merge( $this->controls, $this->controls_grouping( $controls, 'details' ) );
		}

		/* ── Actions ───────────────────────────────────────────────────── */

		$this->controls['hideActions'] = [
			'group' => 'actions',
			'label' => esc_html__( 'Hide', 'bricks' ),
			'type'  => 'checkbox',
		];
		$this->controls['actionsGap'] = [
			'group' => 'actions',
			'label' => esc_html__( 'Gap', 'bricks' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [
				[ 'property' => 'display', 'selector' => '.mmi-sub-actions', 'value' => 'flex' ],
				[ 'property' => 'flex-wrap', 'selector' => '.mmi-sub-actions', 'value' => 'wrap' ],
				[ 'property' => 'gap', 'selector' => '.mmi-sub-actions' ],
			],
		];
		$this->controls = array_merge(
			$this->controls,
			$this->controls_grouping( $this->generate_standard_controls( 'actionButton', '.mmi-sub-actions a.woocommerce-button' ), 'actions' ),
			[ 'destructiveButtonSep' => [ 'group' => 'actions', 'type' => 'separator', 'label' => esc_html__( 'Destructive button (Cancel)', 'mmi-subscriptions' ) ] ],
			$this->controls_grouping( $this->generate_standard_controls( 'destructiveButton', '.mmi-sub-actions a.woocommerce-button.mmi-sub-action-cancel', [ 'background-color', 'border', 'box-shadow', 'typography' ] ), 'actions' )
		);

		/* ── Payment method ────────────────────────────────────────────── */

		$this->controls['hidePaymentMethod'] = [
			'group' => 'paymentMethod',
			'label' => esc_html__( 'Hide', 'bricks' ),
			'type'  => 'checkbox',
		];
		$this->controls = array_merge(
			$this->controls,
			[ 'paymentMethodTitleSep' => [ 'group' => 'paymentMethod', 'type' => 'separator', 'label' => esc_html__( 'Title', 'bricks' ) ] ],
			$this->controls_grouping( $this->generate_standard_controls( 'paymentMethodTitle', '.mmi-sub-payment-method-title', [ 'typography', 'margin' ] ), 'paymentMethod' ),
			[ 'paymentMethodListSep' => [ 'group' => 'paymentMethod', 'type' => 'separator', 'label' => esc_html__( 'Saved methods', 'mmi-subscriptions' ) ] ],
			$this->controls_grouping( $this->generate_standard_controls( 'paymentMethodList', '.mmi-sub-payment-method .woocommerce-PaymentMethods', [ 'typography', 'padding', 'background-color', 'border' ] ), 'paymentMethod' ),
			[ 'paymentMethodButtonSep' => [ 'group' => 'paymentMethod', 'type' => 'separator', 'label' => esc_html__( 'Button', 'bricks' ) ] ],
			$this->controls_grouping( $this->generate_standard_controls( 'paymentMethodButton', '.mmi-sub-payment-method button.button', [ 'typography', 'padding', 'background-color', 'border' ] ), 'paymentMethod' ),
			[ 'addMethodLinkSep' => [ 'group' => 'paymentMethod', 'type' => 'separator', 'label' => esc_html__( 'Add payment method link', 'mmi-subscriptions' ) ] ]
		);

		// Link → WooCommerce's own Add payment method endpoint (URL fixed on purpose).
		$d          = MMI_Subscriptions_My_Account::markup_defaults();
		$link_shown = [ 'hideAddMethodLink', '!=', true ];

		$this->controls['hideAddMethodLink'] = [
			'group' => 'paymentMethod',
			'label' => esc_html__( 'Hide', 'bricks' ),
			'type'  => 'checkbox',
		];
		$this->controls['addMethodText'] = [
			'group'       => 'paymentMethod',
			'label'       => esc_html__( 'Text', 'bricks' ) . ' (' . esc_html__( 'has saved methods', 'mmi-subscriptions' ) . ')',
			'type'        => 'text',
			'placeholder' => $d['add_method_text'],
			'required'    => $link_shown,
		];
		$this->controls['addMethodTextEmpty'] = [
			'group'       => 'paymentMethod',
			'label'       => esc_html__( 'Text', 'bricks' ) . ' (' . esc_html__( 'no saved methods', 'mmi-subscriptions' ) . ')',
			'type'        => 'text',
			'placeholder' => $d['add_method_text_empty'],
			'required'    => $link_shown,
		];
		$this->controls['addMethodAsButton'] = [
			'group'    => 'paymentMethod',
			'label'    => esc_html__( 'Show as button', 'mmi-subscriptions' ),
			'type'     => 'checkbox',
			'required' => $link_shown,
		];
		$link_style = $this->generate_standard_controls( 'addMethodLink', '.mmi-sub-add-payment-method', [ 'typography', 'padding', 'background-color', 'border' ] );
		foreach ( $link_style as &$control ) {
			$control['required'] = $link_shown;
		}
		unset( $control );
		$this->controls = array_merge( $this->controls, $this->controls_grouping( $link_style, 'paymentMethod' ) );

		/* ── Related orders ────────────────────────────────────────────── */

		$this->controls['hideRelatedOrders'] = [
			'group' => 'relatedOrders',
			'label' => esc_html__( 'Hide', 'bricks' ),
			'type'  => 'checkbox',
		];
		$this->controls = array_merge(
			$this->controls,
			[ 'relatedOrdersTitleSep' => [ 'group' => 'relatedOrders', 'type' => 'separator', 'label' => esc_html__( 'Title', 'bricks' ) ] ],
			$this->controls_grouping( $this->generate_standard_controls( 'relatedOrdersTitle', '.mmi-sub-related-orders-title', [ 'typography', 'margin' ] ), 'relatedOrders' )
		);
		$related = '.mmi-sub-related-orders';
		foreach ( [
			'relatedOrdersThead' => [ esc_html__( 'Table', 'bricks' ) . ' - ' . esc_html__( 'Head', 'bricks' ), "{$related} thead th, {$related} tbody td::before" ],
			'relatedOrdersTbody' => [ esc_html__( 'Table', 'bricks' ) . ' - ' . esc_html__( 'Body', 'bricks' ), "{$related} tbody td" ],
		] as $key => [ $label, $selector ] ) {
			$this->controls[ "{$key}Sep" ] = [ 'group' => 'relatedOrders', 'type' => 'separator', 'label' => $label ];

			$controls = $this->generate_standard_controls( $key, $selector );
			unset( $controls[ "{$key}Margin" ], $controls[ "{$key}BoxShadow" ] );
			$this->controls = array_merge( $this->controls, $this->controls_grouping( $controls, 'relatedOrders' ) );
		}
		$this->controls = array_merge(
			$this->controls,
			[ 'relatedOrdersLinksSep' => [ 'group' => 'relatedOrders', 'type' => 'separator', 'label' => esc_html__( 'Links', 'bricks' ) ] ],
			$this->controls_grouping( $this->generate_standard_controls( 'relatedOrdersLinks', "{$related} tbody td a:not(.woocommerce-button)", [ 'typography' ] ), 'relatedOrders' ),
			[ 'relatedOrdersButtonSep' => [ 'group' => 'relatedOrders', 'type' => 'separator', 'label' => esc_html__( 'Button', 'bricks' ) ] ],
			$this->controls_grouping( $this->generate_standard_controls( 'relatedOrdersButton', "{$related} a.woocommerce-button" ), 'relatedOrders' )
		);
	}

	/**
	 * Frontend: the subscription in the view-mmi-subscription endpoint, only
	 * if the current customer owns it. Builder/template preview: the
	 * "Preview subscription ID", else the newest subscription.
	 */
	private function get_subscription(): ?MMI_Subscription {
		if ( $this->is_preview() ) {
			return MMI_Subscriptions_Bricks::preview_subscription( absint( $this->settings['previewSubscriptionId'] ?? 0 ), true );
		}

		$subscription = mmisub_get_subscription( absint( get_query_var( 'view-mmi-subscription' ) ) );
		$user_id      = get_current_user_id();

		return ( $subscription && $user_id && (int) $subscription->get_customer_id() === $user_id ) ? $subscription : null;
	}

	private function is_preview(): bool {
		return bricks_is_builder() || bricks_is_builder_call() || \Bricks\Helpers::is_bricks_template( get_the_ID() );
	}

	public function render() {
		$s            = $this->settings;
		$subscription = $this->get_subscription();

		if ( ! $subscription ) {
			if ( $this->is_preview() ) {
				$this->render_element_placeholder( [ 'title' => esc_html__( 'No subscription found to preview. Set a "Preview subscription ID".', 'mmi-subscriptions' ) ] );
			} else {
				wc_print_notice(
					esc_html__( 'Subscription not found.', 'mmi-subscriptions' ) . ' <a href="' . esc_url( wc_get_account_endpoint_url( MMI_Subscriptions_My_Account::ENDPOINT ) ) . '" class="wc-forward">' . esc_html__( 'My subscriptions', 'mmi-subscriptions' ) . '</a>',
					'error'
				);
			}
			return;
		}

		ob_start();
		MMI_Subscriptions_My_Account::render_subscription_details( $subscription, [
			'show_title'          => empty( $s['hideTitle'] ),
			'show_actions'        => empty( $s['hideActions'] ),
			'show_payment_method' => empty( $s['hidePaymentMethod'] ),
			'show_add_method_link'  => empty( $s['hideAddMethodLink'] ),
			'add_method_as_button'  => ! empty( $s['addMethodAsButton'] ),
			'add_method_text'       => $s['addMethodText'] ?? '',
			'add_method_text_empty' => $s['addMethodTextEmpty'] ?? '',
			'show_related_orders' => empty( $s['hideRelatedOrders'] ),
		] );
		$markup = ob_get_clean();

		echo "<div {$this->render_attributes( '_root' )}>{$markup}</div>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_subscription_details().
	}
}
