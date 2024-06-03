import {useEffect, useState} from '@wordpress/element';

import {
    PayPalScriptProvider,
    PayPalCardFieldsProvider,
    PayPalCardFieldsForm,
} from "@paypal/react-paypal-js";

import {CheckoutHandler} from "./checkout-handler";
import {createOrder, onApprove} from "../card-fields-config";

export function CardFields({config, eventRegistration, emitResponse}) {
    const {onPaymentSetup} = eventRegistration;
    const {responseTypes} = emitResponse;

    const [cardFieldsForm, setCardFieldsForm] = useState();
    const getCardFieldsForm = (cardFieldsForm) => {
        setCardFieldsForm(cardFieldsForm)
    }

    const getSavePayment = (savePayment) => {
        localStorage.setItem('ppcp-save-card-payment', savePayment);
    }

    useEffect(
        () =>
            onPaymentSetup(() => {
                async function handlePaymentProcessing() {
                    await cardFieldsForm.submit()
                        .catch((error) => {
                            return {
                                type: responseTypes.ERROR,
                            }
                        });

                    return {
                        type: responseTypes.SUCCESS,
                    }
                }

                return handlePaymentProcessing();
            }),
        [onPaymentSetup, cardFieldsForm]
    );

    return (
        <>
            <PayPalScriptProvider
                options={{
                    clientId: config.scriptData.client_id,
                    components: "card-fields",
                    dataNamespace: 'ppcp-block-card-fields',
                }}
            >
                <PayPalCardFieldsProvider
                    createOrder={createOrder}
                    onApprove={onApprove}
                    onError={(err) => {
                        console.error(err);
                    }}
                >
                    <PayPalCardFieldsForm/>
                    <CheckoutHandler
                        getCardFieldsForm={getCardFieldsForm}
                        getSavePayment={getSavePayment}
                        saveCardText={config.save_card_text}
                        is_vaulting_enabled={config.is_vaulting_enabled}
                    />
                </PayPalCardFieldsProvider>
            </PayPalScriptProvider>
        </>
    )
}
