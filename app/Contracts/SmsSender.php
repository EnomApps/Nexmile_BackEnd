<?php

namespace App\Contracts;

interface SmsSender
{
    /**
     * Deliver a message to an Indian mobile number.
     *
     * @param  string  $phone  10 digits, no country code
     *
     * @throws \RuntimeException when the provider rejects the message
     */
    public function send(string $phone, string $message): void;
}
