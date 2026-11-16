<?php

namespace App\Http\Middleware\Api;

use App\Support\ApiError;
use App\Support\SubscriptionGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Primera capa en la API: la misma decisión que la web, respondida en JSON. */
class EnsureSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        return match (SubscriptionGate::decide($request->user(), $request->attributes->get('tenant_company'), $request)) {
            SubscriptionGate::BLOCK => ApiError::subscriptionInactive(),
            SubscriptionGate::READONLY => ApiError::subscriptionReadonly(),
            default => $next($request),
        };
    }
}
