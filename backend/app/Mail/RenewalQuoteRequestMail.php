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
 * Sent when an operator requests a next-term renewal quotation from the
 * renewal pipeline (`/policies/expiring`). The recipient is either the
 * insurance carrier (asking them to quote the next term) or the writing
 * agent (relaying the request internally) — chosen per-send by the operator.
 *
 * Carries the current policy's key figures so the insurer has enough to
 * produce a quote. The operator can override the free-text message body.
 */
class RenewalQuoteRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Policy  $policy  the expiring policy a quote is wanted for
     * @param  string  $recipient  'carrier' | 'agent' — who this is addressed to
     * @param  string|null  $message  operator's custom note (falls back to a default)
     * @param  string|null  $subjectOverride  operator's custom subject (falls back to the default)
     */
    public function __construct(
        public readonly Policy $policy,
        public readonly string $recipient,
        public readonly ?string $message = null,
        public readonly ?string $subjectOverride = null,
    ) {}

    public function envelope(): Envelope
    {
        $ref = $this->policy->policy_no ?: $this->policy->application_no ?: ('#'.$this->policy->id);
        $subject = ($this->subjectOverride !== null && trim($this->subjectOverride) !== '')
            ? $this->subjectOverride
            : "InsureHub — ขอใบเสนอราคาต่ออายุ (Renewal quotation request) · {$ref}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $customerName = trim(
            ($this->policy->customer?->first_name ?? '').' '.($this->policy->customer?->last_name ?? '')
        );
        if ($customerName === '') {
            $customerName = $this->policy->customer?->juristic_name ?? '';
        }

        return new Content(
            view: 'emails.renewal-quote-request',
            with: [
                'policy' => $this->policy,
                'recipient' => $this->recipient,
                'toCarrier' => $this->recipient === 'carrier',
                'customerName' => $customerName,
                'carrierName' => $this->policy->carrier?->name
                    ?? $this->policy->carrier?->code
                    ?? '',
                'agentName' => trim(
                    ($this->policy->writingAgent?->first_name ?? '').' '.($this->policy->writingAgent?->last_name ?? '')
                ),
                'message' => $this->message,
            ],
        );
    }
}
