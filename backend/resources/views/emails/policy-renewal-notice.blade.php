<!doctype html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #0f172a; max-width: 560px; margin: 24px auto; padding: 24px; background: #f8fafc; border-radius: 12px;">
  <h1 style="color: #0e74e8; margin: 0 0 12px;">
    @if ($sentToAgent) Renewal reminder for your customer @else Your policy is due for renewal @endif
  </h1>

  @if ($sentToAgent)
    <p>Hello {{ $agentName ?: 'agent' }},</p>
    <p>One of your customers has a policy coming up for renewal. Please reach out to arrange the next term.</p>
    <p><strong>Customer:</strong> {{ $customerName ?: '(no name on file)' }}</p>
  @else
    <p>Dear {{ $customerName ?: 'valued customer' }},</p>
    <p>Your insurance policy is approaching its expiration date. To ensure continuous coverage, please contact us to arrange your renewal.</p>
  @endif

  <div style="background: white; border: 1px solid #e2e8f0; padding: 16px; border-radius: 8px; margin: 20px 0; font-size: 13px;">
    <div style="margin-bottom:6px;"><strong>Policy no.:</strong> <code style="background:#f1f5f9;padding:2px 6px;border-radius:3px;">{{ $policy->policy_no ?: $policy->application_no ?: ('#'.$policy->id) }}</code></div>
    <div style="margin-bottom:6px;"><strong>Coverage from:</strong> {{ $policy->effective_date?->toDateString() ?: '—' }}</div>
    <div style="margin-bottom:6px;"><strong>Coverage to:</strong> {{ $policy->expiry_date?->toDateString() ?: '—' }}</div>
    <div><strong>Premium:</strong> ฿ {{ number_format((float)($policy->annual_premium ?? 0), 2) }}</div>
  </div>

  @if (! $sentToAgent)
    <p style="font-size: 13px; color: #64748b;">
      To renew, please contact your agent{{ $agentName ? " ({$agentName})" : '' }} or reply to this email.
    </p>
  @endif

  <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0;" />
  <p style="font-size: 12px; color: #94a3b8;">InsureHub Broker — <a href="mailto:ops@insurehub.co.th" style="color:#94a3b8;">ops@insurehub.co.th</a></p>
</body>
</html>
