<?php
/**
 * MMI Subscription DB — Table management and CRUD helpers.
 *
 * Table: {prefix}mmi_subscriptions
 *
 * @package MannMade\Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Subscription_DB {

    const TABLE_VERSION = '1.1';
    const OPTION_KEY    = 'mmi_subscriptions_db_version';

    /* ── Table creation ─────────────────────────────────────────────────── */

    public static function create_table(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'mmi_subscriptions';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                     bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            stripe_subscription_id varchar(255)        NOT NULL DEFAULT '',
            stripe_customer_id     varchar(255)        NOT NULL DEFAULT '',
            license_key            varchar(255)        NOT NULL DEFAULT '',
            plugin_slug            varchar(100)        NOT NULL DEFAULT 'mmi-suite',
            billing_interval       varchar(20)         NOT NULL DEFAULT 'yearly',
            status                 varchar(20)         NOT NULL DEFAULT 'active',
            amount                 int(11)             NOT NULL DEFAULT 0,
            currency               varchar(10)         NOT NULL DEFAULT 'usd',
            email                  varchar(255)        NOT NULL DEFAULT '',
            wc_order_id            bigint(20) unsigned          DEFAULT NULL,
            wc_subscription_id     bigint(20) unsigned          DEFAULT NULL,
            payment_gateway        varchar(50)         NOT NULL DEFAULT '',
            next_renewal           datetime                     DEFAULT NULL,
            created_at             datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at             datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY stripe_sub_id (stripe_subscription_id),
            KEY license_key (license_key(191)),
            KEY email (email(191)),
            KEY status (status),
            KEY wc_subscription_id (wc_subscription_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::OPTION_KEY, self::TABLE_VERSION );
    }

    /* ── Schema migration ───────────────────────────────────────────────── */

    /**
     * Run incremental schema upgrades via dbDelta.
     *
     * Called on every `plugins_loaded` (fast — dbDelta only alters when needed)
     * and on the activation hook.  Adding columns to the CREATE TABLE statement
     * above is enough; dbDelta handles the ALTER TABLE automatically.
     */
    public static function maybe_upgrade(): void {
        if ( get_option( self::OPTION_KEY ) === self::TABLE_VERSION ) {
            return; // Already up-to-date.
        }

        self::create_table(); // Re-runs dbDelta with the updated schema.
    }

    /* ── CRUD ───────────────────────────────────────────────────────────── */

    /**
     * Create a new subscription record.
     *
     * @param  array $data  Keys: stripe_subscription_id, stripe_customer_id,
     *                            license_key, plugin_slug, billing_interval,
     *                            status, amount, currency, email,
     *                            wc_order_id (optional), next_renewal (optional).
     * @return int|false    Inserted row ID, or false on failure.
     */
    public static function create( array $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mmi_subscriptions';

        $defaults = [
            'plugin_slug'      => 'mmi-suite',
            'billing_interval' => 'yearly',
            'status'           => 'active',
            'amount'           => 0,
            'currency'         => 'usd',
            'email'            => '',
            'wc_order_id'      => null,
            'next_renewal'     => null,
            'created_at'       => current_time( 'mysql' ),
            'updated_at'       => current_time( 'mysql' ),
        ];

        $row = array_merge( $defaults, $data );

        $result = $wpdb->insert( $table, $row );
        return $result !== false ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update a subscription by Stripe subscription ID.
     *
     * @param  string $stripe_subscription_id
     * @param  array  $data  Columns to update.
     * @return bool
     */
    public static function update_by_stripe_id( string $stripe_subscription_id, array $data ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'mmi_subscriptions';
        $data['updated_at'] = current_time( 'mysql' );
        $result = $wpdb->update(
            $table,
            $data,
            [ 'stripe_subscription_id' => $stripe_subscription_id ]
        );
        return $result !== false;
    }

    /**
     * Find a subscription record by Stripe subscription ID.
     *
     * @param  string $stripe_subscription_id
     * @return object|null
     */
    public static function find_by_stripe_id( string $stripe_subscription_id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'mmi_subscriptions';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE stripe_subscription_id = %s LIMIT 1",
            $stripe_subscription_id
        ) ) ?: null;
    }

    /**
     * Find a subscription by license key.
     *
     * @param  string $license_key
     * @return object|null
     */
    public static function find_by_license_key( string $license_key ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'mmi_subscriptions';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE license_key = %s ORDER BY created_at DESC LIMIT 1",
            $license_key
        ) ) ?: null;
    }

    /**
     * Return a paginated list of subscriptions for the admin UI.
     *
     * @param  array $args  limit, offset, orderby, order, status.
     * @return object[]
     */
    public static function get_all( array $args = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'mmi_subscriptions';

        $limit   = isset( $args['limit'] )   ? (int) $args['limit']   : 50;
        $offset  = isset( $args['offset'] )  ? (int) $args['offset']  : 0;
        $allowed = [ 'id', 'created_at', 'next_renewal', 'status', 'email' ];
        $orderby = in_array( $args['orderby'] ?? 'created_at', $allowed, true ) ? $args['orderby'] : 'created_at';
        $order   = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

        $where = '';
        if ( ! empty( $args['status'] ) ) {
            $where = $wpdb->prepare( ' WHERE status = %s', sanitize_key( $args['status'] ) );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            "SELECT * FROM {$table}{$where} ORDER BY {$orderby} {$order} LIMIT {$limit} OFFSET {$offset}"
        ) ?: [];
    }
}
