import {
	combineStyles,
	combineWrapperIds,
} from '../../../ppcp-button/resources/js/modules/Helper/PaymentButtonHelpers';
import PaymentButton from '../../../ppcp-button/resources/js/modules/Renderer/PaymentButton';
import widgetBuilder from '../../../ppcp-button/resources/js/modules/Renderer/WidgetBuilder';
import UpdatePaymentData from './Helper/UpdatePaymentData';
import { PaymentMethods } from '../../../ppcp-button/resources/js/modules/Helper/CheckoutMethodState';

/**
 * Plugin-specific styling.
 *
 * Note that most properties of this object do not apply to the Google Pay button.
 *
 * @typedef {Object} PPCPStyle
 * @property {string}  shape  - Outline shape.
 * @property {?number} height - Button height in pixel.
 */

/**
 * Style options that are defined by the Google Pay SDK and are required to render the button.
 *
 * @typedef {Object} GooglePayStyle
 * @property {string} type     - Defines the button label.
 * @property {string} color    - Button color
 * @property {string} language - The locale; an empty string will apply the user-agent's language.
 */

/**
 * Google Pay JS SDK
 *
 * @see https://developers.google.com/pay/api/web/reference/request-objects
 * @typedef {Object} GooglePaySDK
 * @property {typeof PaymentsClient} PaymentsClient - Main API client for payment actions.
 */

/**
 * The Payments Client class, generated by the Google Pay SDK.
 *
 * @see https://developers.google.com/pay/api/web/reference/client
 * @typedef {Object} PaymentsClient
 * @property {Function} createButton         - The convenience method is used to generate a Google Pay payment button styled with the latest Google Pay branding for insertion into a webpage.
 * @property {Function} isReadyToPay         - Use the isReadyToPay(isReadyToPayRequest) method to determine a user's ability to return a form of payment from the Google Pay API.
 * @property {Function} loadPaymentData      - This method presents a Google Pay payment sheet that allows selection of a payment method and optionally configured parameters
 * @property {Function} onPaymentAuthorized  - This method is called when a payment is authorized in the payment sheet.
 * @property {Function} onPaymentDataChanged - This method handles payment data changes in the payment sheet such as shipping address and shipping options.
 */

class GooglepayButton extends PaymentButton {
	/**
	 * @inheritDoc
	 */
	static methodId = PaymentMethods.GOOGLEPAY;

	/**
	 * @inheritDoc
	 */
	static cssClass = 'google-pay';

	/**
	 * Client reference, provided by the Google Pay JS SDK.
	 */
	#paymentsClient = null;

	/**
	 * @inheritDoc
	 */
	static getWrappers( buttonConfig, ppcpConfig ) {
		return combineWrapperIds(
			buttonConfig?.button?.wrapper || '',
			buttonConfig?.button?.mini_cart_wrapper || '',
			ppcpConfig?.button?.wrapper || '',
			'ppc-button-googlepay-container',
			'ppc-button-ppcp-googlepay'
		);
	}

	/**
	 * @inheritDoc
	 */
	static getStyles( buttonConfig, ppcpConfig ) {
		const styles = combineStyles(
			ppcpConfig?.button || {},
			buttonConfig?.button || {}
		);

		if ( 'buy' === styles.MiniCart.type ) {
			styles.MiniCart.type = 'pay';
		}

		return styles;
	}

	constructor(
		context,
		externalHandler,
		buttonConfig,
		ppcpConfig,
		contextHandler
	) {
		super(
			context,
			externalHandler,
			buttonConfig,
			ppcpConfig,
			contextHandler
		);

		this.onPaymentAuthorized = this.onPaymentAuthorized.bind( this );
		this.onPaymentDataChanged = this.onPaymentDataChanged.bind( this );
		this.onButtonClick = this.onButtonClick.bind( this );

		this.log( 'Create instance' );
	}

