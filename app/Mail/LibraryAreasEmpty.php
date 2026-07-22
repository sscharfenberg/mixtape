<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to `config('mixtape.scan.alert_email')` when `app:update` finds a
 * configured area empty while the database still has rows for it. The scan
 * protected the data (no pruning) — but that almost always means a dropped mount
 * or a permissions problem, so it is escalated to an alert (and a non-zero exit).
 */
class LibraryAreasEmpty extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{area: string, rows: int}>  $areas
     */
    public function __construct(
        public array $areas,
        public string $host,
    ) {}

    /** The alert subject, tagged with the app name and the affected host. */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.config('app.name').'] Library scan: empty area(s) on '.$this->host,
        );
    }

    /** Render the plain-text body from the emails.scan-areas-empty view. */
    public function content(): Content
    {
        return new Content(text: 'emails.scan-areas-empty');
    }
}
