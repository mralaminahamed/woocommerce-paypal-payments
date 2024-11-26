import BadgeBox from '../BadgeBox';
import { __, sprintf } from '@wordpress/i18n';

const BcdcOptionalPaymentMethods = ( { isPayLater, storeCountry } ) => {
	if ( isPayLater && storeCountry === 'us' ) {
		return (
			<div className="ppcp-r-optional-payment-methods__wrapper">
				<BadgeBox
					title={ __(
						'Credit and Debit Cards',
						'woocommerce-paypal-payments'
					) }
					imageBadge={ [
						'icon-button-visa.svg',
						'icon-button-mastercard.svg',
						'icon-button-amex.svg',
						'icon-button-discover.svg',
					] }
					textBadge={ __(
						'from 2.59% + $0.49 USD<sup>1</sup>',
						'woocommerce-paypal-payments'
					) }
					description={ sprintf(
						// translators: %s: Link to PayPal REST application guide
						__(
							'Process major credit and debit cards through PayPal’s card fields. <a target="_blank" href="%s">Learn more</a>',
							'woocommerce-paypal-payments'
						),
						'https://woocommerce.com/document/woocommerce-paypal-payments/#manual-credential-input '
					) }
				/>
			</div>
		);
	}

	return (
		<div className="ppcp-r-optional-payment-methods__wrapper">
			<BadgeBox
				title={ __(
					'Credit and Debit Cards',
					'woocommerce-paypal-payments'
				) }
				imageBadge={ [
					'icon-button-visa.svg',
					'icon-button-mastercard.svg',
					'icon-button-amex.svg',
					'icon-button-discover.svg',
				] }
				textBadge={ __(
					'from 3.40% + €0.35 EUR<sup>1</sup>',
					'woocommerce-paypal-payments'
				) }
				description={ sprintf(
					// translators: %s: Link to PayPal REST application guide
					__(
						'Process major credit and debit cards through PayPal’s card fields. <a target="_blank" href="%s">Learn more</a>',
						'woocommerce-paypal-payments'
					),
					'https://woocommerce.com/document/woocommerce-paypal-payments/#manual-credential-input '
				) }
			/>
		</div>
	);
};

export default BcdcOptionalPaymentMethods;
