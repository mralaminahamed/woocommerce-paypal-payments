const onApprove = (context, errorHandler, spinner) => {
    return (data, actions) => {
        spinner.block();
        return fetch(context.config.ajax.approve_order.endpoint, {
            method: 'POST',
            body: JSON.stringify({
                nonce: context.config.ajax.approve_order.nonce,
                order_id:data.orderID
            })
        }).then((res)=>{
            return res.json();
        }).then((data)=>{
            spinner.unblock();
            if (!data.success) {
                errorHandler.genericError();
                if (typeof actions.restart !== 'undefined') {
                    return actions.restart();
                }
                throw new Error(data.data.message);
            }
            document.querySelector('#place_order').click()
        });

    }
}

export default onApprove;
