<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AgentRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Agent $agent,
        public readonly ?string $note = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'InsureHub — Update on your agent application');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.agent-rejected',
            with: [
                'agentName' => trim(($this->agent->first_name ?? '').' '.($this->agent->last_name ?? '')),
                'note' => $this->note,
            ],
        );
    }
}
