<?php

namespace App\Services\Push;

use App\Contracts\PushSender;
use App\Jobs\SendDataPush;
use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who gets told what, and in which language.
 *
 * Sending is queued, always. FCM is one HTTP request per device, and a rider
 * board with eight riders on it would otherwise add eight round trips to the
 * moment a merchant taps "ready" — the one moment in the product where a
 * spinner costs food going cold.
 *
 * Callers pass a translation key, never finished text. Two reasons, and the
 * first is the one that matters: a notification is rendered long after the
 * request that caused it, inside a queue worker that has no idea whose phone
 * it is about. Rendering in the caller meant rendering in the worker's own
 * locale, which is English — so a rider who chose Tamil was sent English, and
 * the Tamil file sat there unused. The second is that one notification can go
 * to many people at once, and the rider board is exactly that: eight riders
 * who may not share a language.
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
     *
     * Scoped to the owner. On sign-out the caller is whoever registered it, so
     * nothing legitimate is lost — and without the scope any signed-in account
     * could silence another person's phone with a token it happened to learn.
     */
    public function forget(User $user, string $token): void
    {
        DeviceToken::where('user_id', $user->id)
            ->where('token_hash', DeviceToken::hash($token))
            ->delete();
    }

    /**
     * Queue a notification to one person's installs of one app.
     *
     * `$key` is a translation prefix — `push.customer.accepted` — holding
     * `.title` and `.body`. It is resolved per recipient, not here.
     *
     * @param  array<string, string|int>  $replacements
     * @param  array<string, string>  $data
     */
    public function toUser(?User $user, string $app, string $key, array $replacements = [], array $data = []): void
    {
        if ($user === null) {
            return;
        }

        $this->toUsers([$user->id], $app, $key, $replacements, $data);
    }

    /**
     * Queue a notification to several people at once — the rider board.
     *
     * @param  list<int>|Collection<int, int>  $userIds
     * @param  array<string, string|int>  $replacements
     * @param  array<string, string>  $data
     */
    public function toUsers(array|Collection $userIds, string $app, string $key, array $replacements = [], array $data = []): void
    {
        $ids = collect($userIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        SendPushNotification::dispatch($ids->all(), $app, $key, $replacements, $data);
    }

    /**
     * Queue a data-only payload — no notification, for the app to act on.
     *
     * This is the ringing order card, and its withdrawal. There is no
     * translation key because there is no text: the app draws the card from
     * the payload in whatever language it is already running in, which is the
     * one place localisation belongs to the client rather than to us.
     *
     * @param  list<int>|Collection<int, int>  $userIds
     * @param  array<string, string|int>  $data
     */
    public function dataToUsers(array|Collection $userIds, string $app, array $data, ?int $ttlSeconds = null): void
    {
        $ids = collect($userIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        SendDataPush::dispatch($ids->all(), $app, $data, $ttlSeconds);
    }

    /**
     * Do the sending for a data payload. Called from the queued job.
     *
     * No locale grouping here, and no \`with('user')\`: there is nothing to
     * translate, so every device gets the identical payload and this is one
     * query and one send per token rather than per language.
     *
     * @param  list<int>  $userIds
     * @param  array<string, string|int>  $data
     */
    public function deliverData(array $userIds, string $app, array $data, ?int $ttlSeconds = null): void
    {
        $devices = DeviceToken::query()
            ->whereIn('user_id', $userIds)
            ->where('app', $app)
            ->get();

        if ($devices->isEmpty()) {
            return;
        }

        $dead = $this->sender->sendData(
            $devices->pluck('token')->all(),
            $data,
            $this->collapseKey($data),
            $ttlSeconds,
        );

        $this->prune($devices, $dead);
    }

    /**
     * Do the sending. Called from the queued job, not from a request.
     *
     * @param  list<int>  $userIds
     * @param  array<string, string|int>  $replacements
     * @param  array<string, string>  $data
     */
    public function deliver(array $userIds, string $app, string $key, array $replacements = [], array $data = []): void
    {
        $devices = DeviceToken::query()
            // Loaded because the recipient's language decides the wording, and
            // one notification can reach people who do not share one.
            ->with('user:id,preferred_locale')
            ->whereIn('user_id', $userIds)
            ->where('app', $app)
            ->get();

        if ($devices->isEmpty()) {
            return;
        }

        $dead = [];

        /*
         * Grouped by language rather than rendered per device: eight riders on
         * a board are usually one or two languages, and this keeps it to one
         * translation lookup each instead of eight.
         */
        foreach ($devices->groupBy(fn (DeviceToken $device) => $this->localeFor($device)) as $locale => $group) {
            $dead = array_merge($dead, $this->sender->send(
                $group->pluck('token')->all(),
                __($key.'.title', $replacements, $locale),
                __($key.'.body', $replacements, $locale),
                $data,
                $this->collapseKey($data),
            ));
        }

        $this->prune($devices, $dead);
    }

    /**
     * Delete what the provider rejected, and stamp what it accepted.
     *
     * FCM tells us which tokens are gone. Deleting them is the only thing
     * that stops the table filling with uninstalled apps and every send
     * getting slower.
     *
     * @param  Collection<int, DeviceToken>  $devices
     * @param  list<string>  $dead
     */
    protected function prune(Collection $devices, array $dead): void
    {
        if ($dead !== []) {
            DeviceToken::whereIn('token_hash', array_map(
                fn (string $token) => DeviceToken::hash($token),
                $dead,
            ))->delete();
        }

        $devices->whereNotIn('token', $dead)
            ->each(fn (DeviceToken $device) => $device->forceFill(['last_used_at' => now()])->save());
    }

    /**
     * What this notification is about, so a later one replaces it.
     *
     * An order's stages arrive as separate notifications — accepted, ready,
     * rider assigned, picked up, delivered — and without this a customer who
     * leaves their phone alone finds five entries for one delivery. Keyed on
     * the order, so stages replace each other while two different orders stay
     * apart.
     *
     * Read off the data payload the app already routes on rather than passed
     * separately: a notification with no order behind it is a notification
     * with nothing to collapse against, and it shows on its own.
     *
     * @param  array<string, string>  $data
     */
    protected function collapseKey(array $data): ?string
    {
        $order = $data['order_id'] ?? null;

        return $order === null || $order === '' ? null : 'order-'.$order;
    }

    /**
     * The language to write in.
     *
     * Falls back to the app default rather than to nothing: a user row with a
     * blank locale must still get a readable notification, and __() with a
     * null locale would quietly use the worker's, which is how this was
     * broken in the first place.
     */
    protected function localeFor(DeviceToken $device): string
    {
        $locale = $device->user?->preferred_locale;

        return is_string($locale) && $locale !== ''
            ? $locale
            : (string) config('app.locale');
    }
}
