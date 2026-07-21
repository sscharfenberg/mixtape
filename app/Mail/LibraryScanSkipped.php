<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * End-of-run summary of files the scan could not read (and why), sent to
 * `config('mixtape.scan.alert_email')`. Skipped files are non-fatal — the scan
 * completes — but they must never be silent, so the owner gets the list plus
 * getID3's reason for each, enough to find and fix the file.
 */
class LibraryScanSkipped extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{path: string, reason: string}>  $skipped  (already capped for display)
     * @param  int  $total  the true total (may exceed the shown list)
     */
    public function __construct(
        public array $skipped,
        public int $total,
        public string $host,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.config('app.name').'] Library scan: '.$this->total.' file(s) skipped on '.$this->host,
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.scan-skipped');
    }
}
