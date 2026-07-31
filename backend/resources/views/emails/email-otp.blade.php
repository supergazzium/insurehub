<!doctype html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #0f172a; max-width: 560px; margin: 24px auto; padding: 24px; background: #f8fafc; border-radius: 12px;">
  <h1 style="color: #0e74e8; margin: 0 0 12px;">InsureHub — รหัสยืนยันอีเมล</h1>
  <p>ใช้รหัสด้านล่างเพื่อยืนยันอีเมลของคุณในการสมัครเป็นตัวแทน</p>

  <div style="text-align: center; margin: 24px 0;">
    <div style="display: inline-block; background: #0e74e8; color: white; font-size: 32px; letter-spacing: 8px; font-weight: 700; padding: 16px 32px; border-radius: 8px; font-family: 'Menlo', monospace;">{{ $code }}</div>
  </div>

  <p style="font-size: 14px; color: #64748b;">รหัสนี้จะหมดอายุใน <strong>{{ $ttlMinutes }} นาที</strong> หากคุณไม่ได้ร้องขอ กรุณาละเว้นอีเมลฉบับนี้</p>

  <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0;" />

  <p style="font-size: 14px; color: #64748b;">
    Use the code below to verify your email during agent registration. This code expires in <strong>{{ $ttlMinutes }} minutes</strong>. If you didn't request this, you can safely ignore this email.
  </p>

  <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0;" />
  <p style="font-size: 12px; color: #94a3b8;">InsureHub Broker — <a href="mailto:noreply@insurehub.co.th" style="color:#94a3b8;">noreply@insurehub.co.th</a></p>
</body>
</html>
