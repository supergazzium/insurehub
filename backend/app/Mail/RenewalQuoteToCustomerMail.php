<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Policy;
use App\Models\PolicyDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the InsureHub-branded renewal quotation to the customer (Phase E of
 * the renewal quotation pipeline). Attaches the generated
 * `renewal_quote_insurehub` PDF stored on the policy. The operator can
 * override the message body.
 */
class RenewalQuoteToCustomerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Policy $policy,
        public readonly PolicyDocument $quoteDoc,
        public readonly ?string $message = null,
    ) {}

    public function envelope(): Envelope
    {
        $ref = $this->policy->policy_no ?: $this->policy->application_no ?: ('#'.$this->policy->id);

        return new Envelope(
            subject: "InsureHub — ใบเสนอราคาต่ออายุกรมธรรม์ · {$ref}",
        );
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
            view: 'emails.renewal-quote-to-customer',
            with: [
                'policy' => $this->policy,
                'customerName' => $customerName,
                'message' => $this->message,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        // The generated quote lives on the private `local` disk. Attach it by
        // its stored path with the friendly original filename.
        if (empty($this->quoteDoc->file_path)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', $this->quoteDoc->file_path)
                ->as($this->quoteDoc->file_name ?: 'renewal-quotation.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
