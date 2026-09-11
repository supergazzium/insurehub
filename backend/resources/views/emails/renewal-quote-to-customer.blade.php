<!doctype html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'IBM Plex Sans Thai', sans-serif; color: #0f172a; max-width: 600px; margin: 24px auto; padding: 24px; background: #f8fafc; border-radius: 12px;">
  <h1 style="color: #0e74e8; margin: 0 0 12px; font-size: 20px;">ใบเสนอราคาต่ออายุกรมธรรม์</h1>

  <p>เรียน คุณ{{ $customerName ?: 'ลูกค้า' }},</p>

  @if ($message)
    <div style="white-space:pre-wrap; margin:12px 0;">{{ $message }}</div>
  @else
    <p>InsureHub ได้จัดทำใบเสนอราคาสำหรับการต่ออายุกรมธรรม์ของท่านเรียบร้อยแล้ว รายละเอียดตามเอกสารแนบ</p>
  @endif

  <div style="background: white; border: 1px solid #e2e8f0; padding: 16px; border-radius: 8px; margin: 20px 0; font-size: 13px;">
    <div style="margin-bottom:6px;"><strong>เลขกรมธรรม์:</strong>
      <code style="background:#f1f5f9;padding:2px 6px;border-radius:3px;">{{ $policy->policy_no ?: $policy->application_no ?: ('#'.$policy->id) }}</code></div>
    <div style="margin-bottom:6px;"><strong>ความคุ้มครองเดิมถึง:</strong> {{ $policy->expiry_date?->toDateString() ?: '—' }}</div>
    <div><strong>แบบประกัน:</strong> {{ $policy->product?->name ?: $policy->product?->code ?: '—' }}</div>
  </div>

  <p style="font-size: 13px; color: #64748b;">
    กรุณาตรวจสอบใบเสนอราคาที่แนบมา หากมีข้อสงสัยหรือต้องการยืนยันการต่ออายุ กรุณาติดต่อกลับได้ที่อีเมลนี้
  </p>

  <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0;" />
  <p style="font-size: 12px; color: #94a3b8;">InsureHub Broker — <a href="mailto:ops@insurehub.co.th" style="color:#94a3b8;">ops@insurehub.co.th</a></p>
</body>
</html>
