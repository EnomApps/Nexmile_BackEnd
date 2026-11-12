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
}
