<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to `config('mixtape.scan.alert_email')` when `app:update` aborts on a
 * fatal error, so a broken nightly scan doesn't fail silently. Plain-text only;
 * the full detail is in `storage/logs/library.log` on the box.
 */
class LibraryScanFailed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $summary,
        public string $exceptionClass,
        public string $location,
        public string $host,
        public string $trace,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.config('app.name').'] Library scan failed on '.$this->host,
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.scan-failed');
    }
}
