import { setVisible } from '../../../ppcp-button/resources/js/modules/Helper/Hiding';
import { setEnabled } from '../../../ppcp-button/resources/js/modules/Helper/ButtonDisabler';
import widgetBuilder from '../../../ppcp-button/resources/js/modules/Renderer/WidgetBuilder';
import UpdatePaymentData from './Helper/UpdatePaymentData';
import { apmButtonsInit } from '../../../ppcp-button/resources/js/modules/Helper/ApmButtons';
import TransactionInfo from './Helper/TransactionInfo';

class GooglepayButton {
	constructor(
		context,
		externalHandler,
		buttonConfig,
		ppcpConfig,
		contextHandler
	) {
		apmButtonsInit( ppcpConfig );

		this.isInitialized = false;

		this.context = context;
		this.externalHandler = externalHandler;
		this.buttonConfig = buttonConfig;
		this.ppcpConfig = ppcpConfig;
		this.contextHandler = contextHandler;

		this.paymentsClient = null;

		this.log = function () {
			if ( this.buttonConfig.is_debug ) {
				//console.log('[GooglePayButton]', ...arguments);
			}
		};
	}

	init( config, transactionInfo ) {
		if ( this.isInitialized ) {
			return;
		}
		this.isInitialized = true;

		if ( ! this.validateConfig() ) {
			return;
		}

		if ( ! this.contextHandler.validateContext() ) {
			return;
		}

		this.googlePayConfig = config;
		this.transactionInfo = transactionInfo;
		this.allowedPaymentMethods = config.allowedPaymentMethods;
		this.baseCardPaymentMethod = this.allowedPaymentMethods[ 0 ];

		this.initClient();
		this.initEventHandlers();

		this.paymentsClient
			.isReadyToPay(
				this.buildReadyToPayRequest(
					this.allowedPaymentMethods,
					config
				)
			)
			.then( ( response ) => {
				if ( response.result ) {
					if (
						( this.context === 'checkout' ||
							this.context === 'pay-now' ) &&
						this.buttonConfig.is_wc_gateway_enabled === '1'
					) {
						const wrapper = document.getElementById(
							'ppc-button-ppcp-googlepay'
						);

						if ( wrapper ) {
							const { ppcpStyle, buttonStyle } =
								this.contextConfig();

							wrapper.classList.add(
								`ppcp-button-${ ppcpStyle.shape }`,
								'ppcp-button-apm',
								'ppcp-button-googlepay'
							);

							if ( ppcpStyle.height ) {
								wrapper.style.height = `${ ppcpStyle.height }px`;
							}

							this.addButtonCheckout(
								this.baseCardPaymentMethod,
								wrapper,
								buttonStyle
							);

							return;
						}
					}

					this.addButton( this.baseCardPaymentMethod );
				}
			} )
			.catch( function ( err ) {
				console.error( err );
			} );
	}

	reinit() {
		if ( ! this.googlePayConfig ) {
			return;
		}

		this.isInitialized = false;
		this.init( this.googlePayConfig, this.transactionInfo );
	}

	validateConfig() {
		if (
			[ 'PRODUCTION', 'TEST' ].indexOf(
				this.buttonConfig.environment
			) === -1
		) {
			console.error(
				'[GooglePayButton] Invalid environment.',
				this.buttonConfig.environment
			);
			return false;
		}

		if ( ! this.contextHandler ) {
			console.error(
				'[GooglePayButton] Invalid context handler.',
				this.contextHandler
			);
			return false;
		}

		return true;
	}

	/**
	 * Returns configurations relative to this button context.
	 */
	contextConfig() {
		const config = {
			wrapper: this.buttonConfig.button.wrapper,
			ppcpStyle: this.ppcpConfig.button.style,
			buttonStyle: this.buttonConfig.button.style,
			ppcpButtonWrapper: this.ppcpConfig.button.wrapper,
		};

		if ( this.context === 'mini-cart' ) {
			config.wrapper = this.buttonConfig.button.mini_cart_wrapper;
			config.ppcpStyle = this.ppcpConfig.button.mini_cart_style;
			config.buttonStyle = this.buttonConfig.button.mini_cart_style;
			config.ppcpButtonWrapper = this.ppcpConfig.button.mini_cart_wrapper;

			// Handle incompatible types.
			if ( config.buttonStyle.type === 'buy' ) {
				config.buttonStyle.type = 'pay';
			}
		}

		if (
			[ 'cart-block', 'checkout-block' ].indexOf( this.context ) !== -1
		) {
			config.ppcpButtonWrapper =
				'#express-payment-method-ppcp-gateway-paypal';
		}

		return config;
	}

