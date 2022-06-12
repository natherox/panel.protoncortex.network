<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Store;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Pterodactyl\Exceptions\DisplayException;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;
use Pterodactyl\Http\Requests\Api\Client\Store\Gateways\PayPalRequest;

class PayPalController extends ClientApiController
{
    private SettingsRepositoryInterface $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        parent::__construct();

        $this->settings = $settings;
    }

    /**
     * Constructs the PayPal order request and redirects
     * the user over to PayPal for credits purchase.
     * 
     * @throws DisplayException
     */
    public function purchase(PayPalRequest $request): JsonResponse
    {
        if ($this->settings->get('jexactyl::store:paypal:enabled') != 'true') {
            throw new DisplayException('Unable to purchase via PayPal: module not enabled');
        };

        $client = $this->createClient();
        $amount = $request->input('amount');
        $cost = config('gateways.paypal.cost', 1) / 100 * $amount; // Calculate the cost of credits.
        $currency = config('gateways.currency', 'USD');

        $order = new OrdersCreateRequest();
        $order->prefer('return=representation');

        $order->body = [
            "intent" => "CAPTURE",
            "purchase_units" => [
                [
                    "reference_id" => uniqid(),
                    "description" => $amount.' Credits | '.$this->settings->get('settings::app:name'),
                    "amount" => [
                        "value" => $cost,
                        'currency_code' => strtoupper($currency),
                        'breakdown' => [
                            'item_total' => ['currency_code' => strtoupper($currency), 'value' => $cost]
                        ]
                    ]
                ]
            ],
            "application_context" => [
                "cancel_url" => route('api.client.store.paypal.cancel'),
                "return_url" => route('api.client.store.paypal.success'),
                'brand_name' => $this->settings->get('settings::app:name'),
                'shipping_preference'  => 'NO_SHIPPING'
            ]
        ];

        try {
            $response = $client->execute($order);
            return new JsonResponse($response->result->links[1]->href, 200, [], null, true);
        } catch (DisplayException $ex) {
            throw new DisplayException('Unable to process order.');
        }
    }
    
    /**
     * Add balance to a user when the purchase is successful.
     * 
     * @throws DisplayException
     */
    public function success(PayPalRequest $request): RedirectResponse
    {
        $client = $this->getClient();
        $amount = $request->input('amount');

        try {
            $order = new OrdersCaptureRequest($request->input('token'));
            $order->prefer('return=representation');

            $res = $client->execute($req);
    
            if ($res->statusCode == 200 | 201) {
                $request->user()->update([
                    'store_balance' => $request->user()->store_balance + $amount,
                ]);
            };

            return redirect()->route('api.client.store.paypal.success');

        } catch (DisplayException $ex) {
            throw new DisplayException('Unable to process order.');
        };
    }

    /**
     * Callback for when a payment is cancelled.
     */
    public function cancel(): RedirectResponse
    {
        return redirect()->route('api.client.store.paypal.cancel');
    }

    /**
     * Returns a PayPal client which can be used
     * for processing orders via the API.
     * 
     * @throws DisplayException
     */
    protected function getClient(): PayPalHttpClient
    {
        $environment = new ProductionEnvironment(
            config('gateways.paypal.client_id'),
            config('gateways.paypal.client_secret')
        );

        return new PayPalHttpClient($environment);
    }
}