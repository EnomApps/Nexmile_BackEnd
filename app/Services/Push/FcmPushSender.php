<?php

namespace App\Services\Push;

use App\Contracts\PushSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging, over HTTP v1.
 *
 * No vendor SDK. The whole integration is an OAuth token and one POST per
 * device, and the SDK would pull in a dependency tree to save about thirty
 * lines — the same call made for Razorpay.
 *
 * HTTP v1 rather than the legacy server key: Google has shut the legacy
 * endpoint down, and v1 is the only one that will still be there.
 */
class FcmPushSender implements PushSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * @param  list<string>  $tokens
     * @param  array<string, string>  $data
     * @return list<string>
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        if ($tokens === []) {
            return [];
        }

        $project = config('push.fcm.project_id');
        $url = "https://fcm.googleapis.com/v1/projects/{$project}/messages:send";

        $accessToken = $this->accessToken();
        $dead = [];

        /*
         * One request per token. FCM v1 has no multicast — the batch endpoint
         * was part of the legacy API — so this is the shape whether we like it
         * or not. It is why sending is queued.
         */
        foreach ($tokens as $token) {
            $response = Http::withToken($accessToken)
                ->timeout((int) config('push.timeout_seconds'))
                ->post($url, [
                    'message' => [
                        'token' => $token,
                        'notification' => ['title' => $title, 'body' => $body],
                        // Strings only: FCM rejects a data payload with any
                        // other scalar type, and a silent 400 is worse than a
                        // loud one.
                        'data' => array_map(fn ($v) => (string) $v, $data),
                        'android' => [
                            // A rider needs the sound and the wake. A delivery
                            // offer arriving silently is an offer not seen.
                            'priority' => 'high',
                            'notification' => ['channel_id' => config('push.android_channel')],
                        ],
                        'apns' => [
                            'headers' => ['apns-priority' => '10'],
                            'payload' => ['aps' => ['sound' => 'default']],
                        ],
                    ],
                ]);

            if ($response->successful()) {
                continue;
            }

            /*
             * 404 UNREGISTERED and 400 INVALID_ARGUMENT on the token mean the
             * install is gone. Anything else is our problem, not the device's,
             * and must not delete a working token.
             */
            $status = $response->json('error.status');

            if ($response->status() === 404 || $status === 'UNREGISTERED' || $status === 'INVALID_ARGUMENT') {
                $dead[] = $token;

                continue;
            }

            Log::warning('FCM refused a message', [
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);
        }

        return $dead;
    }

    /**
     * A short-lived OAuth token, minted from the service account.
     *
     * Cached: it lasts an hour, and minting one per notification would double
     * every send and hit Google's rate limits on a busy evening.
     */
    private function accessToken(): string
    {
        return Cache::remember('push.fcm.token', now()->addMinutes(50), function () {
            $credentials = $this->credentials();

            $now = time();

            $jwt = $this->sign([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key']);

            $response = Http::asForm()
                ->timeout((int) config('push.timeout_seconds'))
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Could not get an FCM access token: '.$response->body());
            }

            return (string) $response->json('access_token');
        });
    }

    /** @return array{client_email: string, private_key: string} */
    private function credentials(): array
    {
        $path = config('push.fcm.credentials');

        if ($path === null || ! is_file($path)) {
            throw new RuntimeException('FCM credentials file not found. Set FCM_CREDENTIALS to the service account JSON.');
        }

        $json = json_decode((string) file_get_contents($path), true);

        if (! isset($json['client_email'], $json['private_key'])) {
            throw new RuntimeException('FCM credentials file is not a service account key.');
        }

        return ['client_email' => $json['client_email'], 'private_key' => $json['private_key']];
    }

    /** @param  array<string, mixed>  $claims */
    private function sign(array $claims, string $privateKey): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

        $payload = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($claims);

        openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return $payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
