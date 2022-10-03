<?php
/**
 * The compatibility module services.
 *
 * @package WooCommerce\PayPalCommerce\Compat
 */

declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\Compat;

use Psr\Container\ContainerInterface;
use WooCommerce\PayPalCommerce\Compat\Assets\CompatAssets;
use WooCommerce\PayPalCommerce\WcGateway\Settings\Settings;

return array(

	'compat.ppec.mock-gateway'                      => static function( $container ) {
		$settings = $container->get( 'wcgateway.settings' );
		$title    = $settings->has( 'title' ) ? $settings->get( 'title' ) : __( 'PayPal', 'woocommerce-paypal-payments' );
		$title    = sprintf(
			/* Translators: placeholder is the gateway name. */
			__( '%s (Legacy)', 'woocommerce-paypal-payments' ),
			$title
		);

		return new PPEC\MockGateway( $title );
	},

	'compat.ppec.subscriptions-handler'             => static function ( ContainerInterface $container ) {
		$ppcp_renewal_handler = $container->get( 'subscription.renewal-handler' );
		$gateway              = $container->get( 'compat.ppec.mock-gateway' );

		return new PPEC\SubscriptionsHandler( $ppcp_renewal_handler, $gateway );
	},

	'compat.ppec.settings_importer'                 => static function( ContainerInterface $container ) : PPEC\SettingsImporter {
		$settings = $container->get( 'wcgateway.settings' );

		return new PPEC\SettingsImporter( $settings );
	},

	'compat.plugin-script-names'                    => static function( ContainerInterface $container ) : array {
		return array(
			'ppcp-smart-button',
			'ppcp-oxxo',
			'ppcp-pay-upon-invoice',
			'ppcp-vaulting-myaccount-payments',
			'ppcp-gateway-settings',
			'ppcp-webhooks-status-page',
			'ppcp-tracking',
		);
	},

	'compat.gzd.is_supported_plugin_version_active' => function (): bool {
		return function_exists( 'wc_gzd_get_shipments_by_order' ); // 3.0+
	},

	'compat.gzd.tracking_statuses_map'              => function ( ContainerInterface $container ): array {
		$gzd_statuses = wc_gzd_get_shipment_statuses();
		$status_map = array();

		foreach ( $gzd_statuses as $gzd_status ) {
			$gzd_status = strtolower( $gzd_status );
			switch ( $gzd_status ) {
				case 'draft':
					$status_map[ $gzd_status ] = 'ON_HOLD';
					break;
				case 'processing':
				case 'shipped':
					$status_map[ $gzd_status ] = 'SHIPPED';
					break;
				case 'delivered':
					$status_map[ $gzd_status ] = 'DELIVERED';
					break;
			}
		}

		return $status_map;
	},

	'compat.module.url'                             => static function ( ContainerInterface $container ): string {
		/**
		 * The path cannot be false.
		 *
		 * @psalm-suppress PossiblyFalseArgument
		 */
		return plugins_url(
			'/modules/ppcp-compat/',
			dirname( realpath( __FILE__ ), 3 ) . '/woocommerce-paypal-payments.php'
		);
	},

	'compat.assets'                                 => function( ContainerInterface $container ) : CompatAssets {
		return new CompatAssets(
			$container->get( 'compat.module.url' ),
			$container->get( 'ppcp.asset-version' ),
			$container->get( 'compat.should-initialize-gzd-compat-layer' )
		);
	},

	'compat.should-initialize-gzd-compat-layer'     => function( ContainerInterface $container ) : bool {
		$settings = $container->get( 'wcgateway.settings' );
		assert( $settings instanceof Settings );

		$tracking_enabled     = $settings->has( 'tracking_enabled' ) && $settings->get( 'tracking_enabled' );
		$is_gzd_active        = $container->get( 'compat.gzd.is_supported_plugin_version_active' );
		$should_sync_shipment = apply_filters( 'woocommerce_paypal_payments_sync_gzd_tracking', true );
		$is_paypal_order_edit_page = $container->get( 'order-tracking.is-paypal-order-edit-page' );

		return $tracking_enabled && $is_gzd_active && $should_sync_shipment && $is_paypal_order_edit_page;
	},

);
