<?php

namespace Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-099: delivery_email notification only — not association authority.
 */
class WorkforceSsoEnrollmentInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public string $inviterName,
        public string $enrollmentUrl,
        public \DateTimeInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Link Enterprise SSO for '.$this->tenant->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'identity::mail.workforce-sso-enrollment-invitation',
        );
    }
}
