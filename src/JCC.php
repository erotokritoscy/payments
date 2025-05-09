<?php

namespace Erotokritoscy\Payments;

use Erotokritoscy\Payments\Models\JccTransaction;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JCC
{
    /**
     * @throws Exception
     */
    public static function formUrl(float $amount, string $returnUrl, ?string $failUrl, ?string $callbackUrl, array $additionalParams = []): string
    {
        $amount      = number_format($amount, 2, '', '');
        $orderNumber = time() . rand(1000, 9999);

        $url = config('jcc.order_form_url');

        if (!config('jcc.development')) {
            $url = str_replace('-test', '', $url);
        }

        $response = Http::asForm()->post($url, [
            'userName'           => config('jcc.username'),
            'password'           => config('jcc.password'),
            'orderNumber'        => $orderNumber,
            'amount'             => $amount,
            'currency'           => config('jcc.currencyCode'),
            'returnUrl'          => $returnUrl,
            'failUrl'            => $failUrl,
            'dynamicCallbackUrl' => $callbackUrl,
            'jsonParams'         => $additionalParams !== [] ? json_encode($additionalParams) : null,
        ]);

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
        $transaction->save();

        return $data['formUrl'];
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
