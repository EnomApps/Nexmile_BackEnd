<?php

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\NullSmsSender;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swap SMS_DRIVER to add a real gateway; nothing else changes.
        $this->app->bind(SmsSender::class, function () {
            return match (config('sms.driver')) {
                'null' => new NullSmsSender,
                default => new LogSmsSender,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerApiDocs();
    }

    /**
     * One OpenAPI document per app, alongside the combined one at /docs/api.
     *
     * An app developer reading a single 94-endpoint document has to work out
     * which half belongs to them, and the answer is not obvious — `/profile`
     * is shared, `/auth` is shared, and `/orders` means something different to
     * a customer and a rider. Splitting them is the same reasoning as the
     * three Postman collections.
     */
    private function registerApiDocs(): void
    {
        /*
         * Shared by customer and rider: both sign in the same way and both
         * edit the same profile. A merchant does neither — they use
         * /merchant/login with a password.
         */
        $shared = ['api/v1/auth', 'api/v1/profile'];

        $apis = [
            'customer' => [
                'title' => 'Nexmile — Customer app',
                'description' => "Everything the customer app needs.\n\n".
                    "Sign-up and sign-in are the same two calls — there is no separate registration endpoint, and the account is created on first successful verification.\n\n".
                    '**Money is a JSON number** and loses its zero fraction: ₹430.00 arrives as `430`. Read it as `num`, never `double`.',
                'prefixes' => [...$shared, 'api/v1/addresses', 'api/v1/restaurants', 'api/v1/carts', 'api/v1/orders'],
            ],
            'rider' => [
                'title' => 'Nexmile — Rider app',
                'description' => "Everything the rider app needs.\n\n".
                    "Auth is identical to the customer app except `intended_role: \"rider\"`. The account comes back `pending` and stays that way until an admin approves the documents, so onboarding is a wizard rather than a single screen.\n\n".
                    '**There are no push notifications yet** — poll `/rider/orders/available` while the board is on screen.',
                'prefixes' => [...$shared, 'api/v1/rider'],
            ],
            'merchant' => [
                'title' => 'Nexmile — Merchant',
                'description' => "Everything a merchant can do over the API.\n\n".
                    "Merchants sign in with a **password**, not an OTP. Registration happens on the website at nexmile.in/merchants/register.\n\n".
                    '**Menu item updates are POST, not PATCH** — PHP does not parse multipart bodies on PATCH, so a PATCH upload arrives with an empty `$_FILES`.',
                'prefixes' => ['api/v1/merchant'],
            ],
        ];

        foreach ($apis as $name => $api) {
            Scramble::registerApi($name, [
                'info' => [
                    'version' => config('scramble.info.version', '1.0.0'),
                    'description' => $api['description'],
                ],
            ])
                ->routes(fn (Route $route) => Str::startsWith($route->uri(), $api['prefixes']))
                ->afterOpenApiGenerated(function (OpenApi $document) use ($api) {
                    $document->info->title = $api['title'];
                });
        }
    }
}
