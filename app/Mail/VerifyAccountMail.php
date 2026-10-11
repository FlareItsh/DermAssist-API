<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyAccountMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $verificationUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public User $user,
        public string $verificationToken
    ) {
        $frontendUrl = rtrim((string) (config('app.frontend_url') ?? env('FRONTEND_URL', 'http://localhost:3000')), '/');
        $this->verificationUrl = "{$frontendUrl}/auth/verify?token=".urlencode($this->verificationToken);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify Email Address',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.verify-account',
            with: [
                'user' => $this->user,
                'url' => $this->verificationUrl,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
