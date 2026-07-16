<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Policy;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a customer (or their agent if the customer has no email) when
 * the admin/agent clicks "Send renewal notice" on /policies/expiring.
 */
class PolicyRenewalNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Policy $policy,
        public readonly bool $sentToAgent,
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = $this->sentToAgent
            ? 'InsureHub — Renewal reminder for your customer'
            : 'InsureHub — Your policy is due for renewal';
        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.policy-renewal-notice',
            with: [
                'policy' => $this->policy,
                'customerName' => trim(($this->policy->customer?->first_name ?? '').' '.($this->policy->customer?->last_name ?? '')),
                'agentName' => trim(($this->policy->writingAgent?->first_name ?? '').' '.($this->policy->writingAgent?->last_name ?? '')),
                'sentToAgent' => $this->sentToAgent,
            ],
        );
    }
}
