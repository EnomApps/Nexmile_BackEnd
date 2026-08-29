<?php

namespace App\Contracts;

interface PushSender
{
    /**
     * Deliver one notification to a set of device tokens.
     *
     * @param  list<string>  $tokens
     * @param  array<string, string>  $data  key/value payload the app routes on
     * @return list<string> tokens the provider rejected as dead, to be pruned
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array;
}
