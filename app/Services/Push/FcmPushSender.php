<?php

namespace App\Services\Push;

use App\Contracts\PushSender;
use Closure;
use Illuminate\Http\Client\Response;
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
    public function send(
        array $tokens,
        string $title,
        string $body,
        array $data = [],
        ?string $collapseKey = null,
    ): array {
        return $this->deliver(
            $tokens,
            fn (string $token) => $this->notificationMessage($token, $title, $body, $data, $collapseKey),
        );
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, string>  $data
     * @return list<string>
     */
    public function sendData(
        array $tokens,
        array $data,
        ?string $collapseKey = null,
        ?int $ttlSeconds = null,
    ): array {
        return $this->deliver(
            $tokens,
            fn (string $token) => $this->dataMessage($token, $data, $collapseKey, $ttlSeconds),
        );
    }

    /**
     * Post one message per token, mint a fresh access token if Google refuses
     * the one we have, and collect the tokens worth deleting.
     *
     * Shared by both kinds of message because none of it is about the message
     * — it is about the transport, and the transport fails the same way
     * whether the payload draws a notification or wakes the app.
     *
     * @param  list<string>  $tokens
     * @param  Closure(string): array<string, mixed>  $message
     * @return list<string>
     */
    private function deliver(array $tokens, Closure $message): array
    {
        if ($tokens === []) {
            return [];
        }

        $project = config('push.fcm.project_id');
        $url = "https://fcm.googleapis.com/v1/projects/{$project}/messages:send";

        $accessToken = $this->accessToken();
        $dead = [];

        /*
         * A rotated or revoked service account key is only discovered when
         * Google refuses one of these. Minted once more on the first 401, and
         * only once: a key that is genuinely gone would otherwise mint a token
         * per device on every send for as long as nobody noticed.
         */
        $refreshed = false;

        /*
         * One request per token. FCM v1 has no multicast — the batch endpoint
         * was part of the legacy API — so this is the shape whether we like it
         * or not. It is why sending is queued.
         */
        foreach ($tokens as $token) {
            $response = $this->post($url, $accessToken, $message($token));

            /*
             * 401 is about us, not the device. The cached token has an hour on
             * it and is reused for fifty minutes, so without this a key
             * rotated at six o'clock silently drops every notification until
             * ten to seven.
             */
            if ($response->status() === 401 && ! $refreshed) {
                Log::warning('FCM rejected the access token; minting a new one.');

                Cache::forget('push.fcm.token');

                $refreshed = true;
                $accessToken = $this->accessToken();

                $response = $this->post($url, $accessToken, $message($token));
            }

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

    /** @param  array<string, mixed>  $message */
    private function post(string $url, string $accessToken, array $message): Response
    {
        return Http::withToken($accessToken)
            ->timeout((int) config('push.timeout_seconds'))
            ->post($url, ['message' => $message]);
    }

    /**
     * Something for the operating system to draw.
     *
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    private function notificationMessage(
        string $token,
        string $title,
        string $body,
        array $data,
        ?string $collapseKey,
    ): array {
        return [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => $this->strings($data),
            'android' => [
                // A rider needs the sound and the wake. A delivery
                // offer arriving silently is an offer not seen.
                'priority' => 'high',
                'notification' => array_filter([
                    'channel_id' => config('push.android_channel'),
                    /*
                     * One live notification per order rather than a
                     * pile of five. Android replaces a notification
                     * carrying a tag it already has, and alerts again
                     * while doing it, so each stage is still felt.
                     */
                    'tag' => $collapseKey,
                ], fn ($value) => $value !== null),
            ],
            /*
             * iOS takes the sound per message; Android takes it from
             * the channel, which is why only this side needs naming.
             * A file the build does not bundle plays nothing at all,
             * so this stays 'default' until an iOS release is
             * actually carrying the asset.
             */
            'apns' => [
                'headers' => array_filter([
                    'apns-priority' => '10',
                    // iOS calls the same idea a collapse id, and caps
                    // it at 64 bytes.
                    'apns-collapse-id' => $this->collapseHeader($collapseKey),
                ], fn ($value) => $value !== null),
                'payload' => ['aps' => ['sound' => config('push.ios_sound')]],
            ],
        ];
    }

    /**
     * Something for the app to act on.
     *
     * No `notification` key at all, which is what makes it data-only: FCM
     * decides by presence, and including an empty one would have Android draw
     * a blank tray entry and never call the app.
     *
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    private function dataMessage(
        string $token,
        array $data,
        ?string $collapseKey,
        ?int $ttlSeconds,
    ): array {
        return [
            'token' => $token,
            'data' => $this->strings($data),
            'android' => array_filter([
                /*
                 * The thing that makes a ringing card possible. A normal
                 * priority data message is held until the phone next wakes
                 * on its own, which on a locked phone in a pocket can be
                 * minutes — long after the offer expired.
                 */
                'priority' => 'high',

                /*
                 * Delivered to an app that has not been opened since the
                 * phone was switched on. A rider who reboots at the start of
                 * a shift and does not open the app would otherwise receive
                 * nothing at all.
                 */
                'direct_boot_ok' => true,

                'ttl' => $ttlSeconds === null ? null : $ttlSeconds.'s',
            ], fn ($value) => $value !== null),

            /*
             * iOS will not ring for this. A background push is throttled by
             * the system and may be held for minutes, so a data message is
             * the wrong tool for an offer on an iPhone — that needs a
             * notification with a critical alert entitlement, or the
             * call-style interface CallKit provides. The rider app ships on
             * Android, so this side exists to be correct rather than to be
             * used: `apns-push-type: background` with priority 5 is what
             * Apple requires for a payload with no alert, and priority 10
             * here would be rejected outright.
             */
            'apns' => [
                'headers' => array_filter([
                    'apns-push-type' => 'background',
                    'apns-priority' => '5',
                    'apns-expiration' => $ttlSeconds === null ? null : (string) (time() + $ttlSeconds),
                    'apns-collapse-id' => $this->collapseHeader($collapseKey),
                ], fn ($value) => $value !== null),
                'payload' => ['aps' => ['content-available' => 1]],
            ],
        ];
    }

    private function collapseHeader(?string $collapseKey): ?string
    {
        return $collapseKey === null ? null : substr($collapseKey, 0, 64);
    }

    /**
     * Strings only: FCM rejects a data payload with any other scalar type,
     * and a silent 400 is worse than a loud one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function strings(array $data): array
    {
        return array_map(fn ($value) => (string) $value, $data);
    }

    /**
     * A short-lived OAuth token, minted from the service account.
     *
     * Cached: it lasts an hour, and minting one per notification would double
     * every send and hit Google's rate limits on a busy evening.
     */
    protected function accessToken(): string
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
