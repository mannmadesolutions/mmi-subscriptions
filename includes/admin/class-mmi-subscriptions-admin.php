<?php
/**
 * MMI Subscriptions Admin
 *
 * Registers the Subscriptions submenu under WooCommerce, provides the list table
 * (reusing WC_List_Table), column definitions, bulk actions, row actions, and the
 * single-subscription edit screen.
 *
 * Mirrors WCS_Admin_Post_Types / WC_Subscriptions_Admin from WooCommerce Subscriptions.
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MMI_Subscriptions_Admin {

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'admin_menu',           [ self::class, 'register_menu' ] );
		add_action( 'admin_init',           [ self::class, 'handle_bulk_action' ] );
		add_filter( 'set_screen_option_mmi_subscriptions_per_page', [ self::class, 'set_screen_option' ], 10, 3 );
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public static function register_menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Subscriptions', 'mmi-subscriptions' ),
			__( 'Subscriptions', 'mmi-subscriptions' ),
			mmi_subscriptions_required_capability(),
			'mmi-subscriptions',
			[ self::class, 'render_list_page' ]
		);

		add_action( "load-{$hook}", [ self::class, 'add_screen_options' ] );
		add_action( "load-{$hook}", [ self::class, 'handle_edit_actions' ] );
		add_action( "load-{$hook}", [ self::class, 'enqueue_assets' ] );
	}

	public static function enqueue_assets(): void {
		add_action( 'admin_enqueue_scripts', static function (): void {
			wp_enqueue_style(
				'mmi-subscriptions-admin',
				MMI_SUBSCRIPTIONS_PLUGIN_URL . 'assets/css/admin-subscriptions.css',
				[ 'mmi-suite-common' ],
				MMI_SUBSCRIPTIONS_VERSION
			);
			wp_enqueue_script(
				'mmi-subscriptions-admin',
				MMI_SUBSCRIPTIONS_PLUGIN_URL . 'assets/js/admin-subscriptions.js',
				[],
				MMI_SUBSCRIPTIONS_VERSION,
				true
			);
		} );
	}

	public static function add_screen_options(): void {
		add_screen_option( 'per_page', [
			'label'   => __( 'Subscriptions per page', 'mmi-subscriptions' ),
			'default' => 20,
			'option'  => 'mmi_subscriptions_per_page',
		] );
	}

	/** Sortable list columns: request key => what the column shows. */
	const SORT_COLUMNS = [
		'id'             => 'ID',
		'customer'       => 'Customer',
		'status'         => 'Status',
		'next_payment'   => 'Next Payment',
		'total'          => 'Recurring Total',
		'payment_method' => 'Payment Method',
		'start'          => 'Start Date',
	];

	/** The value a subscription's list column displays, in sortable form. */
	private static function sort_value( MMI_Subscription $sub, string $key ) {
		switch ( $key ) {
			case 'id':             return $sub->get_id();
			case 'customer':       return self::get_customer_display( $sub );
			case 'status':         return $sub->get_status();
			case 'next_payment':   return (string) ( $sub->get_date( 'next_payment' ) ?: '' );
			case 'total':          return (float) $sub->get_meta( '_mmi_sub_recurring_total', true );
			case 'payment_method': return $sub->get_payment_method_title();
			case 'start':          return (string) ( $sub->get_date( 'start' ) ?: '' );
		}
		return '';
	}

	public static function set_screen_option( mixed $status, string $option, mixed $value ): mixed {
		if ( 'mmi_subscriptions_per_page' === $option ) {
			return (int) $value;
		}
		return $status;
	}

	// ── List screen ───────────────────────────────────────────────────────────

	public static function render_list_page(): void {
		if ( ! mmi_subscriptions_user_can() ) {
			wp_die( esc_html__( 'You do not have permission to view subscriptions.', 'mmi-subscriptions' ), 403 );
		}

		// Single-subscription edit view.
		if ( isset( $_GET['subscription_id'] ) && ! isset( $_GET['bulk_action'] ) ) { // phpcs:ignore
			self::render_edit_page( absint( $_GET['subscription_id'] ) ); // phpcs:ignore
			return;
		}

		$per_page       = (int) get_user_meta( get_current_user_id(), 'mmi_subscriptions_per_page', true ) ?: 20;
		$current_page   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
		$status_filter  = sanitize_key( $_GET['status'] ?? 'any' ); // phpcs:ignore
		$search         = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore

		$args  = [
			'status' => 'any' === $status_filter ? array_keys( mmisub_get_subscription_statuses() ) : [ "wc-{$status_filter}" ],
			'limit'  => $per_page,
			'offset' => ( $current_page - 1 ) * $per_page,
			'type'   => 'shop_mmi_sub',
		];

		if ( $search ) {
			$args['customer'] = $search;
		}

		$all_ids = wc_get_orders( array_merge( $args, [ 'limit' => -1, 'offset' => 0, 'return' => 'ids' ] ) );
		$all_ids = is_array( $all_ids ) ? array_map( 'intval', $all_ids ) : [];
		$total   = count( $all_ids );

		// Column sort (?orderby=&order=, shared mmi-table-sort.js "server" mode).
		// Several columns (status, next payment, payment method) aren't
		// sortable through wc_get_orders() for this order type, so a sorted
		// view orders every matching ID by the value its column shows, then
		// takes the page — the ID list is already fetched above for the count.
		$sort_key = sanitize_key( $_GET['orderby'] ?? '' ); // phpcs:ignore
		if ( isset( self::SORT_COLUMNS[ $sort_key ] ) ) {
			$desc   = 'desc' === sanitize_key( $_GET['order'] ?? '' ); // phpcs:ignore
			$values = [];
			foreach ( $all_ids as $sub_id ) {
				$sub = mmisub_get_subscription( $sub_id );
				$values[ $sub_id ] = $sub instanceof MMI_Subscription ? self::sort_value( $sub, $sort_key ) : '';
			}
			uksort( $values, static function ( $a, $b ) use ( $values, $desc ) {
				$va = $values[ $a ];
				$vb = $values[ $b ];
				if ( ( '' === $va ) !== ( '' === $vb ) ) {
					return '' === $va ? 1 : -1; // empty last, both directions
				}
				$cmp = is_numeric( $va ) && is_numeric( $vb ) ? $va <=> $vb : strnatcasecmp( (string) $va, (string) $vb );
				return ( $desc ? -$cmp : $cmp ) ?: $a <=> $b;
			} );
			$orders = array_slice( array_keys( $values ), ( $current_page - 1 ) * $per_page, $per_page );
		} else {
			$orders = wc_get_orders( $args );
		}

		$subscriptions = array_filter(
			array_map( fn( $o ) => mmisub_get_subscription( $o instanceof WC_Order ? $o->get_id() : (int) $o ), $orders ),
			fn( $s ) => $s instanceof MMI_Subscription
		);

		self::render_list_table( $subscriptions, $total, $per_page, $current_page, $status_filter );
	}

	/**
	 * @param  MMI_Subscription[] $subscriptions
	 */
	private static function render_list_table(
		array $subscriptions,
		int $total,
		int $per_page,
		int $current_page,
		string $status_filter
	): void {
		$total_pages = (int) ceil( $total / max( 1, $per_page ) );
		$statuses    = mmisub_get_subscription_statuses();
		?>
		<div class="wrap mmi-page">
			<div class="mmi-header">
				<h1><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Subscriptions', 'mmi-subscriptions' ); ?></h1>
				<p class="mmi-header-description"><?php esc_html_e( 'View and manage customer subscription orders.', 'mmi-subscriptions' ); ?></p>
			</div>
			<div class="wp-header-end"></div>

			<?php self::render_status_filters( $statuses, $status_filter ); ?>

			<form method="get">
				<input type="hidden" name="page" value="mmi-subscriptions">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">

				<table class="wp-list-table widefat fixed striped mmi-uniform-table mmi-uniform-table--hoverable" data-mmi-sort="server">
					<thead>
						<tr>
							<th class="check-column"><input type="checkbox" id="cb-select-all"></th>
							<th data-sort-key="id"><?php esc_html_e( 'ID', 'mmi-subscriptions' ); ?></th>
							<th data-sort-key="customer"><?php esc_html_e( 'Customer', 'mmi-subscriptions' ); ?></th>
							<th data-sort-key="status"><?php esc_html_e( 'Status', 'mmi-subscriptions' ); ?></th>
							<th data-sort-key="next_payment"><?php esc_html_e( 'Next Payment', 'mmi-subscriptions' ); ?></th>
							<th data-sort-key="total"><?php esc_html_e( 'Recurring Total', 'mmi-subscriptions' ); ?></th>
							<th data-sort-key="payment_method"><?php esc_html_e( 'Payment Method', 'mmi-subscriptions' ); ?></th>
							<th data-sort-key="start"><?php esc_html_e( 'Start Date', 'mmi-subscriptions' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $subscriptions ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No subscriptions found.', 'mmi-subscriptions' ); ?></td></tr>
						<?php else : ?>
						<?php foreach ( $subscriptions as $subscription ) : ?>
						<?php
							$sub_id      = $subscription->get_id();
							$edit_url    = admin_url( "admin.php?page=mmi-subscriptions&subscription_id={$sub_id}" );
						?>
						<tr>
							<td class="check-column"><input type="checkbox" name="subscription_ids[]" value="<?php echo esc_attr( $sub_id ); ?>"></td>
							<td><a href="<?php echo esc_url( $edit_url ); ?>">#<?php echo esc_html( $sub_id ); ?></a></td>
							<td><?php echo esc_html( self::get_customer_display( $subscription ) ); ?></td>
							<td><?php echo wp_kses_post( MMI_Subscription_Meta_Boxes::status_badge( $subscription->get_status() ) ); ?></td>
							<td><?php echo esc_html( self::format_date( $subscription->get_date( 'next_payment' ) ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $subscription->get_meta( '_mmi_sub_recurring_total', true ) ) ); ?></td>
							<td><?php echo esc_html( $subscription->get_payment_method_title() ); ?></td>
							<td><?php echo esc_html( self::format_date( $subscription->get_date( 'start' ) ) ); ?></td>
						</tr>
						<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="8">
								<?php self::render_pagination( $current_page, $total_pages ); ?>
							</td>
						</tr>
					</tfoot>
				</table>

				<div class="tablenav bottom">
					<div class="alignleft actions bulkactions">
						<select name="bulk_action">
							<option value=""><?php esc_html_e( '— Bulk Actions —', 'mmi-subscriptions' ); ?></option>
							<option value="cancel"><?php esc_html_e( 'Cancel', 'mmi-subscriptions' ); ?></option>
							<option value="on-hold"><?php esc_html_e( 'Suspend', 'mmi-subscriptions' ); ?></option>
							<option value="active"><?php esc_html_e( 'Reactivate', 'mmi-subscriptions' ); ?></option>
						</select>
						<?php wp_nonce_field( 'mmi_sub_bulk_action', '_wpnonce_bulk' ); ?>
						<input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'mmi-subscriptions' ); ?>">
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	private static function render_status_filters( array $statuses, string $current ): void {
		echo '<ul class="subsubsub">';
		$base = admin_url( 'admin.php?page=mmi-subscriptions' );
		$all_url = add_query_arg( 'status', 'any', $base );
		$class = 'any' === $current ? 'current' : '';
		echo "<li><a href='" . esc_url( $all_url ) . "' class='" . esc_attr( $class ) . "'>" . esc_html__( 'All', 'mmi-subscriptions' ) . '</a>';

		foreach ( $statuses as $key => $label ) {
			$slug  = ltrim( $key, 'wc-' );
			$url   = add_query_arg( 'status', $slug, $base );
			$class = $slug === $current ? 'current' : '';
			echo " | <li><a href='" . esc_url( $url ) . "' class='" . esc_attr( $class ) . "'>" . esc_html( $label ) . '</a></li>';
		}
		echo '</ul>';
	}

	private static function render_pagination( int $current, int $total ): void {
		if ( $total <= 1 ) {
			return;
		}
		$base = remove_query_arg( 'paged' );
		echo '<div class="tablenav-pages">';
		for ( $i = 1; $i <= $total; $i++ ) {
			$url   = add_query_arg( 'paged', $i, $base );
			$class = $i === $current ? 'button button-primary' : 'button';
			echo "<a href='" . esc_url( $url ) . "' class='" . esc_attr( $class ) . "'>" . (int) $i . '</a> ';
		}
		echo '</div>';
	}

	// ── Edit screen ───────────────────────────────────────────────────────────

	/**
	 * Handles single-subscription form posts on page load so every action can
	 * redirect (POST → redirect → GET) — a refresh never re-submits a status
	 * change or, worse, a renewal charge.
	 *
	 * Hooked to load-{page hook}.
	 */
	public static function handle_edit_actions(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['mmisub_action'] ) || empty( $_GET['subscription_id'] ) ) { // phpcs:ignore
			return;
		}

		$subscription_id = absint( $_GET['subscription_id'] ); // phpcs:ignore
		check_admin_referer( "mmisub_edit_{$subscription_id}", '_mmisub_nonce' );
		if ( ! mmi_subscriptions_user_can() ) {
			mmisub_audit( 'subscription.admin_edit', [
				'object_type' => 'subscription',
				'object_id'   => $subscription_id,
				'outcome'     => 'denied',
			] );
			wp_die( esc_html__( 'You do not have permission to edit subscriptions.', 'mmi-subscriptions' ), 403 );
		}

		$subscription = mmisub_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			wp_die( esc_html__( 'Subscription not found.', 'mmi-subscriptions' ) );
		}

		$action     = sanitize_key( wp_unslash( $_POST['mmisub_action'] ) ); // phpcs:ignore
		$old_status = $subscription->get_status();
		$result     = match ( $action ) {
			'status'          => self::action_update_status( $subscription ),
			'schedule'        => self::action_update_schedule( $subscription ),
			'note'            => self::action_add_note( $subscription ),
			'process_renewal' => self::action_process_renewal( $subscription ),
			default           => new WP_Error( 'mmisub_unknown_action', __( 'Unknown action.', 'mmi-subscriptions' ) ),
		};

		$audit_actions = [
			'status'          => 'subscription.status_change',
			'schedule'        => 'subscription.schedule_update',
			'note'            => 'subscription.note_add',
			'process_renewal' => 'subscription.manual_renewal',
		];
		if ( isset( $audit_actions[ $action ] ) ) {
			$fresh   = mmisub_get_subscription( $subscription_id );
			$details = [ 'actor' => 'admin', 'old_status' => $old_status, 'new_status' => $fresh ? $fresh->get_status() : '' ];
			if ( is_wp_error( $result ) ) {
				$details['error'] = $result->get_error_code();
			}
			if ( 'note' === $action ) {
				$details['customer_note'] = ! empty( $_POST['mmisub_note_customer'] ); // phpcs:ignore
			}
			mmisub_audit( $audit_actions[ $action ], [
				'object_type' => 'subscription',
				'object_id'   => $subscription_id,
				'outcome'     => is_wp_error( $result ) ? 'failure' : 'success',
				'details'     => $details,
			] );
		}

		$args = is_wp_error( $result )
			? [ 'mmisub_error' => rawurlencode( $result->get_error_message() ) ]
			: [ 'mmisub_updated' => rawurlencode( $result ) ];

		wp_safe_redirect( add_query_arg( $args, self::edit_url( $subscription_id ) ) );
		exit;
	}

	/**
	 * @return string|WP_Error  Success message or error.
	 */
	private static function action_update_status( MMI_Subscription $subscription ) {
		$new_status = sanitize_key( wp_unslash( $_POST['mmisub_status'] ?? '' ) ); // phpcs:ignore
		$note       = sanitize_textarea_field( wp_unslash( $_POST['mmisub_note'] ?? '' ) ); // phpcs:ignore

		if ( ! array_key_exists( $new_status, mmisub_get_subscription_statuses() ) ) {
			return new WP_Error( 'mmisub_bad_status', __( 'Invalid status.', 'mmi-subscriptions' ) );
		}
		if ( $subscription->has_status( $new_status ) ) {
			if ( $note ) {
				$subscription->add_order_note( $note, 0, true );
				return __( 'Note added. Status unchanged.', 'mmi-subscriptions' );
			}
			return __( 'Status unchanged.', 'mmi-subscriptions' );
		}

		// Route through the manager so Action Scheduler events follow the
		// status (a bare update_status() left renewals queued on cancelled subs).
		match ( $new_status ) {
			'mmisub-active'         => $subscription->has_status( 'mmisub-on-hold' )
				? MMI_Subscriptions_Manager::reactivate_subscription( $subscription )
				: MMI_Subscriptions_Manager::activate_subscription( $subscription ),
			'mmisub-on-hold'        => MMI_Subscriptions_Manager::put_subscription_on_hold( $subscription ),
			'mmisub-pending-cancel' => MMI_Subscriptions_Manager::cancel_subscription_at_period_end( $subscription ),
			'mmisub-cancelled'      => MMI_Subscriptions_Manager::cancel_subscription( $subscription ),
			default                 => $subscription->update_status( $new_status, '', true ),
		};

		$subscription = mmisub_get_subscription( $subscription->get_id() );
		if ( ! $subscription || ! $subscription->has_status( $new_status ) ) {
			return new WP_Error( 'mmisub_status_refused', __( 'That status change is not allowed from the current status.', 'mmi-subscriptions' ) );
		}
		if ( $note ) {
			$subscription->add_order_note( $note, 0, true );
		}
		return __( 'Subscription status updated.', 'mmi-subscriptions' );
	}

	/**
	 * Saves admin-edited schedule dates. Only fields whose minute-precision value
	 * actually changed are written, so an untouched field keeps its seconds.
	 *
	 * A past next_payment is allowed on purpose: the scheduler never queues a
	 * past timestamp, so it simply marks the period as overdue for an explicit
	 * Process Renewal Now — it is never charged automatically.
	 *
	 * @return string|WP_Error
	 */
	private static function action_update_schedule( MMI_Subscription $subscription ) {
		if ( $subscription->has_status( mmisub_get_ended_statuses() ) ) {
			return new WP_Error( 'mmisub_ended', __( 'Ended subscriptions cannot be rescheduled.', 'mmi-subscriptions' ) );
		}

		$posted  = (array) wp_unslash( $_POST['mmisub_dates'] ?? [] ); // phpcs:ignore
		$labels  = MMI_Subscription_Meta_Boxes::date_labels();
		$current = [];
		$new     = [];

		foreach ( MMI_Subscription_Meta_Boxes::EDITABLE_DATES as $key ) {
			$current[ $key ] = $subscription->get_date( $key );
			if ( ! array_key_exists( $key, $posted ) ) {
				$new[ $key ] = $current[ $key ];
				continue;
			}
			$raw = sanitize_text_field( (string) $posted[ $key ] );
			if ( '' === $raw ) {
				$new[ $key ] = 0;
				continue;
			}
			$dt = date_create_immutable_from_format( MMI_Subscription_Meta_Boxes::INPUT_DATE_FORMAT, $raw, wp_timezone() );
			if ( ! $dt ) {
				/* translators: %s: date label */
				return new WP_Error( 'mmisub_bad_date', sprintf( __( '%s is not a valid date.', 'mmi-subscriptions' ), $labels[ $key ] ) );
			}
			// Unchanged at minute precision → keep the stored value (and its seconds).
			$same    = $current[ $key ] && wp_date( MMI_Subscription_Meta_Boxes::INPUT_DATE_FORMAT, $current[ $key ] ) === $raw;
			$new[ $key ] = $same ? $current[ $key ] : $dt->getTimestamp();
		}

		if ( ! $new['next_payment'] && $subscription->has_status( [ 'mmisub-active', 'mmisub-on-hold' ] ) ) {
			return new WP_Error( 'mmisub_need_next', __( 'An active subscription needs a Next Payment date.', 'mmi-subscriptions' ) );
		}
		if ( $new['end'] && $new['end'] !== $current['end'] && $new['end'] <= time() ) {
			return new WP_Error( 'mmisub_end_past', __( 'End Date must be in the future.', 'mmi-subscriptions' ) );
		}
		if ( $new['end'] && $new['next_payment'] && $new['end'] <= $new['next_payment'] ) {
			return new WP_Error( 'mmisub_end_order', __( 'End Date must be after Next Payment.', 'mmi-subscriptions' ) );
		}
		if ( $new['trial_end'] && $new['next_payment'] && $new['trial_end'] > $new['next_payment'] ) {
			return new WP_Error( 'mmisub_trial_order', __( 'Trial End must be on or before Next Payment.', 'mmi-subscriptions' ) );
		}

		$changed = array_filter( $new, static fn( $ts, $key ) => $ts !== $current[ $key ], ARRAY_FILTER_USE_BOTH );

		// Re-queue even when unchanged if the renewal action has gone missing.
		$queued = ! function_exists( 'as_next_scheduled_action' ) || as_next_scheduled_action(
			MMI_Subscription_Scheduler::HOOK_PAYMENT,
			[ 'subscription_id' => $subscription->get_id() ],
			MMI_Subscription_Scheduler::ACTION_GROUP
		);
		if ( ! $changed && ( $queued || ! $subscription->has_status( 'mmisub-active' ) ) ) {
			return __( 'Schedule unchanged.', 'mmi-subscriptions' );
		}
		if ( ! $changed ) {
			$changed = [ 'next_payment' => $new['next_payment'] ];
		}

		$subscription->update_dates( $changed );

		// update_dates() queues any future date — but an on-hold subscription must
		// not renew until it is reactivated (matches put_subscription_on_hold()).
		if ( ! $subscription->has_status( 'mmisub-active' ) && isset( $changed['next_payment'] ) ) {
			MMI_Subscription_Scheduler::instance()->update_date( $subscription, 'next_payment', 0 );
		}

		$lines = [];
		foreach ( $changed as $key => $ts ) {
			if ( $ts === $current[ $key ] ) {
				continue;
			}
			$lines[] = sprintf(
				/* translators: 1: date label, 2: old date, 3: new date */
				__( '%1$s changed from %2$s to %3$s.', 'mmi-subscriptions' ),
				$labels[ $key ],
				MMI_Subscription_Meta_Boxes::format_datetime( $current[ $key ] ),
				MMI_Subscription_Meta_Boxes::format_datetime( $ts )
			);
		}
		if ( $lines ) {
			$subscription->add_order_note( implode( ' ', $lines ), 0, true );
		}

		return $lines ? implode( ' ', $lines ) : __( 'Renewal re-queued.', 'mmi-subscriptions' );
	}

	/**
	 * @return string|WP_Error
	 */
	private static function action_add_note( MMI_Subscription $subscription ) {
		$note = wp_kses_post( trim( wp_unslash( $_POST['mmisub_note'] ?? '' ) ) ); // phpcs:ignore
		if ( '' === $note ) {
			return new WP_Error( 'mmisub_empty_note', __( 'Note is empty.', 'mmi-subscriptions' ) );
		}
		$is_customer = ! empty( $_POST['mmisub_note_customer'] ); // phpcs:ignore
		$subscription->add_order_note( $note, $is_customer ? 1 : 0, true );
		return $is_customer
			? __( 'Note added and sent to the customer.', 'mmi-subscriptions' )
			: __( 'Note added.', 'mmi-subscriptions' );
	}

	/**
	 * Runs the same renewal pipeline the scheduler fires (renewal order + gateway
	 * charge) on demand. A short per-subscription lock stops a double-submit
	 * from charging twice.
	 *
	 * @return string|WP_Error
	 */
	private static function action_process_renewal( MMI_Subscription $subscription ) {
		if ( ! $subscription->has_status( [ 'mmisub-active', 'mmisub-on-hold' ] ) ) {
			return new WP_Error( 'mmisub_not_renewable', __( 'Only active or on-hold subscriptions can be renewed.', 'mmi-subscriptions' ) );
		}

		$lock = 'mmisub_renewing_' . $subscription->get_id();
		if ( get_transient( $lock ) ) {
			return new WP_Error( 'mmisub_renewal_locked', __( 'A renewal for this subscription was just processed. Wait a minute before trying again.', 'mmi-subscriptions' ) );
		}
		set_transient( $lock, 1, MINUTE_IN_SECONDS );

		$before = wp_list_pluck( $subscription->get_renewal_orders(), 'id' );
		$subscription->add_order_note( __( 'Renewal processed manually by admin.', 'mmi-subscriptions' ), 0, true );

		MMI_Logger::info(
			sprintf( 'Admin-triggered renewal for subscription #%d.', $subscription->get_id() ),
			[ 'subscription_id' => $subscription->get_id(), 'user_id' => get_current_user_id() ],
			'general',
			'MMI_Subscriptions_Admin'
		);

		do_action( MMI_Subscription_Scheduler::HOOK_PAYMENT, $subscription->get_id() );

		$subscription = mmisub_get_subscription( $subscription->get_id() );
		$new_orders   = array_filter(
			$subscription ? $subscription->get_renewal_orders() : [],
			static fn( $o ) => ! in_array( $o->get_id(), $before, true )
		);
		$order = $new_orders ? reset( $new_orders ) : null;
		if ( ! $order ) {
			return new WP_Error( 'mmisub_renewal_failed', __( 'No renewal order was created — check the subscription notes and MMI logs.', 'mmi-subscriptions' ) );
		}
		if ( $order->is_paid() ) {
			/* translators: 1: order number, 2: next payment date */
			return sprintf( __( 'Renewal order #%1$s paid. Next payment: %2$s.', 'mmi-subscriptions' ), $order->get_order_number(), MMI_Subscription_Meta_Boxes::format_datetime( $subscription->get_date( 'next_payment' ) ) );
		}
		return new WP_Error(
			'mmisub_renewal_unpaid',
			/* translators: 1: order number, 2: order status */
			sprintf( __( 'Renewal order #%1$s was created but is %2$s — see the order notes.', 'mmi-subscriptions' ), $order->get_order_number(), wc_get_order_status_name( $order->get_status() ) )
		);
	}

	private static function edit_url( int $subscription_id ): string {
		return admin_url( 'admin.php?page=mmi-subscriptions&subscription_id=' . $subscription_id );
	}

	private static function render_edit_page( int $subscription_id ): void {
		$subscription = mmisub_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			wp_die( esc_html__( 'Subscription not found.', 'mmi-subscriptions' ) );
		}

		$updated = isset( $_GET['mmisub_updated'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['mmisub_updated'] ) ) ) : ''; // phpcs:ignore
		$error   = isset( $_GET['mmisub_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['mmisub_error'] ) ) ) : ''; // phpcs:ignore
		$user    = get_userdata( $subscription->get_customer_id() );
		?>
		<div class="wrap mmi-page mmisub-edit">
			<div class="mmi-header">
				<h1>
					<span class="dashicons dashicons-update"></span>
					<?php
					printf(
						/* translators: %d subscription ID */
						esc_html__( 'Subscription #%d', 'mmi-subscriptions' ),
						(int) $subscription_id
					);
					?>
				</h1>
				<p class="mmi-header-description">
					<?php
					echo esc_html(
						$user
							/* translators: 1: customer name, 2: next payment date */
							? sprintf( __( '%1$s · next payment %2$s', 'mmi-subscriptions' ), $user->display_name, MMI_Subscription_Meta_Boxes::format_datetime( $subscription->get_date( 'next_payment' ), get_option( 'date_format' ) ) )
							: __( 'View and update this subscription.', 'mmi-subscriptions' )
					);
					?>
				</p>
			</div>
			<div class="wp-header-end"></div>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $updated ); ?></p></div>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=mmi-subscriptions' ) ); ?>">&larr; <?php esc_html_e( 'Back to Subscriptions', 'mmi-subscriptions' ); ?></a></p>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="postbox-container-1" class="postbox-container">
						<?php MMI_Subscription_Meta_Boxes::render_sidebar( $subscription ); ?>
					</div>
					<div id="postbox-container-2" class="postbox-container">
						<?php MMI_Subscription_Meta_Boxes::render_main( $subscription ); ?>
					</div>
				</div>
				<br class="clear">
			</div>
		</div>
		<?php
	}

	// ── Bulk action handler ───────────────────────────────────────────────────

	public static function handle_bulk_action(): void {
		if ( ! isset( $_POST['bulk_action'], $_POST['subscription_ids'], $_POST['_wpnonce_bulk'] ) ) { // phpcs:ignore
			return;
		}
		// Nonce first (it is user-bound), so only a real form post by a logged-in
		// user can reach the audited denial — no audit-table flooding.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_bulk'] ) ), 'mmi_sub_bulk_action' ) ) { // phpcs:ignore
			return;
		}
		if ( ! mmi_subscriptions_user_can() ) {
			mmisub_audit( 'subscription.bulk_action', [ 'outcome' => 'denied' ] );
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['bulk_action'] ) ); // phpcs:ignore
		$ids    = array_map( 'absint', (array) $_POST['subscription_ids'] ); // phpcs:ignore
		if ( ! in_array( $action, [ 'cancel', 'on-hold', 'active' ], true ) ) {
			return;
		}

		$done = [];
		foreach ( $ids as $id ) {
			$subscription = mmisub_get_subscription( $id );
			if ( ! $subscription ) {
				continue;
			}
			match ( $action ) {
				'cancel'   => MMI_Subscriptions_Manager::cancel_subscription( $subscription ),
				'on-hold'  => MMI_Subscriptions_Manager::put_subscription_on_hold( $subscription ),
				'active'   => MMI_Subscriptions_Manager::reactivate_subscription( $subscription ),
			};
			$done[] = $id;
		}

		// One audit row per bulk run, listing the subscriptions it touched.
		mmisub_audit( 'subscription.bulk_action', [
			'object_type' => 'subscription',
			'outcome'     => 'success',
			'details'     => [ 'actor' => 'admin', 'bulk_action' => $action, 'subscription_ids' => $done ],
		] );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function get_customer_display( MMI_Subscription $subscription ): string {
		$user_id = $subscription->get_customer_id();
		if ( ! $user_id ) {
			return $subscription->get_billing_email();
		}
		$user = get_userdata( $user_id );
		return $user ? $user->display_name . ' (' . $user->user_email . ')' : '#' . $user_id;
	}

	private static function format_date( int $timestamp ): string {
		if ( ! $timestamp ) {
			return __( 'N/A', 'mmi-subscriptions' );
		}
		return (string) wp_date( wc_date_format(), $timestamp );
	}
}