	initClient() {
		const callbacks = {
			onPaymentAuthorized: this.onPaymentAuthorized.bind( this ),
		};

		if (
			this.buttonConfig.shipping.enabled &&
			this.contextHandler.shippingAllowed()
		) {
			callbacks.onPaymentDataChanged =
				this.onPaymentDataChanged.bind( this );
		}

		this.paymentsClient = new google.payments.api.PaymentsClient( {
			environment: this.buttonConfig.environment,
			// add merchant info maybe
			paymentDataCallbacks: callbacks,
		} );
	}

	initEventHandlers() {
		const { wrapper, ppcpButtonWrapper } = this.contextConfig();

		if ( wrapper === ppcpButtonWrapper ) {
			throw new Error(
				`[GooglePayButton] "wrapper" and "ppcpButtonWrapper" values must differ to avoid infinite loop. Current value: "${ wrapper }"`
			);
		}

		const syncButtonVisibility = () => {
			const $ppcpButtonWrapper = jQuery( ppcpButtonWrapper );
			setVisible( wrapper, $ppcpButtonWrapper.is( ':visible' ) );
			setEnabled(
				wrapper,
				! $ppcpButtonWrapper.hasClass( 'ppcp-disabled' )
			);
		};

		jQuery( document ).on(
			'ppcp-shown ppcp-hidden ppcp-enabled ppcp-disabled',
			( ev, data ) => {
				if ( jQuery( data.selector ).is( ppcpButtonWrapper ) ) {
					syncButtonVisibility();
				}
			}
		);

		syncButtonVisibility();
	}

	buildReadyToPayRequest( allowedPaymentMethods, baseRequest ) {
		return Object.assign( {}, baseRequest, {
			allowedPaymentMethods,
		} );
	}

	/**
	 * Add a Google Pay purchase button
	 * @param baseCardPaymentMethod
	 */
	addButton( baseCardPaymentMethod ) {
		this.log( 'addButton', this.context );

		const { wrapper, ppcpStyle, buttonStyle } = this.contextConfig();

		this.waitForWrapper( wrapper, () => {
			jQuery( wrapper ).addClass( 'ppcp-button-' + ppcpStyle.shape );

			if ( ppcpStyle.height ) {
				jQuery( wrapper ).css( 'height', `${ ppcpStyle.height }px` );
			}

			const button = this.paymentsClient.createButton( {
				onClick: this.onButtonClick.bind( this ),
				allowedPaymentMethods: [ baseCardPaymentMethod ],
				buttonColor: buttonStyle.color || 'black',
				buttonType: buttonStyle.type || 'pay',
				buttonLocale: buttonStyle.language || 'en',
				buttonSizeMode: 'fill',
			} );

			jQuery( wrapper ).append( button );
		} );
	}

	addButtonCheckout( baseCardPaymentMethod, wrapper, buttonStyle ) {
		const button = this.paymentsClient.createButton( {
			onClick: this.onButtonClick.bind( this ),
			allowedPaymentMethods: [ baseCardPaymentMethod ],
			buttonColor: buttonStyle.color || 'black',
			buttonType: buttonStyle.type || 'pay',
			buttonLocale: buttonStyle.language || 'en',
			buttonSizeMode: 'fill',
		} );

		wrapper.appendChild( button );
	}

	waitForWrapper( selector, callback, delay = 100, timeout = 2000 ) {
		const startTime = Date.now();
		const interval = setInterval( () => {
			const el = document.querySelector( selector );
			const timeElapsed = Date.now() - startTime;

			if ( el ) {
				clearInterval( interval );
				callback( el );
			} else if ( timeElapsed > timeout ) {
				clearInterval( interval );
			}
		}, delay );
	}

	//------------------------
	// Button click
	//------------------------

