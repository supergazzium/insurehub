<!doctype html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #0f172a; max-width: 560px; margin: 24px auto; padding: 24px; background: #f8fafc; border-radius: 12px;">
  <h1 style="color: #0e74e8; margin: 0 0 12px;">Welcome to InsureHub, {{ $agentName ?: 'agent' }}</h1>
  <p>Your application has been <strong>approved</strong>. You can now sign in and access your agent portal.</p>

  <p><strong>Your agent code:</strong> <code style="background:#e0f2fe; padding:2px 8px; border-radius:4px;">{{ $agentCode }}</code></p>

  <p style="margin: 24px 0;">
    <a href="{{ $loginUrl }}" style="background:#0e74e8; color:white; text-decoration:none; padding:10px 20px; border-radius:8px; display:inline-block;">Sign in to your portal</a>
  </p>

  <p style="font-size: 13px; color: #64748b;">
    Next steps: complete your profile, upload your ID card and bank book, and generate your personal referral link.
  </p>

  <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0;" />
  <p style="font-size: 12px; color: #94a3b8;">InsureHub Broker — <a href="mailto:ops@insurehub.co.th" style="color:#94a3b8;">ops@insurehub.co.th</a></p>
</body>
</html>
