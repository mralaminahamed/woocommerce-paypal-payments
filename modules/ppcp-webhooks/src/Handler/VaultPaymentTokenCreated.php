<?php
/**
 * Handles the Webhook VAULT.PAYMENT-TOKEN.CREATED
 *
 * @package WooCommerce\PayPalCommerce\Webhooks\Handler
 */

declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\Webhooks\Handler;

use Psr\Log\LoggerInterface;
use WC_Payment_Token_CC;
use WC_Payment_Tokens;
use WooCommerce\PayPalCommerce\Vaulting\PaymentTokenPayPal;
use WooCommerce\PayPalCommerce\WcGateway\Processor\AuthorizedPaymentsProcessor;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class VaultPaymentTokenCreated
 */
class VaultPaymentTokenCreated implements RequestHandler {

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * The prefix.
	 *
	 * @var string
	 */
	protected $prefix;

	/**
	 * The authorized payment processor.
	 *
	 * @var AuthorizedPaymentsProcessor
	 */
	protected $authorized_payments_processor;

	/**
	 * WooCommerce Payment token PayPal.
	 *
	 * @var PaymentTokenPayPal
	 */
	private $payment_token_paypal;

	/**
	 * VaultPaymentTokenCreated constructor.
	 *
	 * @param LoggerInterface             $logger The logger.
	 * @param string                      $prefix The prefix.
	 * @param AuthorizedPaymentsProcessor $authorized_payments_processor The authorized payment processor.
	 * @param PaymentTokenPayPal          $payment_token_paypal WooCommerce Payment token PayPal.
	 */
	public function __construct(
		LoggerInterface $logger,
		string $prefix,
		AuthorizedPaymentsProcessor $authorized_payments_processor,
		PaymentTokenPayPal $payment_token_paypal
	) {
		$this->logger                        = $logger;
		$this->prefix                        = $prefix;
		$this->authorized_payments_processor = $authorized_payments_processor;
		$this->payment_token_paypal          = $payment_token_paypal;
	}

	/**
	 * The event types a handler handles.
	 *
	 * @return string[]
	 */
	public function event_types(): array {
		return array(
			'VAULT.PAYMENT-TOKEN.CREATED',
		);
	}

	/**
	 * Whether a handler is responsible for a given request or not.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool
	 */
	public function responsible_for_request( WP_REST_Request $request ): bool {
		return in_array( $request['event_type'], $this->event_types(), true );
	}

	/**
	 * Responsible for handling the request.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		$this->logger->info( wc_print_r( $request['resource'], true ) );

		$response = array( 'success' => false );

		$customer_id = null !== $request['resource'] && isset( $request['resource']['customer_id'] )
			? $request['resource']['customer_id']
			: '';
		if ( ! $customer_id ) {
			$message = 'No customer id was found.';
			$this->logger->warning( $message, array( 'request' => $request ) );
			$response['message'] = $message;
			return new WP_REST_Response( $response );
		}

		$wc_customer_id = (int) str_replace( $this->prefix, '', $customer_id );
		$this->authorized_payments_processor->capture_authorized_payments_for_customer( $wc_customer_id );

		if ( isset( $request['resource']['id'] ) ) {
			if ( isset( $request['resource']['source']['card'] ) ) {
				$token = new WC_Payment_Token_CC();
				$token->set_token( $request['resource']['id'] );
				$token->set_user_id( $wc_customer_id );
				$token->set_last4( $request['resource']['source']['card']['last_digits'] ?? '' );
				$expiry = explode( '-', $request['resource']['source']['card']['expiry'] ?? '' );
				$token->set_expiry_year( $expiry[0] ?? '' );
				$token->set_expiry_month( $expiry[1] ?? '' );
				$token->set_card_type( $request['resource']['source']['card']['brand'] ?? '' );
				$token->save();
				WC_Payment_Tokens::set_users_default( $wc_customer_id, $token->get_id() );
			} elseif ( isset( $request['resource']['source']['paypal'] ) ) {
				$this->payment_token_paypal->set_token( $request['resource']['id'] );
				$this->payment_token_paypal->set_user_id( $wc_customer_id );
				$this->payment_token_paypal->save();
				WC_Payment_Tokens::set_users_default( $wc_customer_id, $this->payment_token_paypal->get_id() );
			}
		}

		$response['success'] = true;
		return new WP_REST_Response( $response );
	}
}
