    // BEGIN PADDLE CHECKOUT INSTALLER — managed by install.sh, re-run to update
    /**
     * Paddle's "default payment link" destination, shared by both the one-time
     * (PaddleCheckout) and recurring (PaddleSubscription) gateways. Paddle appends
     * ?_ptxn={transaction_id} to this URL once a transaction is created, and Paddle.js
     * (embedded in the view) reads that parameter to open the actual checkout overlay
     * for the customer — one-time and recurring prices use the exact same overlay.
     */
    public function paddleCheckout(Request $request)
    {
        $transactionId = (string) $request->query('_ptxn', '');

        abort_if($transactionId === '', 404);

        $payment = Payment::where('transaction_id', $transactionId)->first();

        if ($payment) {
            $gatewayConfig = $payment->gatewayConfig;

            abort_if(! $gatewayConfig, 404);

            return view('theme::payments.paddle-checkout', [
                'transactionId' => $transactionId,
                'sandbox' => (string) $gatewayConfig->config('sandbox', '0') === '1',
                'clientSideToken' => $gatewayConfig->config('client_side_token'),
                'callbackUrl' => route('payments.gateway.callback', [
                    'webhook_id' => $gatewayConfig->webhook_id,
                    'payment_token' => $payment->token,
                ]),
            ]);
        }

        $subscription = Subscription::where('data->paddle_transaction_id', $transactionId)->first();

        abort_if(! $subscription, 404);

        $gatewayConfig = $subscription->gatewayConfig;

        abort_if(! $gatewayConfig, 404);

        return view('theme::payments.paddle-checkout', [
            'transactionId' => $transactionId,
            'sandbox' => (string) $gatewayConfig->config('sandbox', '0') === '1',
            'clientSideToken' => $gatewayConfig->config('client_side_token'),
            'callbackUrl' => $subscription->callbackUrl(),
        ]);
    }

    // END PADDLE CHECKOUT INSTALLER

