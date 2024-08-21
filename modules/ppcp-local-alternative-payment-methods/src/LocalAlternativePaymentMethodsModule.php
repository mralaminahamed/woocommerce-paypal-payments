<?php
/**
 * The local alternative payment methods module.
 *
 * @package WooCommerce\PayPalCommerce\LocalAlternativePaymentMethods
 */

declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\LocalAlternativePaymentMethods;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use WC_Order;
use WooCommerce\PayPalCommerce\Vendor\Dhii\Container\ServiceProvider;
use WooCommerce\PayPalCommerce\Vendor\Dhii\Modular\Module\ModuleInterface;
use WooCommerce\PayPalCommerce\Vendor\Interop\Container\ServiceProviderInterface;
use WooCommerce\PayPalCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * Class LocalAlternativePaymentMethodsModule
 */
class LocalAlternativePaymentMethodsModule implements ModuleInterface {

	/**
	 * {@inheritDoc}
	 */
	public function setup(): ServiceProviderInterface {
		return new ServiceProvider(
			require __DIR__ . '/../services.php',
			require __DIR__ . '/../extensions.php'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( ContainerInterface $c ): void {
		add_filter(
			'woocommerce_payment_gateways',
			/**
			 * Param types removed to avoid third-party issues.
			 *
			 * @psalm-suppress MissingClosureParamType
			 */
			function ( $methods ) use ( $c ) {
				if ( ! is_array( $methods ) ) {
					return $methods;
				}

				$payment_methods = $c->get('ppcp-local-apms.payment-methods');
				foreach ($payment_methods as $key => $value) {
					$methods[] = $c->get( 'ppcp-local-apms.' .  $key . '.wc-gateway' );
				}

				return $methods;
			}
		);

		add_filter(
			'woocommerce_available_payment_gateways',
			/**
			 * Param types removed to avoid third-party issues.
			 *
			 * @psalm-suppress MissingClosureParamType
			 */
			function ( $methods ) use ( $c ) {
				if ( ! is_array( $methods ) ) {
					return $methods;
				}

				if ( ! is_admin() ) {
					$customer_country = WC()->customer->get_billing_country() ?: WC()->customer->get_shipping_country();
					$site_currency    = get_woocommerce_currency();

					$payment_methods = $c->get('ppcp-local-apms.payment-methods');
					foreach ($payment_methods as $payment_method) {
						if ( $customer_country !== $payment_method['country'] || $site_currency !== $payment_method['currency'] ) {
							unset( $methods[ $payment_method['id'] ] );
						}
					}
				}

				return $methods;
			}
		);

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( PaymentMethodRegistry $payment_method_registry ) use ( $c ): void {
				$payment_methods = $c->get('ppcp-local-apms.payment-methods');
				foreach ($payment_methods as $key => $value) {
					$payment_method_registry->register( $c->get( 'ppcp-local-apms.' . $key . '.payment-method' ) );
				}
				$a = 1;
			}
		);

		add_filter(
			'woocommerce_paypal_payments_localized_script_data',
			function ( array $data ) use ($c) {
				$payment_methods = $c->get('ppcp-local-apms.payment-methods');

				$default_disable_funding               = $data['url_params']['disable-funding'] ?? '';
				$disable_funding                       = array_merge( array_keys( $payment_methods ), array_filter( explode( ',', $default_disable_funding ) ) );
				$data['url_params']['disable-funding'] = implode( ',', array_unique( $disable_funding ) );

				return $data;
			}
		);

		add_action(
			'woocommerce_before_thankyou',
			/**
			 * Activate is_checkout() on woocommerce/classic-shortcode checkout blocks.
			 *
			 * @psalm-suppress MissingClosureParamType
			 */
			function( $order_id ) use($c) {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof WC_Order ) {
					return;
				}

				// phpcs:disable WordPress.Security.NonceVerification.Recommended
				$cancelled = wc_clean( wp_unslash( $_GET['cancelled'] ?? '' ) );
				$order_key = wc_clean( wp_unslash( $_GET['key'] ?? '' ) );
				// phpcs:enable

				$payment_methods = $c->get('ppcp-local-apms.payment-methods');
				if (
					! $this->is_local_apm($order->get_payment_method(), $payment_methods)
					|| ! $cancelled
					|| $order->get_order_key() !== $order_key
				) {
					return;
				}

				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$error_code = wc_clean( wp_unslash( $_GET['errorcode'] ?? '' ) );
				if ( $error_code === 'processing_error' || $error_code === 'payment_error' ) {
					$order->update_status( 'failed', __( "The payment can't be processed because of an error.", 'woocommerce-paypal-payments' ) );

					add_filter( 'woocommerce_order_has_status', '__return_true' );
				}
			}
		);
	}

	/**
	 * Check if given payment method is a local APM.
	 *
	 * @param string $selected_payment_method Selected payment method.
	 * @param array $payment_methods Available local APMs.
	 * @return bool
	 */
	private function is_local_apm(string $selected_payment_method, array $payment_methods): bool {
		foreach ($payment_methods as $payment_method) {
			if($payment_method['id'] === $selected_payment_method) {
				return true;
			}
		}

		return false;
	}
}
