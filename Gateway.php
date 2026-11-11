<?php

namespace Extensions\Gateways\PaddleSubscription;

use App\Extensions\Foundation\SubscriptionGatewayExtension;
use App\Models\GatewayConfig;
use App\Models\Subscription;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class Gateway extends SubscriptionGatewayExtension
{
    /**
     * Define the extension identifier. This identifier should be unique.
     *
     * @var string
     */
    protected string $id = 'gateway-paddle-subscription';

    /**
     * Define the extension display name
     *
     * @var string
     */
    protected string $name = 'Paddle Subscription';

    /**
     * Define the extension description.
     *
     * @var string
     */
    protected string $description = 'Accept recurring payments using Paddle Billing (Merchant of Record).';

    /**
     * Define the extension type. For example, if the extension is a module, the extension type should be "Module".
     *
     * @var string
     */
    protected string $type = 'Gateway';

    /**
     * Define the gateway type. Can be "subscription" or "payment".
     *
     * @var string
     */
    protected string $gatewayType = 'subscription';

    /**
     * Define the supported currencies. Empty array means all currencies are supported.
     *
     * @var array
     */
    protected array $currencies = [];

    /**
     * Define the extension version.
     *
     * @var string
     */
    protected string $version = '1.0.0';

    /**
     * Define the WemX versions that the extension is compatible with.
     *
     * @var array
     */
    protected array $wemxVersions = [
        '*',
    ];

    /**
     * Define the authors of the extension.
     *
     * @var array
     */
    protected array $authors = [
        [
            'name' => 'OpsLab',
            'email' => 'claude-ops@opslabsystems.cloud',
        ],
    ];

    /**
     * Define the extension display description to customers.
     *
     * @var string
     */
    public string $gatewayDescription = 'Pay securely by card, PayPal, or other local payment methods via Paddle.';

    /**
     * Define the default configuration values required to setup this gateway.
     *
     * @return array
     */
    public static function setConfig(): array
    {
        return [
            'api_key' => [
                'label' => 'Paddle API Key',
                'description' => 'Paddle Dashboard → Developer Tools → Authentication. Use a sandbox API key while "Sandbox Mode" below is enabled.',
                'type' => 'text',
                'rules' => ['required', 'string'],
            ],
            'client_side_token' => [
                'label' => 'Client-side Token',
                'description' => 'Paddle Dashboard → Developer Tools → Authentication → Client-side tokens (a separate, publicly-safe token — not the API Key above). Required to actually render Paddle\'s checkout overlay in the customer\'s browser.',
                'type' => 'text',
                'rules' => ['required', 'string'],
            ],
            'webhook_secret' => [
                'label' => 'Notification Secret Key',
                'description' => 'Create a notification destination in Paddle Dashboard → Developer Tools → Notifications pointing at this gateway\'s webhook URL (shown below once saved), subscribed to the subscription.* and transaction.completed events. Paste that destination\'s secret key here.',
                'type' => 'text',
                'rules' => ['required', 'string'],
            ],
            'sandbox' => [
                'label' => 'Sandbox Mode',
                'description' => 'Enable while testing with a Paddle sandbox account. Disable to process real payments.',
                'type' => 'select',
                'options' => [
                    '0' => 'Disabled (Live)',
                    '1' => 'Enabled (Sandbox)',
                ],
                'rules' => ['required'],
                'default_value' => '0',
            ],
        ];
    }

    /**
     * Create a Paddle transaction for a recurring (non-catalog) price and redirect the
     * customer to the checkout overlay page. Paddle itself creates the underlying
     * Subscription object once this transaction is paid — we don't create it directly.
     *
     * @throws Exception
     */
    public function subscribe(Subscription $subscription, GatewayConfig $gatewayConfig)
    {
        $this->ensureDefaultCheckoutUrlConfigured($gatewayConfig);

        $interval = $this->toBillingCycle($subscription->frequency);

        $response = Http::withToken($gatewayConfig->config('api_key'))
            ->acceptJson()
            ->post($this->apiUrl($gatewayConfig, '/transactions'), [
                'currency_code' => $subscription->currency,
                'collection_mode' => 'automatic',
                'custom_data' => [
                    'subscription_token' => $subscription->token,
                ],
                'items' => [[
                    'quantity' => 1,
                    'price' => [
                        'description' => $subscription->description,
                        'name' => $subscription->description,
                        'unit_price' => [
                            'amount' => $this->toMinorUnits($subscription->amount, $subscription->currency),
                            'currency_code' => $subscription->currency,
                        ],
                        'billing_cycle' => $interval,
                        // Non-catalog price: Paddle requires either product_id (an existing
                        // catalog product) or a nested product object to create one inline.
                        // We have no Paddle catalog product to reference, so create inline.
                        'product' => [
                            'name' => $subscription->description,
                            'tax_category' => 'standard',
                        ],
                    ],
                ]],
            ]);

        if ($response->failed()) {
            throw new Exception('Paddle transaction creation failed: '.$response->body());
        }

        $transaction = $response->json('data');
        $checkoutUrl = $transaction['checkout']['url'] ?? null;

        if (! $checkoutUrl) {
            throw new Exception('Paddle did not return a checkout URL. Configure a default payment link in your Paddle Dashboard → Checkout settings.');
        }

        // We don't have a real Paddle subscription_id yet — Paddle only creates one once
        // this transaction is paid. Stash the transaction id in `data` so the webhook
        // (subscription.created carries the originating transaction_id) and the
        // best-effort browser callback can both find their way back to this record.
        $subscription->update([
            'data' => array_merge($subscription->data ?? [], [
                'paddle_transaction_id' => $transaction['id'],
            ]),
        ]);

        return redirect($checkoutUrl);
    }

    /**
     * Handle the browser redirect back from the checkout overlay.
     * Paddle's webhook is the authoritative source of truth — this is only a
     * best-effort confirmation so the customer isn't staring at a blank page
     * waiting for the webhook to land.
     *
     * @throws Exception
     */
    public function callback(Request $request, GatewayConfig $gatewayConfig)
    {
        $subscription = Subscription::where('token', $request->get('subscription_token'))->first();

        if (! $subscription) {
            throw new Exception('Subscription not found');
        }

        if ($subscription->isActive()) {
            return redirect($subscription->successUrl());
        }

        $transactionId = $subscription->data('paddle_transaction_id');

        if ($transactionId) {
            $response = Http::withToken($gatewayConfig->config('api_key'))
                ->acceptJson()
                ->get($this->apiUrl($gatewayConfig, '/transactions/'.$transactionId));

            if ($response->successful()) {
                $paddleSubscriptionId = $response->json('data.subscription_id');

                if ($paddleSubscriptionId) {
                    $this->syncFromPaddleSubscription($subscription, $gatewayConfig, $paddleSubscriptionId);
                }
            }
        }

        return redirect($subscription->successUrl());
    }

    /**
     * Handle webhooks (notifications) from Paddle.
     *
     * @throws Exception
     */
    public function webhook(Request $request, GatewayConfig $gatewayConfig)
    {
        $this->verifyPaddleWebhook($request, $gatewayConfig->config('webhook_secret'));

        $eventType = $request->input('event_type');
        $data = $request->input('data', []);

        if (! str_starts_with((string) $eventType, 'subscription.')) {
            return response()->json(['message' => 'Event ignored'], 200);
        }

        $paddleSubscriptionId = $data['id'] ?? null;

        if (! $paddleSubscriptionId) {
            throw new Exception('Subscription ID not found in webhook payload');
        }

        $subscription = Subscription::where('subscription_id', $paddleSubscriptionId)->first();

        // subscription.created is the only event carrying the originating transaction_id,
        // so it's how we first link a Paddle subscription back to our own record.
        if (! $subscription && ! empty($data['transaction_id'])) {
            $subscription = Subscription::where('data->paddle_transaction_id', $data['transaction_id'])->first();
        }

        if (! $subscription) {
            throw new Exception('Subscription not found');
        }

        $nextBilledAt = $data['next_billed_at'] ?? null;

        match ($eventType) {
            'subscription.created', 'subscription.activated', 'subscription.resumed' => $subscription->isActive()
                ? $subscription->updateNextBillingDate($nextBilledAt ?? now()->addDays($subscription->frequency))
                : $subscription->activated($paddleSubscriptionId, $nextBilledAt, $data),
            'subscription.updated' => in_array($data['status'] ?? null, ['active', 'trialing'], true)
                ? $this->syncNextBillingDate($subscription, $nextBilledAt)
                : null,
            'subscription.past_due', 'subscription.paused' => $subscription->inactive(),
            'subscription.canceled' => $subscription->cancelled('Cancelled via Paddle'),
            default => null,
        };

        return response()->json(['message' => 'Webhook processed'], 200);
    }

    /**
     * Fetch the live subscription status directly from Paddle and sync our local state —
     * used by WemX's periodic subscription check cron, independent of webhooks.
     *
     * @throws Exception
     */
    public function checkSubscription(Subscription $subscription, GatewayConfig $gatewayConfig)
    {
        if (! $subscription->subscription_id) {
            return false;
        }

        $response = Http::withToken($gatewayConfig->config('api_key'))
            ->acceptJson()
            ->get($this->apiUrl($gatewayConfig, '/subscriptions/'.$subscription->subscription_id));

        if ($response->failed()) {
            throw new Exception('Failed to check subscription: '.$response->body());
        }

        $data = $response->json('data');
        $status = $data['status'] ?? null;

        if (in_array($status, ['active', 'trialing'], true)) {
            $this->syncNextBillingDate($subscription, $data['next_billed_at'] ?? null);

            return true;
        }

        if ($status === 'canceled' && $subscription->isActive()) {
            $subscription->cancelled('Cancelled via Paddle');

            return false;
        }

        if ($subscription->isActive()) {
            $subscription->inactive();
        }

        return false;
    }

    /**
     * Cancel the subscription directly at Paddle. Cancels immediately rather than at the
     * next billing period, so WemX's local "cancelled" state and Paddle's billing stay
     * in sync — otherwise Paddle would keep charging the customer after WemX stops
     * considering the subscription active.
     *
     * @throws Exception
     */
    public function cancelSubscription(Subscription $subscription, GatewayConfig $gatewayConfig)
    {
        if (! $subscription->subscription_id) {
            $subscription->cancelled('Cancelled before first payment');

            return true;
        }

        $response = Http::withToken($gatewayConfig->config('api_key'))
            ->acceptJson()
            ->post($this->apiUrl($gatewayConfig, '/subscriptions/'.$subscription->subscription_id.'/cancel'), [
                'effective_from' => 'immediately',
            ]);

        if ($response->failed()) {
            throw new Exception('Paddle subscription cancellation failed: '.$response->body());
        }

        $subscription->cancelled('Cancelled by customer');

        return true;
    }

    /**
     * Shared by callback() and the "subscription.created" webhook path: links a freshly
     * discovered Paddle subscription_id to our record and marks it active.
     */
    private function syncFromPaddleSubscription(Subscription $subscription, GatewayConfig $gatewayConfig, string $paddleSubscriptionId): void
    {
        $response = Http::withToken($gatewayConfig->config('api_key'))
            ->acceptJson()
            ->get($this->apiUrl($gatewayConfig, '/subscriptions/'.$paddleSubscriptionId));

        if ($response->failed()) {
            return;
        }

        $data = $response->json('data');

        if (in_array($data['status'] ?? null, ['active', 'trialing'], true)) {
            $subscription->activated($paddleSubscriptionId, $data['next_billed_at'] ?? null, $data);
        }
    }

    private function syncNextBillingDate(Subscription $subscription, ?string $nextBilledAt): void
    {
        if (! $nextBilledAt) {
            return;
        }

        if ($subscription->isNotActive()) {
            $subscription->activated($subscription->subscription_id, $nextBilledAt);

            return;
        }

        $subscription->updateNextBillingDate($nextBilledAt);
    }

    /**
     * Paddle refuses to create a transaction / hosted checkout session until the account
     * has a "default payment link" set (Dashboard → Checkout → Checkout settings),
     * otherwise it fails with transaction_default_checkout_url_not_set. Rather than make
     * the merchant configure that by hand, set it ourselves via the API the first time
     * it's missing. We only ever fill it in when empty — if an admin has already set a
     * custom one via the Dashboard (or the Paddle Checkout payment gateway already did),
     * we leave it alone.
     */
    private function ensureDefaultCheckoutUrlConfigured(GatewayConfig $gatewayConfig): void
    {
        $response = Http::withToken($gatewayConfig->config('api_key'))
            ->acceptJson()
            ->get($this->apiUrl($gatewayConfig, '/settings/checkout-url'));

        if ($response->failed()) {
            return;
        }

        if (! empty($response->json('data.default_url'))) {
            return;
        }

        Http::withToken($gatewayConfig->config('api_key'))
            ->acceptJson()
            ->patch($this->apiUrl($gatewayConfig, '/settings/checkout-url'), [
                'default_url' => route('payments.paddle.checkout'),
            ]);
    }

    /**
     * Build a full API URL for the configured environment (sandbox or live).
     */
    private function apiUrl(GatewayConfig $gatewayConfig, string $path): string
    {
        $base = ((string) $gatewayConfig->config('sandbox', '0') === '1')
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';

        return $base.$path;
    }

    /**
     * Paddle amounts are integer strings in the currency's smallest unit (e.g. cents) —
     * except for a handful of zero-decimal currencies Paddle supports (confirmed against
     * their live /currencies endpoint) where the smallest unit *is* the base unit, so
     * multiplying by 100 would overcharge by 100x.
     */
    private function toMinorUnits(float $amount, string $currency): string
    {
        $zeroDecimalCurrencies = ['JPY', 'KRW', 'VND', 'CLP'];

        if (in_array(strtoupper($currency), $zeroDecimalCurrencies, true)) {
            return (string) (int) round($amount);
        }

        return (string) (int) round($amount * 100);
    }

    /**
     * Convert WemX's `frequency` (billing interval in days) into Paddle's
     * billing_cycle shape, picking the largest interval that evenly divides it
     * so e.g. 365 days becomes "1 year" rather than "365 days".
     */
    private function toBillingCycle(int $days): array
    {
        $intervals = [
            365 => 'year',
            30 => 'month',
            7 => 'week',
            1 => 'day',
        ];

        foreach ($intervals as $dayEquivalent => $label) {
            if ($days % $dayEquivalent === 0) {
                return [
                    'interval' => $label,
                    'frequency' => (int) ($days / $dayEquivalent),
                ];
            }
        }

        return ['interval' => 'day', 'frequency' => $days];
    }

    /**
     * Verify the "Paddle-Signature" header against the raw request body.
     * Format: ts=<unix_timestamp>;h1=<hex hmac-sha256 of "ts:raw_body">
     *
     * @throws Exception
     */
    private function verifyPaddleWebhook(Request $request, ?string $webhookSecret): void
    {
        if (! $webhookSecret) {
            throw new Exception('Paddle webhook secret is not configured.');
        }

        $sigHeader = $request->header('Paddle-Signature');

        if (! $sigHeader) {
            throw new Exception('Paddle-Signature header is missing.');
        }

        $timestamp = null;
        $signature = null;

        foreach (explode(';', $sigHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 'ts') {
                $timestamp = $value;
            } elseif ($key === 'h1') {
                $signature = $value;
            }
        }

        if (! $timestamp || ! $signature) {
            throw new Exception('Invalid Paddle-Signature header format.');
        }

        $rawBody = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $timestamp.':'.$rawBody, $webhookSecret);

        if (! hash_equals($expectedSignature, $signature)) {
            throw new Exception('Invalid Paddle webhook signature.');
        }

        // Reject stale signatures — without this, a correctly-signed payload captured
        // once (e.g. from logs, a compromised proxy) could be replayed indefinitely.
        if (abs(time() - (int) $timestamp) > 300) {
            throw new Exception('Paddle webhook timestamp is too old.');
        }
    }
}
