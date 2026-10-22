<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('otp-send', function (Request $request) {
            $destination = Str::lower(trim((string) $request->input('destination', '')));

            return [
                Limit::perMinute(3)->by('otp-send:ip:'.$request->ip()),
                Limit::perHour(5)->by('otp-send:destination:'.$destination),
            ];
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            $destination = Str::lower(trim((string) $request->input('destination', '')));

            return [
                Limit::perMinute(10)->by('otp-verify:ip:'.$request->ip()),
                Limit::perHour(10)->by('otp-verify:destination:'.$destination),
            ];
        });
    }
}
