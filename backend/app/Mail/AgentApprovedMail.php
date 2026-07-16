<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AgentApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Agent $agent,
        public readonly string $loginUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'InsureHub — Your agent application has been approved');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.agent-approved',
            with: [
                'agentName' => trim(($this->agent->first_name ?? '').' '.($this->agent->last_name ?? '')),
                'agentCode' => $this->agent->agent_code,
                'loginUrl' => $this->loginUrl,
            ],
        );
    }
}
