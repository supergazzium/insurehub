<!doctype html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'IBM Plex Sans Thai', sans-serif; color: #0f172a; max-width: 600px; margin: 24px auto; padding: 24px; background: #f8fafc; border-radius: 12px;">
  <h1 style="color: #0e74e8; margin: 0 0 12px; font-size: 20px;">
    ขอใบเสนอราคาต่ออายุกรมธรรม์
  </h1>
  <p style="color:#64748b; margin:0 0 20px; font-size:13px;">Renewal quotation request</p>

  @if ($toCarrier)
    <p>เรียน {{ $carrierName ?: 'บริษัทประกัน' }},</p>
    <p>InsureHub Broker ขอความอนุเคราะห์ใบเสนอราคาสำหรับการต่ออายุกรมธรรม์ของลูกค้าดังรายละเอียดด้านล่างนี้ สำหรับระยะเวลาความคุ้มครองปีถัดไป</p>
  @else
    <p>เรียน {{ $agentName ?: 'ตัวแทน' }},</p>
    <p>กรุณาติดต่อบริษัทประกันเพื่อขอใบเสนอราคาต่ออายุกรมธรรม์ของลูกค้ารายนี้ ตามรายละเอียดด้านล่าง</p>
  @endif

  @if ($message)
    <div style="background:#eff6ff; border-left:3px solid #0e74e8; padding:12px 16px; border-radius:6px; margin:16px 0; font-size:13px; white-space:pre-wrap;">{{ $message }}</div>
  @endif

  <div style="background: white; border: 1px solid #e2e8f0; padding: 16px; border-radius: 8px; margin: 20px 0; font-size: 13px;">
    <div style="margin-bottom:6px;"><strong>ลูกค้า / Customer:</strong> {{ $customerName ?: '—' }}</div>
    <div style="margin-bottom:6px;"><strong>เลขกรมธรรม์ / Policy no.:</strong>
      <code style="background:#f1f5f9;padding:2px 6px;border-radius:3px;">{{ $policy->policy_no ?: $policy->application_no ?: ('#'.$policy->id) }}</code></div>
    <div style="margin-bottom:6px;"><strong>แบบประกัน / Product:</strong> {{ $policy->product?->name ?: $policy->product?->code ?: '—' }}</div>
    <div style="margin-bottom:6px;"><strong>บริษัทประกัน / Carrier:</strong> {{ $carrierName ?: '—' }}</div>
    <div style="margin-bottom:6px;"><strong>ความคุ้มครองถึง / Coverage to:</strong> {{ $policy->expiry_date?->toDateString() ?: '—' }}</div>
    <div><strong>เบี้ยปัจจุบัน / Current premium:</strong> ฿ {{ number_format((float)($policy->annual_premium ?? 0), 2) }}</div>
  </div>

  <p style="font-size: 13px; color: #64748b;">
    กรุณาส่งใบเสนอราคากลับมายังอีเมลนี้ หรือติดต่อทีมงาน InsureHub
  </p>

  <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0;" />
  <p style="font-size: 12px; color: #94a3b8;">InsureHub Broker — <a href="mailto:ops@insurehub.co.th" style="color:#94a3b8;">ops@insurehub.co.th</a></p>
</body>
</html>