	/**
	 * @inheritDoc
	 */
	get isConfigValid() {
		const validEnvs = [ 'PRODUCTION', 'TEST' ];

		if ( ! validEnvs.includes( this.buttonConfig.environment ) ) {
			this.error( 'Invalid environment.', this.buttonConfig.environment );
			return false;
		}

		// Preview buttons only need a valid environment.
		if ( this.isPreview ) {
			return true;
		}

		if ( ! this.googlePayConfig ) {
			this.error( 'No API configuration - missing configure() call?' );
			return false;
		}

		if ( ! this.transactionInfo ) {
			this.error( 'No transactionInfo - missing configure() call?' );
			return false;
		}

		if ( ! typeof this.contextHandler?.validateContext() ) {
			this.error( 'Invalid context handler.', this.contextHandler );
			return false;
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	get requiresShipping() {
		return super.requiresShipping && this.buttonConfig.shipping?.enabled;
	}

	/**
	 * The Google Pay API.
	 *
	 * @return {?GooglePaySDK} API for the Google Pay JS SDK, or null when SDK is not ready yet.
	 */
	get googlePayApi() {
		return window.google?.payments?.api;
	}

	/**
	 * The Google Pay PaymentsClient instance created by this button.
	 * @see https://developers.google.com/pay/api/web/reference/client
	 *
	 * @return {?PaymentsClient} The SDK object, or null when SDK is not ready yet.
	 */
	get paymentsClient() {
		return this.#paymentsClient;
	}

	/**
	 * Configures the button instance. Must be called before the initial `init()`.
	 *
	 * @param {Object} apiConfig       - API configuration.
	 * @param {Object} transactionInfo - Transaction details; required before "init" call.
	 */
	configure( apiConfig, transactionInfo ) {
		this.googlePayConfig = apiConfig;
			this.transactionInfo = transactionInfo;

		this.allowedPaymentMethods = this.googlePayConfig.allowedPaymentMethods;
		this.baseCardPaymentMethod = this.allowedPaymentMethods[ 0 ];
		}

	init() {
		// Stop, if the button is already initialized.
		if ( this.isInitialized ) {
			return;
		}

		// Stop, if configuration is invalid.
		if ( ! this.isConfigValid ) {
			return;
		}

		super.init();
		this.#paymentsClient = this.createPaymentsClient();

		if ( ! this.isPresent ) {
			this.log( 'Payment wrapper not found', this.wrapperId );
			return;
		}

		if ( ! this.paymentsClient ) {
			this.log( 'Could not initialize the payments client' );
			return;
		}

		this.paymentsClient
			.isReadyToPay(
				this.buildReadyToPayRequest(
					this.allowedPaymentMethods,
					this.googlePayConfig
				)
			)
			.then( ( response ) => {
				this.log( 'PaymentsClient.isReadyToPay response:', response );
				this.isEligible = !! response.result;
			} )
			.catch( ( err ) => {
				console.error( err );
				this.isEligible = false;
			} );
	}

	reinit() {
		super.reinit();
		this.init();
	}

	/**
	 * Provides an object with relevant paymentDataCallbacks for the current button instance.
	 *
	 * @return {Object} An object containing callbacks for the current scope & configuration.
	 */
	preparePaymentDataCallbacks() {
		const callbacks = {};

		// We do not attach any callbacks to preview buttons.
		if ( this.isPreview ) {
			return callbacks;
		}

		callbacks.onPaymentAuthorized = this.onPaymentAuthorized;

		if ( this.requiresShipping ) {
			callbacks.onPaymentDataChanged = this.onPaymentDataChanged;
		}

		return callbacks;
	}

	createPaymentsClient() {
		if ( ! this.googlePayApi ) {
			return null;
		}

		const callbacks = this.preparePaymentDataCallbacks();

		/**
		 * Consider providing merchant info here:
		 *
		 * @see https://developers.google.com/pay/api/web/reference/request-objects#PaymentOptions
		 */
		return new this.googlePayApi.PaymentsClient( {
			environment: this.buttonConfig.environment,
			paymentDataCallbacks: callbacks,
		} );
	}

	buildReadyToPayRequest( allowedPaymentMethods, baseRequest ) {
		this.log( 'Ready To Pay request', baseRequest, allowedPaymentMethods );

		return Object.assign( {}, baseRequest, {
			allowedPaymentMethods,
		} );
	}

	/**
	 * Creates the payment button and calls `this.insertButton()` to make the button visible in the
	 * correct wrapper.
	 */
	addButton() {
		if ( ! this.isInitialized || ! this.paymentsClient ) {
			return;
		}

		const baseCardPaymentMethod = this.baseCardPaymentMethod;
		const { color, type, language } = this.style;

		/**
		 * @see https://developers.google.com/pay/api/web/reference/client#createButton
		 */
		const button = this.paymentsClient.createButton( {
			onClick: this.onButtonClick,
			allowedPaymentMethods: [ baseCardPaymentMethod ],
			buttonColor: color || 'black',
			buttonType: type || 'pay',
			buttonLocale: language || 'en',
			buttonSizeMode: 'fill',
		} );

		this.insertButton( button );
	}

	//------------------------
	// Button click
	//------------------------

	/**
	 * Show Google Pay payment sheet when Google Pay payment button is clicked
	 */
	onButtonClick() {
		this.log( 'onButtonClick' );

		const initiatePaymentRequest = () => {
			window.ppcpFundingSource = 'googlepay';

			const paymentDataRequest = this.paymentDataRequest();

			this.log(
				'onButtonClick: paymentDataRequest',
				paymentDataRequest,
				this.context
			);

			this.paymentsClient.loadPaymentData( paymentDataRequest );
		};

		if ( 'function' === typeof this.contextHandler.validateForm ) {
			// During regular checkout, validate the checkout form before initiating the payment.
			this.contextHandler
				.validateForm()
				.then( initiatePaymentRequest, ( reason ) => {
					this.error( 'Form validation failed.', reason );
				} );
		} else {
			// This is the flow on product page, cart, and other non-checkout pages.
			initiatePaymentRequest();
		}
	}

	paymentDataRequest() {
		const baseRequest = {
			apiVersion: 2,
			apiVersionMinor: 0,
		};

		const googlePayConfig = this.googlePayConfig;
		const paymentDataRequest = Object.assign( {}, baseRequest );
		paymentDataRequest.allowedPaymentMethods =
			googlePayConfig.allowedPaymentMethods;
		paymentDataRequest.transactionInfo = this.transactionInfo;
		paymentDataRequest.merchantInfo = googlePayConfig.merchantInfo;

		if ( this.requiresShipping ) {
			paymentDataRequest.callbackIntents = [
				'SHIPPING_ADDRESS',
				'SHIPPING_OPTION',
				'PAYMENT_AUTHORIZATION',
			];
			paymentDataRequest.shippingAddressRequired = true;
			paymentDataRequest.shippingAddressParameters =
				this.shippingAddressParameters();
			paymentDataRequest.shippingOptionRequired = true;
		} else {
			paymentDataRequest.callbackIntents = [ 'PAYMENT_AUTHORIZATION' ];
		}

		return paymentDataRequest;
	}

	//------------------------
	// Shipping processing
	//------------------------

	shippingAddressParameters() {
		return {
			allowedCountryCodes: this.buttonConfig.shipping.countries,
			phoneNumberRequired: true,
		};
	}

	onPaymentDataChanged( paymentData ) {
		this.log( 'onPaymentDataChanged', paymentData );

		return new Promise( async ( resolve, reject ) => {
			try {
				const paymentDataRequestUpdate = {};

				const updatedData = await new UpdatePaymentData(
					this.buttonConfig.ajax.update_payment_data
				).update( paymentData );
				const transactionInfo = this.transactionInfo;

				this.log( 'onPaymentDataChanged:updatedData', updatedData );
				this.log(
					'onPaymentDataChanged:transactionInfo',
					transactionInfo
				);

				updatedData.country_code = transactionInfo.countryCode;
				updatedData.currency_code = transactionInfo.currencyCode;
				updatedData.total_str = transactionInfo.totalPrice;

				// Handle unserviceable address.
				if ( ! updatedData.shipping_options?.shippingOptions?.length ) {
					paymentDataRequestUpdate.error =
						this.unserviceableShippingAddressError();
					resolve( paymentDataRequestUpdate );
					return;
				}

				switch ( paymentData.callbackTrigger ) {
					case 'INITIALIZE':
					case 'SHIPPING_ADDRESS':
						paymentDataRequestUpdate.newShippingOptionParameters =
							updatedData.shipping_options;
						paymentDataRequestUpdate.newTransactionInfo =
							this.calculateNewTransactionInfo( updatedData );
						break;
					case 'SHIPPING_OPTION':
						paymentDataRequestUpdate.newTransactionInfo =
							this.calculateNewTransactionInfo( updatedData );
						break;
				}

				resolve( paymentDataRequestUpdate );
			} catch ( error ) {
				console.error( 'Error during onPaymentDataChanged:', error );
				reject( error );
			}
		} );
	}

	unserviceableShippingAddressError() {
		return {
			reason: 'SHIPPING_ADDRESS_UNSERVICEABLE',
			message: 'Cannot ship to the selected address',
			intent: 'SHIPPING_ADDRESS',
		};
	}

	calculateNewTransactionInfo( updatedData ) {
		return {
			countryCode: updatedData.country_code,
			currencyCode: updatedData.currency_code,
			totalPriceStatus: 'FINAL',
			totalPrice: updatedData.total_str,
		};
	}

	//------------------------
	// Payment process
	//------------------------

	onPaymentAuthorized( paymentData ) {
		this.log( 'onPaymentAuthorized' );
		return this.processPayment( paymentData );
	}

	async processPayment( paymentData ) {
		this.log( 'processPayment' );

		return new Promise( async ( resolve, reject ) => {
			try {
				const id = await this.contextHandler.createOrder();

				this.log( 'processPayment: createOrder', id );

				const confirmOrderResponse = await widgetBuilder.paypal
					.Googlepay()
					.confirmOrder( {
						orderId: id,
						paymentMethodData: paymentData.paymentMethodData,
					} );

				this.log(
					'processPayment: confirmOrder',
					confirmOrderResponse
				);

				/** Capture the Order on the Server */
				if ( confirmOrderResponse.status === 'APPROVED' ) {
					let approveFailed = false;
					await this.contextHandler.approveOrder(
						{
							orderID: id,
						},
						{
							// actions mock object.
							restart: () =>
								new Promise( ( resolve, reject ) => {
									approveFailed = true;
									resolve();
								} ),
							order: {
								get: () =>
									new Promise( ( resolve, reject ) => {
										resolve( null );
									} ),
							},
						}
					);

					if ( ! approveFailed ) {
						resolve( this.processPaymentResponse( 'SUCCESS' ) );
					} else {
						resolve(
							this.processPaymentResponse(
								'ERROR',
								'PAYMENT_AUTHORIZATION',
								'FAILED TO APPROVE'
							)
						);
					}
				} else {
					resolve(
						this.processPaymentResponse(
							'ERROR',
							'PAYMENT_AUTHORIZATION',
							'TRANSACTION FAILED'
						)
					);
				}
			} catch ( err ) {
				resolve(
					this.processPaymentResponse(
						'ERROR',
						'PAYMENT_AUTHORIZATION',
						err.message
					)
				);
			}
		} );
	}

	processPaymentResponse( state, intent = null, message = null ) {
		const response = {
			transactionState: state,
		};

		if ( intent || message ) {
			response.error = {
				intent,
				message,
			};
		}

		this.log( 'processPaymentResponse', response );

		return response;
	}
}

export default GooglepayButton;
