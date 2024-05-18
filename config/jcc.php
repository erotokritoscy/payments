<?php

return [
    'order_form_url'   => 'https://gateway-test.jcc.com.cy/payment/rest/register.do',
    'order_status_url' => 'https://gateway-test.jcc.com.cy/payment/rest/getOrderStatusExtended.do',
    'username'         => env('JCC_USERNAME'),
    'password'         => env('JCC_PASSWORD'),
    'currencyCode'     => env('JCC_CURRENCY_CODE', '978'),
    'callback_secret'  => env('JCC_CALLBACK_SECRET'),
];