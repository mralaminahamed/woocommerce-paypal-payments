<?php
/**
 * The order tracking module services.
 *
 * @package WooCommerce\PayPalCommerce\OrderTracking
 */

declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\OrderTracking;

use WooCommerce\PayPalCommerce\ApiClient\Authentication\Bearer;
use WooCommerce\PayPalCommerce\ApiClient\Exception\RuntimeException;
use WooCommerce\PayPalCommerce\OrderTracking\Shipment\ShipmentFactoryInterface;
use WooCommerce\PayPalCommerce\OrderTracking\Shipment\ShipmentFactory;
use WooCommerce\PayPalCommerce\Vendor\Psr\Container\ContainerInterface;
use WooCommerce\PayPalCommerce\OrderTracking\Assets\OrderEditPageAssets;
use WooCommerce\PayPalCommerce\OrderTracking\Endpoint\OrderTrackingEndpoint;
use WooCommerce\PayPalCommerce\WcGateway\Gateway\PayPalGateway;

return array(
	'order-tracking.assets'                    => function( ContainerInterface $container ) : OrderEditPageAssets {
		return new OrderEditPageAssets(
			$container->get( 'order-tracking.module.url' ),
			$container->get( 'ppcp.asset-version' )
		);
	},
	'order-tracking.shipment.factory'          => static function ( ContainerInterface $container ) : ShipmentFactoryInterface {
		return new ShipmentFactory();
	},
	'order-tracking.endpoint.controller'       => static function ( ContainerInterface $container ) : OrderTrackingEndpoint {
		return new OrderTrackingEndpoint(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'woocommerce.logger.woocommerce' ),
			$container->get( 'button.request-data' ),
			$container->get( 'order-tracking.shipment.factory' ),
			$container->get( 'order-tracking.allowed-shipping-statuses' ),
			$container->get( 'order-tracking.is-merchant-country-us' )
		);
	},
	'order-tracking.module.url'                => static function ( ContainerInterface $container ): string {
		/**
		 * The path cannot be false.
		 *
		 * @psalm-suppress PossiblyFalseArgument
		 */
		return plugins_url(
			'/modules/ppcp-order-tracking/',
			dirname( realpath( __FILE__ ), 3 ) . '/woocommerce-paypal-payments.php'
		);
	},
	'order-tracking.meta-box.renderer'         => static function ( ContainerInterface $container ): MetaBoxRenderer {
		return new MetaBoxRenderer(
			$container->get( 'order-tracking.allowed-shipping-statuses' ),
			$container->get( 'order-tracking.available-carriers' ),
			$container->get( 'order-tracking.endpoint.controller' ),
			$container->get( 'order-tracking.is-merchant-country-us' )
		);
	},
	'order-tracking.allowed-shipping-statuses' => static function ( ContainerInterface $container ): array {
		return (array) apply_filters(
			'woocommerce_paypal_payments_tracking_statuses',
			array(
				'SHIPPED'   => 'Shipped',
				'ON_HOLD'   => 'On Hold',
				'DELIVERED' => 'Delivered',
				'CANCELLED' => 'Cancelled',
			)
		);
	},
	'order-tracking.allowed-carriers'          => static function ( ContainerInterface $container ): array {
		return require __DIR__ . '/carriers.php';
	},
	'order-tracking.available-carriers'        => static function ( ContainerInterface $container ): array {
		$api_shop_country = $container->get( 'api.shop.country' );
		$allowed_carriers = $container->get( 'order-tracking.allowed-carriers' );
		$selected_country_carriers = $allowed_carriers[ $api_shop_country ] ?? array();

		return array(
			$api_shop_country => $selected_country_carriers ?? array(),
			'global'          => $allowed_carriers['global'] ?? array(),
			'other'           => array(
				'name'  => 'Other',
				'items' => array(
					'OTHER' => _x( 'Other', 'Name of carrier', 'woocommerce-paypal-payments' ),
				),
			),
		);
	},
	'order-tracking.is-tracking-available'     => static function ( ContainerInterface $container ): bool {
		try {
			$bearer = $container->get( 'api.bearer' );
			assert( $bearer instanceof Bearer );

			$token = $bearer->bearer();
			return $token->is_tracking_available();
		} catch ( RuntimeException $exception ) {
			return false;
		}
	},
	'order-tracking.is-module-enabled'         => static function ( ContainerInterface $container ): bool {
		$order_id = wc_clean( wp_unslash( $_GET['id'] ?? $_GET['post'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( empty( $order_id ) ) {
			return false;
		}

		$meta = get_post_meta( (int) $order_id, PayPalGateway::ORDER_ID_META_KEY, true );

		if ( empty( $meta ) ) {
			return false;
		}

		$is_tracking_available = $container->get( 'order-tracking.is-tracking-available' );

		return $is_tracking_available && apply_filters( 'woocommerce_paypal_payments_shipment_tracking_enabled', true );
	},

	'order-tracking.is-merchant-country-us'    => static function ( ContainerInterface $container ): bool {
		return $container->get( 'api.shop.country' ) === 'US';
	},
);
