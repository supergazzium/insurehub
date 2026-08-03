<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Renewals — {{ $meta['rangeFrom'] }} to {{ $meta['rangeTo'] }}</title>
  <style>
    /* Register Sarabun (bundled at backend/storage/fonts/) via @font-face
       so Thai tone marks + vowels render correctly. dompdf reads local
       file:// URIs when chroot allows — storage_path is inside base_path
       so this works out of the box. */
    @font-face {
      font-family: 'Sarabun';
      font-style: normal;
      font-weight: 400;
      src: url("{{ storage_path('fonts/Sarabun-Regular.ttf') }}") format('truetype');
    }
    @font-face {
      font-family: 'Sarabun';
      font-style: normal;
      font-weight: 700;
      src: url("{{ storage_path('fonts/Sarabun-Bold.ttf') }}") format('truetype');
    }
    @font-face {
      font-family: 'Sarabun';
      font-style: italic;
      font-weight: 400;
      src: url("{{ storage_path('fonts/Sarabun-Italic.ttf') }}") format('truetype');
    }
    * { font-family: 'Sarabun', DejaVu Sans, sans-serif; }
    body { font-size: 10px; color: #0f172a; margin: 18px 22px; }

    /* Header band */
    .head { display: table; width: 100%; margin-bottom: 12px; border-bottom: 2px solid #0e74e8; padding-bottom: 10px; }
    .head-l { display: table-cell; vertical-align: middle; width: 60px; }
    .head-l img { width: 56px; height: 56px; object-fit: contain; }
    .head-c { display: table-cell; vertical-align: middle; padding-left: 12px; }
    .head-c .brand { font-size: 16px; font-weight: 700; color: #0e74e8; letter-spacing: 0.3px; }
    .head-c .tag { font-size: 10px; color: #64748b; margin-top: 2px; }
    .head-r { display: table-cell; vertical-align: middle; text-align: right; font-size: 9px; color: #64748b; }

    /* Report title */
    h1 { font-size: 15px; margin: 0 0 6px; color: #0f172a; }
    .subtitle { font-size: 10px; color: #64748b; margin-bottom: 12px; }

    /* Summary strip */
    .summary { display: table; width: 100%; margin-bottom: 12px; }
    .sum-cell { display: table-cell; padding: 6px 10px; background: #f1f5f9; border: 1px solid #e2e8f0; border-right: 0; }
    .sum-cell:last-child { border-right: 1px solid #e2e8f0; }
    .sum-cell .label { font-size: 8px; color: #64748b; letter-spacing: 0.4px; }
    .sum-cell .val { font-size: 13px; font-weight: 700; color: #0f172a; margin-top: 2px; }
    .sum-cell.urgent .val { color: #b91c1c; }

    /* Active filters strip */
    .filters { margin-bottom: 8px; font-size: 9px; color: #475569; }
    .filters .chip { display: inline-block; margin-right: 6px; padding: 2px 8px; background: #dbeafe; color: #1e40af; border-radius: 3px; }

    /* Data table */
    table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.data th, table.data td { border-bottom: 1px solid #e2e8f0; padding: 5px 6px; text-align: left; font-size: 9px; vertical-align: top; }
    /* font-weight must be 700 (not 600) because we only registered Sarabun
       at 400 + 700; a mid weight makes dompdf pick a system fallback that
       lacks the Thai code range → "?????" glyphs. Also skip
       text-transform:uppercase — it's a no-op for Thai and can corrupt
       CID-encoded glyphs on some dompdf builds. */
    table.data th { background: #0e74e8; color: white; font-weight: 700; letter-spacing: 0.3px; font-size: 8px; }
    table.data td.num, table.data th.num { text-align: right; font-family: 'Sarabun', DejaVu Sans Mono, monospace; }
    table.data td.center { text-align: center; }
    /* Skip zebra striping — dompdf's Cellmap explodes on 500-row wide tables
       with per-row backgrounds. Plain white with the bottom border is enough. */

    /* Days-remaining pill */
    .pill { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 8px; font-weight: 700; }
    .pill.urgent { background: #fee2e2; color: #991b1b; }
    .pill.soon { background: #fef3c7; color: #92400e; }
    .pill.ok { background: #dcfce7; color: #166534; }

    /* Notes column (blank for pen annotation) */
    .notes-col { border-left: 1px dashed #cbd5e1; }

    /* Footer */
    .footer { position: fixed; bottom: 8px; left: 22px; right: 22px; font-size: 8px; color: #94a3b8; display: table; width: calc(100% - 44px); }
    .footer .fl { display: table-cell; text-align: left; }
    .footer .fr { display: table-cell; text-align: right; }
  </style>
</head>
<body>
  <!-- Fixed page footer with generated timestamp + page numbers -->
  <div class="footer">
    <div class="fl">InsureHub — รายการต่ออายุกรมธรรม์ · Generated {{ $meta['generatedAt'] }}</div>
    <div class="fr">
      Page <script type="text/php">
        if (isset($pdf)) {
          $font = $fontMetrics->getFont("DejaVu Sans", "normal");
          $pdf->page_text(505, 815, "{PAGE_NUM} / {PAGE_COUNT}", $font, 8, [0.58, 0.64, 0.72]);
        }
      </script>
    </div>
  </div>

  <!-- Header band with logo + company info -->
  <div class="head">
    <div class="head-l">
      @php
        $logoPath = public_path('brand/logo.png');
      @endphp
      @if (file_exists($logoPath))
        <img src="{{ $logoPath }}" alt="InsureHub" />
      @endif
    </div>
    <div class="head-c">
      <div class="brand">InsureHub</div>
      <div class="tag">ระบบจัดการตัวแทนประกันภัย · Renewal Pipeline</div>
    </div>
    <div class="head-r">
      <div>{{ config('app.name', 'InsureHub') }}</div>
      <div>{{ env('MAIL_FROM_ADDRESS', 'noreply@insurehub.co.th') }}</div>
      <div>Generated: {{ $meta['generatedAt'] }}</div>
    </div>
  </div>

  <!-- Report title -->
  <h1>รายการต่ออายุกรมธรรม์ ({{ $meta['days'] }} วัน)</h1>
  <div class="subtitle">
    ช่วงวันที่: {{ $meta['rangeFrom'] }} → {{ $meta['rangeTo'] }} · แสดง {{ number_format($meta['filteredCount']) }} รายการ
    @if ($meta['filteredCount'] !== $meta['totalWindow'])
      (กรองจากทั้งหมด {{ number_format($meta['totalWindow']) }} รายการในช่วง)
    @endif
  </div>

  <!-- Summary strip -->
  <div class="summary">
    <div class="sum-cell">
      <div class="label">รายการทั้งหมดในช่วง</div>
      <div class="val">{{ number_format($meta['totalWindow']) }}</div>
    </div>
    <div class="sum-cell urgent">
      <div class="label">ด่วน (≤ 7 วัน)</div>
      <div class="val">{{ number_format($meta['urgent']) }}</div>
    </div>
    <div class="sum-cell">
      <div class="label">แสดงในเอกสารนี้</div>
      <div class="val">{{ number_format($meta['filteredCount']) }}</div>
    </div>
  </div>

  <!-- Active filter chips -->
  @if (! empty($meta['filters']))
    <div class="filters">
      <strong>ตัวกรอง:</strong>
      @foreach ($meta['filters'] as $k => $v)
        <span class="chip">{{ $k }}: {{ $v }}</span>
      @endforeach
    </div>
  @endif

  <!-- Data table -->
  <table class="data">
    <thead>
      <tr>
        <th style="width:20px;" class="center">#</th>
        <th style="width:80px;">เลขกรมธรรม์</th>
        <th>ลูกค้า</th>
        <th style="width:80px;">เบอร์โทร</th>
        <th style="width:120px;">อีเมล</th>
        <th style="width:70px;">ทะเบียนรถ</th>
        <th style="width:100px;">ยี่ห้อ/รุ่น</th>
        <th style="width:75px;">บริษัท</th>
        <th style="width:80px;" class="num">Premium</th>
        <th style="width:60px;">หมดอายุ</th>
        <th style="width:35px;" class="center">วัน</th>
        <th style="width:80px;">ตัวแทน</th>
        <th style="width:80px;" class="notes-col">บันทึกการติดต่อ</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($rows as $i => $r)
        <tr>
          <td class="center">{{ $i + 1 }}</td>
          <td>{{ $r->policy_no ?? $r->application_no ?? '—' }}</td>
          <td>
            {{ $r->customer_name ?? '—' }}
            @if ($r->customer_code)<div style="font-size:8px;color:#94a3b8;">{{ $r->customer_code }}</div>@endif
          </td>
          <td>{{ $r->customer_phone ?? '—' }}</td>
          <td style="word-break: break-all;">{{ $r->customer_email ?? '—' }}</td>
          <td>{{ $r->motor_license_no ?? '—' }}</td>
          <td>
            @if ($r->motor_vehicle_brand || $r->motor_vehicle_model)
              {{ trim(($r->motor_vehicle_brand ?? '') . ' ' . ($r->motor_vehicle_model ?? '')) }}
            @else
              —
            @endif
          </td>
          <td>{{ $r->carrier_code ?? '—' }}</td>
          <td class="num">{{ number_format((float) $r->annual_premium, 0) }}</td>
          <td>{{ $r->expiry_date }}</td>
          <td class="center">
            @php
              $dr = (int) $r->days_remaining;
              $cls = $dr <= 7 ? 'urgent' : ($dr <= 30 ? 'soon' : 'ok');
            @endphp
            <span class="pill {{ $cls }}">{{ $dr }}</span>
          </td>
          <td>
            {{ $r->agent_code ?? '—' }}
            @if ($r->agent_name)<div style="font-size:8px;color:#94a3b8;">{{ $r->agent_name }}</div>@endif
          </td>
          <td class="notes-col">&nbsp;</td>
        </tr>
      @empty
        <tr>
          <td colspan="13" style="text-align:center;color:#94a3b8;padding:24px 0;">
            ไม่พบกรมธรรม์ตามเงื่อนไขที่เลือก
          </td>
        </tr>
      @endforelse
    </tbody>
  </table>
</body>
</html>
