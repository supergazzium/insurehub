<!doctype html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #0f172a; max-width: 560px; margin: 24px auto; padding: 24px; background: #f8fafc; border-radius: 12px;">
  <h1 style="color: #b45309; margin: 0 0 12px;">Update on your application</h1>
  <p>Hello {{ $agentName ?: 'there' }},</p>
  <p>Thank you for your interest in becoming an InsureHub agent. After reviewing your application, we are unable to approve it at this time.</p>

  @if ($note)
    <div style="background: #fff7ed; border: 1px solid #fdba74; padding: 12px 16px; border-radius: 8px; margin: 16px 0;">
      <div style="font-size: 12px; color: #b45309; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Reviewer note</div>
      <div style="color: #7c2d12; white-space: pre-wrap;">{{ $note }}</div>
    </div>
  @endif

  <p>If you believe this is an error or would like to reapply with updated information, please reply to this email.</p>

  <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0;" />
  <p style="font-size: 12px; color: #94a3b8;">InsureHub Broker — <a href="mailto:ops@insurehub.co.th" style="color:#94a3b8;">ops@insurehub.co.th</a></p>
</body>
</html>
