<?php

namespace App\Services\Push;

use App\Contracts\PushSender;
use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who gets told what.
 *
 * Sending is queued, always. FCM is one HTTP request per device, and a rider
 * board with eight riders on it would otherwise add eight round trips to the
 * moment a merchant taps "ready" — the one moment in the product where a
 * spinner costs food going cold.
 */
class PushService
{
    /** The customer app and the rider app are separate installs. */
    public const CUSTOMER = 'customer';

    public const RIDER = 'rider';

    public const MERCHANT = 'merchant';

    public function __construct(protected PushSender $sender) {}

    /**
     * Register a device, or move it to this account.
     *
     * A token is unique to an install, not to a person. Two people sharing a
     * phone, or one rider handing a device to the next shift, must not leave
     * the previous account receiving the new one's orders — so the token moves
     * rather than duplicating.
     */
    public function register(User $user, string $token, string $platform, string $app): DeviceToken
    {
        return DeviceToken::updateOrCreate(
            ['token_hash' => DeviceToken::hash($token)],
            [
                'user_id' => $user->id,
                'token' => $token,
                'platform' => $platform,
                'app' => $app,
                'last_used_at' => now(),
            ],
        );
    }

    /**
     * Forget a device. Called on sign-out, or the phone keeps buzzing for a
     * shift somebody else is working.
     */
    public function forget(string $token): void
    {
        DeviceToken::where('token_hash', DeviceToken::hash($token))->delete();
    }

    /**
     * Queue a notification to one person's installs of one app.
     *
     * @param  array<string, string>  $data
     */
    public function toUser(?User $user, string $app, string $title, string $body, array $data = []): void
    {
        if ($user === null) {
            return;
        }

        $this->toUsers([$user->id], $app, $title, $body, $data);
    }

    /**
     * Queue a notification to several people at once — the rider board.
     *
     * @param  list<int>|Collection<int, int>  $userIds
     * @param  array<string, string>  $data
     */
    public function toUsers(array|Collection $userIds, string $app, string $title, string $body, array $data = []): void
    {
        $ids = collect($userIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        SendPushNotification::dispatch($ids->all(), $app, $title, $body, $data);
    }

    /**
     * Do the sending. Called from the queued job, not from a request.
     *
     * @param  list<int>  $userIds
     * @param  array<string, string>  $data
     */
    public function deliver(array $userIds, string $app, string $title, string $body, array $data = []): void
    {
        $devices = DeviceToken::query()
            ->whereIn('user_id', $userIds)
            ->where('app', $app)
            ->get();

        if ($devices->isEmpty()) {
            return;
        }

        $dead = $this->sender->send($devices->pluck('token')->all(), $title, $body, $data);

        /*
         * FCM tells us which tokens are gone. Deleting them is the only thing
         * that stops the table filling with uninstalled apps and every send
         * getting slower.
         */
        if ($dead !== []) {
            DeviceToken::whereIn('token_hash', array_map(
                fn (string $token) => DeviceToken::hash($token),
                $dead,
            ))->delete();
        }

        $devices->whereNotIn('token', $dead)
            ->each(fn (DeviceToken $device) => $device->forceFill(['last_used_at' => now()])->save());
    }
}
