<?php

namespace Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Tenancy\Models\Tenant;

class CanonicalEmailChangeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public string $proposedEmail,
        public string $verifyUrl,
        public \DateTimeInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirm your new email for '.$this->tenant->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Confirm your new email address for <strong>'
                .e($this->tenant->name)
                .'</strong>.</p><p><a href="'.e($this->verifyUrl).'">Verify email</a></p>'
                .'<p>This link expires at '.e($this->expiresAt->format(\DateTimeInterface::ATOM)).'.</p>',
        );
    }
}
