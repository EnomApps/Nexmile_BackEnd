<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // The code is in the subject so it is readable from a notification
            // without opening the message.
            subject: "{$this->code} is your Nexmile verification code",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp');
    }
}