	/**
	 * Show Google Pay payment sheet when Google Pay payment button is clicked
	 */
	onButtonClick() {
		this.log( 'onButtonClick', this.context );

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
				.then( initiatePaymentRequest, () => {
					console.error(
						'[GooglePayButton] Form validation failed.'
					);
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
		paymentDataRequest.transactionInfo = this.transactionInfo.dataObject;
		paymentDataRequest.merchantInfo = googlePayConfig.merchantInfo;

		if (
			this.buttonConfig.shipping.enabled &&
			this.contextHandler.shippingAllowed()
		) {
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
		this.log( 'onPaymentDataChanged', this.context );
		this.log( 'paymentData', paymentData );

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

				// Handle unserviceable address.
				if ( ! updatedData.shipping_options?.shippingOptions?.length ) {
					paymentDataRequestUpdate.error =
						this.unserviceableShippingAddressError();
					resolve( paymentDataRequestUpdate );
					return;
				}

				if (
					[ 'INITIALIZE', 'SHIPPING_ADDRESS' ].includes(
						paymentData.callbackTrigger
					)
				) {
					paymentDataRequestUpdate.newShippingOptionParameters =
						updatedData.shipping_options;
				}

				transactionInfo.shippingFee = this.getShippingCosts(
					paymentData?.shippingOptionData?.id,
					updatedData.shipping_options
					);

				paymentDataRequestUpdate.newTransactionInfo =
					this.calculateNewTransactionInfo( transactionInfo );

				resolve( paymentDataRequestUpdate );
			} catch ( error ) {
				console.error( 'Error during onPaymentDataChanged:', error );
				reject( error );
			}
		} );
	}

	/**
	 * Returns the shipping costs as numeric value.
	 *
	 * TODO - Move this to the PaymentButton base class
	 *
	 * @param {string} shippingId                           - The shipping method ID.
	 * @param {Object} shippingData                         - The PaymentDataRequest object that
	 *                                                      contains shipping options.
	 * @param {Array}  shippingData.shippingOptions
	 * @param {string} shippingData.defaultSelectedOptionId
	 *
	 * @return {number} The shipping costs.
	 */
	getShippingCosts(
		shippingId,
		{ shippingOptions = [], defaultSelectedOptionId = '' } = {}
	) {
		if ( ! shippingOptions?.length ) {
			this.log( 'Cannot calculate shipping cost: No Shipping Options' );
			return 0;
		}

		const findOptionById = ( id ) =>
			shippingOptions.find( ( option ) => option.id === id );

		const getValidShippingId = () => {
			if (
				'shipping_option_unselected' === shippingId ||
				! findOptionById( shippingId )
			) {
				// Entered on initial call, and when changing the shipping country.
				return defaultSelectedOptionId;
			}

			return shippingId;
		};

		const currentOption = findOptionById( getValidShippingId() );

		return Number( currentOption?.cost ) || 0;
	}

	unserviceableShippingAddressError() {
		return {
			reason: 'SHIPPING_ADDRESS_UNSERVICEABLE',
			message: 'Cannot ship to the selected address',
			intent: 'SHIPPING_ADDRESS',
		};
	}

	/**
	 * Recalculates and returns the plain transaction info object.
	 *
	 * @param {TransactionInfo} transactionInfo - Internal transactionInfo instance.
	 * @return {{totalPrice: string, countryCode: string, totalPriceStatus: string, currencyCode: string}} Updated details.
	 */
	calculateNewTransactionInfo( transactionInfo ) {
		return transactionInfo.dataObject;
	}

	//------------------------
	// Payment process
	//------------------------

	onPaymentAuthorized( paymentData ) {
		this.log( 'onPaymentAuthorized', this.context );
		return this.processPayment( paymentData );
	}

	async processPayment( paymentData ) {
		this.log( 'processPayment', this.context );

		return new Promise( async ( resolve, reject ) => {
			try {
				const id = await this.contextHandler.createOrder();

				this.log( 'processPayment: createOrder', id, this.context );

				const confirmOrderResponse = await widgetBuilder.paypal
					.Googlepay()
					.confirmOrder( {
						orderId: id,
						paymentMethodData: paymentData.paymentMethodData,
					} );

				this.log(
					'processPayment: confirmOrder',
					confirmOrderResponse,
					this.context
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

		this.log( 'processPaymentResponse', response, this.context );

		return response;
	}
}

export default GooglepayButton;
