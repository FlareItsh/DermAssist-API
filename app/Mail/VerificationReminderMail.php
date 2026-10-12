<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $verificationUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public User $user,
        public string $hoursRemainingText
    ) {
        $frontendUrl = rtrim((string) (config('app.frontend_url') ?? env('FRONTEND_URL', 'http://localhost:3000')), '/');
        $this->verificationUrl = "{$frontendUrl}/auth/verify?token=".urlencode((string) $this->user->verification_token);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reminder: Verify Your DermAssist Account ('.$this->hoursRemainingText.' remaining)',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.verification-reminder',
            with: [
                'user' => $this->user,
                'url' => $this->verificationUrl,
                'hoursRemainingText' => $this->hoursRemainingText,
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
