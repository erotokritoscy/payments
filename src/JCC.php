<?php

namespace Erotokritoscy\Payments;

use Erotokritoscy\Payments\Models\JccTransaction;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JCC
{
    /**
     * @throws Exception
     */
    public static function formUrl(
        float $amount,
        string $returnUrl,
        ?string $failUrl,
        ?string $callbackUrl,
        array $additionalParams = [],
        ?string $clientId = null,
        bool $forceCardStorage = false
    ): string {
        $amount      = number_format($amount, 2, '', '');
        $orderNumber = time() . rand(1000, 9999);

        $url = config('jcc.order_form_url');

        if (!config('jcc.development')) {
            $url = str_replace('-test', '', $url);
        }

        $params = [
            'userName'           => config('jcc.username'),
            'password'           => config('jcc.password'),
            'orderNumber'        => $orderNumber,
            'amount'             => $amount,
            'currency'           => config('jcc.currencyCode'),
            'returnUrl'          => $returnUrl,
            'failUrl'            => $failUrl,
            'dynamicCallbackUrl' => $callbackUrl,
            'clientId'           => $clientId,
            'jsonParams'         => $additionalParams !== [] ? json_encode($additionalParams) : null,
        ];

        if ($clientId && $forceCardStorage) {
            $params['features'] = 'FORCE_CREATE_BINDING';
        }

        $response = Http::asForm()->post($url, array_filter($params));

        $data = $response->json();
        if (isset($data['errorCode'])) {
            Log::error('JCC Error: ' . $data['errorMessage']);
            throw new Exception('JCC Error');
        }

        $transaction                = new JccTransaction;
        $transaction->order_id      = $data['orderId'];
        $transaction->order_number  = $orderNumber;
        $transaction->amount        = $amount;
        $transaction->currency_code = config('jcc.currencyCode');
        $transaction->client_id     = $clientId;
        $transaction->save();

        return $data['formUrl'];
    }

    /**
     * @throws Exception
     */
    public static function chargeWithBinding(
        float $amount,
        string $mdOrder,
        string $clientId,
        ?string $tii = null,
    ) {
        $amount      = number_format($amount, 2, '', '');
        $orderNumber = time() . rand(1000, 9999);

        $url = config('jcc.order_binding_url');

        $originalTransaction = JccTransaction::where('order_id', $mdOrder)->first();
        $originalOrder       = self::getOrderStatus($mdOrder);

        if (!$originalTransaction) {
            Log::error('JCC Error: Original transaction not found for mdOrder ' . $mdOrder);
            throw new Exception('Original transaction not found');
        }

        if (!isset($originalOrder['bindingInfo']['bindingId'])) {
            Log::error('JCC Error: Binding ID not found for mdOrder ' . $mdOrder);
            throw new Exception('Binding ID not found');
        }

        $originalPaymentDate      = strtotime($originalTransaction->created_at);
        $originalPaymentNetRefNum = $originalTransaction->order_number;
        $bindingId                = $originalOrder['bindingInfo']['bindingId'];

        if (!config('jcc.development')) {
            $url = str_replace('-test', '', $url);
        }

        $response = Http::asForm()->post($url, [
            'userName'                 => config('jcc.username'),
            'password'                 => config('jcc.password'),
            'orderNumber'              => $orderNumber,
            'mdOrder'                  => $mdOrder,
            'bindingId'                => $bindingId,
            'tii'                      => $tii,
            'originalPaymentDate'      => $originalPaymentDate,
            'originalPaymentNetRefNum' => $originalPaymentNetRefNum,
        ]);

        $data = $response->json();
        if ($data['errorCode'] != 0) {
            Log::error('JCC Error: ' . $data['errorMessage']);
            throw new Exception('JCC Error');
        }

        $transaction                = new JccTransaction;
        $transaction->order_id      = $mdOrder;
        $transaction->order_number  = $orderNumber;
        $transaction->amount        = $amount;
        $transaction->currency_code = config('jcc.currencyCode');
        $transaction->client_id     = $clientId;
        $transaction->binding_id    = $bindingId;
        $transaction->status        = $data['errorCode'] != 0 ? JccTransaction::STATUS_FAIL : JccTransaction::STATUS_SUCCESS;
        $transaction->save();

        return $data;
    }

    public static function getOrderStatus($orderId)
    {
        $url = config('jcc.order_status_url');

        if (!config('jcc.development')) {
            $url = str_replace('-test', '', $url);
        }

        $response = Http::asForm()->post($url, [
            'userName' => config('jcc.username'),
            'password' => config('jcc.password'),
            'orderId'  => $orderId,
        ]);

        return $response->json();

    }

    public static function verifyCallback(): bool
    {
        $hashInput = '';
        $data      = request()->except(['checksum']);
        ksort($data);

        foreach ($data as $key => $value) {
            $hashInput .= $key . ';' . $value . ';';
        }

        $key  = config('jcc.callback_secret');
        $hmac = hash_hmac('sha256', $hashInput, $key);

        if (strtoupper($hmac) !== strtoupper(request()->checksum)) {
            Log::error('JCC Callback Error: HMAC mismatch');
            return false;
        }

        $transaction = JccTransaction::where('order_number', request()->orderNumber)->first();

        if (!$transaction) {
            Log::error('JCC Callback Error: Transaction not found');
            return false;
        }

        $transaction->status = match (true) {
            request()->operation === 'approved' && request()->status === "1" => JccTransaction::STATUS_SUCCESS,
            request()->operation === 'approved' && request()->status === "0" => JccTransaction::STATUS_FAIL,
            request()->operation === 'deposited' && request()->status === "1" => JccTransaction::STATUS_SUCCESS,
            request()->operation === 'deposited' && request()->status === "0" => JccTransaction::STATUS_FAIL,
            default => 1
        };

        $transaction->save();

        return $transaction->status === JccTransaction::STATUS_SUCCESS;
    }
}
