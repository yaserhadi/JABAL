<?php

namespace Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Tenancy\Models\Tenant;

class TenantInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public string $inviterName,
        public string $inviteeEmail,
        public string $role,
        public string $acceptUrl,
        public \DateTimeInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You\'re invited to join '.$this->tenant->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'identity::mail.tenant-invitation',
        );
    }
}
