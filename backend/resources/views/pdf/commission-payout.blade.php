<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>ใบสรุปค่าคอมมิชชั่น — {{ $agent['code'] }}</title>
  <style>
    @font-face { font-family: 'Sarabun'; font-style: normal; font-weight: 400; src: url("{{ storage_path('fonts/Sarabun-Regular.ttf') }}") format('truetype'); }
    @font-face { font-family: 'Sarabun'; font-style: normal; font-weight: 700; src: url("{{ storage_path('fonts/Sarabun-Bold.ttf') }}") format('truetype'); }
    * { font-family: 'Sarabun', DejaVu Sans, sans-serif; }
    body { font-size: 11px; color: #0f172a; margin: 24px 28px; }

    .head { display: table; width: 100%; margin-bottom: 14px; border-bottom: 2px solid #0e74e8; padding-bottom: 10px; }
    .head-c { display: table-cell; vertical-align: middle; }
    .head-c .brand { font-size: 17px; font-weight: 700; color: #0e74e8; }
    .head-c .tag { font-size: 10px; color: #64748b; margin-top: 2px; }
    .head-r { display: table-cell; vertical-align: middle; text-align: right; font-size: 10px; color: #475569; }

    h1 { font-size: 14px; margin: 0 0 8px; }
    .meta { font-size: 10px; color: #475569; margin-bottom: 4px; }
    .meta b { color: #0f172a; }
    .vat-badge { display: inline-block; padding: 1px 8px; border-radius: 3px; font-size: 9px; font-weight: 700; }
    .vat-1 { background: #f1f5f9; color: #475569; }
    .vat-2 { background: #dbeafe; color: #1e40af; }
    .vat-3 { background: #ede9fe; color: #6d28d9; }

    table.data { width: 100%; border-collapse: collapse; margin-top: 10px; }
    table.data th, table.data td { border-bottom: 1px solid #e2e8f0; padding: 6px 7px; text-align: left; font-size: 9px; }
    table.data th { background: #0e74e8; color: white; font-weight: 700; font-size: 8px; }
    table.data td.num, table.data th.num { text-align: right; }

    .totals { margin-top: 14px; width: 46%; float: right; }
    .totals table { width: 100%; border-collapse: collapse; }
    .totals td { padding: 4px 8px; font-size: 10px; }
    .totals td.lbl { color: #475569; }
    .totals td.val { text-align: right; font-weight: 700; }
    .totals tr.grand td { border-top: 2px solid #0e74e8; font-size: 13px; color: #0e74e8; padding-top: 8px; }

    .sign { margin-top: 90px; display: table; width: 100%; clear: both; }
    .sign-cell { display: table-cell; width: 50%; text-align: center; font-size: 10px; color: #475569; }
    .sign-line { margin: 0 30px 4px; border-top: 1px dotted #94a3b8; }

    .footer { position: fixed; bottom: 10px; left: 28px; right: 28px; font-size: 8px; color: #94a3b8; display: table; width: calc(100% - 56px); }
    .footer .fl { display: table-cell; text-align: left; }
    .footer .fr { display: table-cell; text-align: right; }
  </style>
</head>
<body>
  <div class="footer">
    <div class="fl">InsureHub — ใบสรุปค่าคอมมิชชั่นตัวแทน · รอบ {{ $batch['from'] }} ถึง {{ $batch['to'] }} · ออกเมื่อ {{ $generatedAt }}</div>
    <div class="fr">Batch #{{ $batch['id'] }}</div>
  </div>

  <div class="head">
    <div class="head-c">
      <div class="brand">InsureHub</div>
      <div class="tag">ใบสรุปการจ่ายค่าคอมมิชชั่นประจำรอบ</div>
    </div>
    <div class="head-r">
      รอบวันแจ้งงาน<br /><b>{{ $batch['from'] }} — {{ $batch['to'] }}</b>
    </div>
  </div>

  <h1>ค่าคอมมิชชั่นตัวแทน: {{ $agent['name'] ?: $agent['code'] }}</h1>
  <div class="meta">รหัสตัวแทน <b>{{ $agent['code'] }}</b>
    &nbsp;·&nbsp; ประเภท VAT:
    <span class="vat-badge vat-{{ $vatType }}">
      @if ($vatType === '2') VAT Exclude
      @elseif ($vatType === '3') VAT Include
      @else ไม่มี VAT @endif
    </span>
  </div>
  <div class="meta">จำนวนรายการ <b>{{ count($items) }}</b> รายการ</div>

  <table class="data">
    <thead>
      <tr>
        <th style="width:14%">เลขกรมธรรม์</th>
        <th style="width:14%">ใบคำขอ</th>
        <th>ลูกค้า</th>
        <th class="num" style="width:16%">เบี้ย (ฐาน)</th>
        <th class="num" style="width:16%">ค่าคอม</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($items as $it)
      <tr>
        <td>{{ $it['policyNo'] ?: '—' }}</td>
        <td>{{ $it['applicationNo'] ?: '—' }}</td>
        <td>{{ $it['customerName'] ?: '—' }}</td>
        <td class="num">{{ number_format($it['base'], 2) }}</td>
        <td class="num">{{ number_format($it['commission'], 2) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <div class="totals">
    <table>
      <tr><td class="lbl">รวมค่าคอมมิชชั่น</td><td class="val">฿{{ number_format($totals['commission'], 2) }}</td></tr>
      @if ($totals['deduct'] > 0)
      <tr><td class="lbl">หักพิเศษ</td><td class="val">-฿{{ number_format($totals['deduct'], 2) }}</td></tr>
      @endif

      {{-- VAT presentation differs by VAT_TYPE (spec §5). --}}
      @if ($vatType === '2')
        {{-- Exclude: VAT added on top of the base. --}}
        <tr><td class="lbl">ฐานภาษี (ก่อน VAT)</td><td class="val">฿{{ number_format($totals['net'], 2) }}</td></tr>
        <tr><td class="lbl">VAT 7%</td><td class="val">฿{{ number_format($totals['vat'], 2) }}</td></tr>
      @elseif ($vatType === '3')
        {{-- Include: VAT already inside the amount, shown for reference. --}}
        <tr><td class="lbl">มูลค่าก่อน VAT</td><td class="val">฿{{ number_format($totals['baseExVat'], 2) }}</td></tr>
        <tr><td class="lbl">VAT 7% (รวมอยู่ในยอด)</td><td class="val">฿{{ number_format($totals['vat'], 2) }}</td></tr>
      @endif

      <tr class="grand"><td class="lbl">ยอดจ่ายสุทธิ</td><td class="val">฿{{ number_format($totals['payable'], 2) }}</td></tr>
    </table>
  </div>

  <div class="sign">
    <div class="sign-cell"><div class="sign-line"></div>ผู้รับเงิน / ตัวแทน</div>
    <div class="sign-cell"><div class="sign-line"></div>ผู้จ่ายเงิน / InsureHub</div>
  </div>
</body>
</html>
