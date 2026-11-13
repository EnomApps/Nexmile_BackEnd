<?php

namespace App\Contracts;

interface PushSender
{
    /**
     * Deliver one notification to a set of device tokens.
     *
     * `$collapseKey` identifies the thing being notified about — an order —
     * so a later notification about it replaces the earlier one on the lock
     * screen instead of stacking beneath it. Without it a customer who leaves
     * their phone alone finds five entries for one delivery: accepted, ready,
     * rider assigned, picked up, delivered. With it they find one that has
     * kept up. Each stage still alerts; only the pile is avoided.
     *
     * Null means do not collapse: show this one on its own.
     *
     * @param  list<string>  $tokens
     * @param  array<string, string>  $data  key/value payload the app routes on
     * @return list<string> tokens the provider rejected as dead, to be pruned
     */
    public function send(
        array $tokens,
        string $title,
        string $body,
        array $data = [],
        ?string $collapseKey = null,
    ): array;

    /**
     * Deliver a payload with no notification attached, for the app to act on
     * itself.
     *
     * This is how a ringing order card gets to a rider. A notification
     * message is drawn by the operating system, which means it draws a line
     * of text in the tray — there is no notification shape that rings for
     * thirty seconds, counts down, and dismisses itself when another rider
     * gets there first. A data message wakes the app instead and lets it put
     * a full-screen card up, start the ringtone, and take it down again.
     *
     * It is also the only way to *withdraw* something already showing.
     *
     * `$ttlSeconds` is how long the message is still worth delivering. An
     * offer card that arrives after its window closed is not a late
     * notification, it is a wrong one: it would ring for food another rider
     * is already carrying. Past the TTL, FCM drops it rather than holding it
     * for a phone that is switched off.
     *
     * @param  list<string>  $tokens
     * @param  array<string, string>  $data
     * @return list<string> tokens the provider rejected as dead
     */
    public function sendData(
        array $tokens,
        array $data,
        ?string $collapseKey = null,
        ?int $ttlSeconds = null,
    ): array;
}
