<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAbaSimulatorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            ! app()->environment('local', 'testing')
            || config('services.aba_payway.mode') !== 'mock'
        ) {
            abort(404);
        }

        return $next($request);
    }
}
