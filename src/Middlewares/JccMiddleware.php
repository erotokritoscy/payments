<?php

namespace Erotokritoscy\Payments\Middlewares;

use Erotokritoscy\Payments\JCC;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Support\Facades\Log;

class JccMiddleware extends Middleware
{
    protected function redirectTo($request)
    {
        if (!JCC::verifyCallback()) {
            return '/';
        }
    }
}
