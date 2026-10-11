<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>ค่านายหน้า / ค่าแนะนำ — {{ $agent['code'] }}</title>
  <style>
    @font-face { font-family: 'Sarabun'; font-style: normal; font-weight: 400; src: url("{{ storage_path('fonts/Sarabun-Regular.ttf') }}") format('truetype'); }
    @font-face { font-family: 'Sarabun'; font-style: normal; font-weight: 700; src: url("{{ storage_path('fonts/Sarabun-Bold.ttf') }}") format('truetype'); }
    * { font-family: 'Sarabun', DejaVu Sans, sans-serif; box-sizing: border-box; }
    @page { margin: 18px 20px; }
    body { font-size: 9px; color: #1a1a1a; margin: 0; }

    /* ── Header ───────────────────────────────────────────────── */
    .head { display: table; width: 100%; margin-bottom: 6px; }
    .head-l { display: table-cell; width: 26%; vertical-align: middle; }
    .head-l img { height: 42px; }
    .head-c { display: table-cell; width: 48%; vertical-align: middle; text-align: center; }
    .head-c .company { font-size: 15px; font-weight: 700; }
    .head-c .subtitle { font-size: 10px; margin-top: 2px; }
    .head-r { display: table-cell; width: 26%; vertical-align: middle; text-align: right; }
    .head-r .qr { display: inline-block; width: 52px; height: 52px; border: 3px solid #111; }
    .head-r .ask { color: #d9480f; font-size: 8px; margin-top: 3px; }

    /* ── Agent identity box ───────────────────────────────────── */
    .agent-box { border: 1px solid #c8ced6; background: #fbe9d0; display: inline-block; padding: 6px 10px; margin: 4px 0 8px; font-size: 9px; line-height: 1.5; }
    .agent-box b { display: inline-block; min-width: 62px; font-weight: 400; }
    .agent-box .v { font-weight: 700; }

    .greeting { font-size: 9px; margin: 6px 0 8px; }

    /* ── Main table ───────────────────────────────────────────── */
    table.data { width: 100%; border-collapse: collapse; }
    table.data th, table.data td { border: 1px solid #8a95a5; padding: 3px 4px; font-size: 8px; vertical-align: middle; }
    table.data thead th { background: #9db0c8; color: #14213d; font-weight: 700; text-align: center; }
    table.data td.num { text-align: right; }
    table.data td.c { text-align: center; }
    table.data td.red { color: #c92a2a; }
    table.data tfoot td { font-weight: 700; background: #eef2f7; }

    /* ── Summary block ────────────────────────────────────────── */
    .summary { width: 70%; margin-top: 12px; margin-left: auto; margin-right: 0; }
    .summary table { width: 100%; border-collapse: collapse; }
    .summary td { padding: 3px 8px; font-size: 9px; }
    .summary td.lbl { text-align: left; }
    .summary td.base { text-align: right; width: 22%; color: #555; }
    .summary td.tax { text-align: right; width: 18%; }
    .summary tr.hl td { background: #fff200; font-weight: 700; }

    /* ── Signatures ───────────────────────────────────────────── */
    .sign { display: table; width: 100%; margin-top: 34px; }
    .sign-cell { display: table-cell; width: 33.33%; text-align: center; font-size: 9px; }
    .sign-line { margin: 26px 18px 3px; border-top: 1px solid #333; }
    .conf { text-align: center; font-size: 8px; color: #444; margin-top: 14px; }
  </style>
</head>
@php
  $money = fn ($n) => number_format((float) $n, 2);
  // Show a dash for zeros, matching the reference sheet.
  $moneyDash = fn ($n) => ((float) $n) == 0.0 ? '-' : number_format((float) $n, 2);
  $pct = fn ($n) => $n === null ? '' : rtrim(rtrim(number_format((float) $n, 2), '0'), '.') . '%';
@endphp
<body>
  <!-- Header -->
  <div class="head">
    <div class="head-l">
      @if ($logoData)<img src="{{ $logoData }}" alt="InsureHub" />@else<span style="font-size:15px;font-weight:700;color:#0e74e8;">InsureHub</span>@endif
    </div>
    <div class="head-c">
      <div class="company">บริษัท อินชัวร์ฮับ จำกัด</div>
      <div class="subtitle">ค่านายหน้า / ค่าแนะนำประจำเดือน : {{ $batch['periodLabel'] ?: ($batch['from'] . ' – ' . $batch['to']) }}</div>
    </div>
    <div class="head-r">
      <div class="qr"></div>
      <div class="ask">สอบถามเพิ่มเติม</div>
    </div>
  </div>

  <!-- Agent identity box -->
  <div class="agent-box">
    <div><b>รหัสตัวแทน :</b> <span class="v">{{ $agent['code'] }}</span></div>
    <div><b>ชื่อนายหน้า :</b> <span class="v">{{ $agent['name'] }}</span></div>
    <div><b>เลขที่บัญชี :</b> <span class="v">{{ $agent['bankAccountNo'] ?: '—' }}</span></div>
    <div><b>ชื่อบัญชี :</b> <span class="v">{{ $agent['bankAccountName'] ?: '—' }}</span></div>
  </div>

  <div class="greeting">
    ขอขอบคุณท่านนายหน้าที่ให้ความร่วมมือด้วยดีเสมอมาและหวังเป็นอย่างยิ่งว่าจะได้รับการตอบรับจากท่านเป็นอย่างดีในโอกาสต่อไป
    ทั้งนี้ทางบริษัทขอนำส่งสรุปค่าตอบแทนประจำเดือน {{ $batch['periodLabel'] ?: '' }} ดังนี้
  </div>

  <!-- Main table -->
  <table class="data">
    <thead>
      <tr>
        <th rowspan="2" style="width:3%">ลำดับ</th>
        <th rowspan="2" style="width:8%">เลขที่<br/>ใบสมัคร</th>
        <th rowspan="2" style="width:6%">วันที่<br/>แจ้งงาน</th>
        <th rowspan="2" style="width:13%">ชื่อผู้เอาประกัน</th>
        <th rowspan="2" style="width:14%">สัญญาเพิ่มเติม</th>
        <th colspan="2">เบี้ยประกัน</th>
        <th colspan="2">ค่าคอม</th>
        <th colspan="2">ยอดหัก (B)</th>
        <th rowspan="2" style="width:8%">ค่านายหน้า<br/>สุทธิ (A)<br/>- ยอดหัก<br/>(A)-(B)</th>
        <th rowspan="2" style="width:6%">หัก ณ ที่จ่าย<br/>3%</th>
        <th rowspan="2" style="width:7%">ยอดจ่าย<br/>สุทธิ</th>
        <th rowspan="2" style="width:8%">หมายเหตุ</th>
      </tr>
      <tr>
        <th style="width:6%">สุทธิ</th>
        <th style="width:6%">เบี้ย<br/>ทั้งหมด</th>
        <th style="width:5%">สัญญา<br/>หลัก</th>
        <th style="width:5%">สัญญา<br/>เพิ่มเติม</th>
        <th style="width:5%">จ่ายขาด/<br/>ส่วนลด/ผ่อน</th>
        <th style="width:5%">ค่าธรรมเนียม<br/>บัตรเครดิต</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($rows as $r)
      <tr>
        <td class="c">{{ $r['no'] }}</td>
        <td class="c">{{ $r['applicationNo'] ?: '—' }}</td>
        <td class="c">{{ $r['policyDate'] ?: '—' }}</td>
        <td>{{ $r['customerName'] ?: '—' }}</td>
        <td>{{ $r['riderName'] ?: '—' }}</td>
        <td class="num">{{ $money($r['netPremium']) }}</td>
        <td class="num">{{ $money($r['totalPremium']) }}</td>
        <td class="c">{{ $pct($r['mainRatePct']) ?: '—' }}</td>
        <td class="c">{{ $pct($r['riderRatePct']) ?: '—' }}</td>
        <td class="num red">{{ $moneyDash($r['payoffDiscount']) }}</td>
        <td class="num red">{{ $moneyDash($r['cardFee']) }}</td>
        <td class="num">{{ $money($r['commissionA']) }}</td>
        <td class="num">{{ $money($r['wht']) }}</td>
        <td class="num">{{ $money($r['payable']) }}</td>
        <td>{{ $r['note'] ?: '' }}</td>
      </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td colspan="5" class="c">รวม</td>
        <td class="num">{{ $money($columnTotals['netPremium']) }}</td>
        <td class="num">{{ $money($columnTotals['totalPremium']) }}</td>
        <td></td>
        <td></td>
        <td class="num">{{ $moneyDash($columnTotals['payoffDiscount']) }}</td>
        <td class="num">{{ $moneyDash($columnTotals['cardFee']) }}</td>
        <td class="num">{{ $money($columnTotals['commissionA']) }}</td>
        <td class="num">{{ $money($columnTotals['wht']) }}</td>
        <td class="num">{{ $money($columnTotals['payable']) }}</td>
        <td></td>
      </tr>
    </tfoot>
  </table>

  <!-- Summary block (WHT recap) -->
  <div class="summary" style="page-break-inside: avoid;">
    <table>
      <tr>
        <td class="lbl">ยอดหัก ณ ที่จ่ายจากค่าคอม (3%)</td>
        <td class="base">{{ $moneyDash($summary['whtFromCommission']) }}</td>
        <td class="tax">{{ $moneyDash($summary['whtFromCommissionTax']) }}</td>
      </tr>
      <tr>
        <td class="lbl">ยอด หัก ณ ที่จ่ายจากโบนัส (3%)</td>
        <td class="base">{{ $moneyDash($summary['whtFromBonus']) }}</td>
        <td class="tax">{{ $moneyDash($summary['whtFromBonusTax']) }}</td>
      </tr>
      <tr>
        <td class="lbl">ยอด หัก ณ ที่จ่ายจากการจ่ายขาด/ส่วนลด (3%)</td>
        <td class="base">{{ $moneyDash($summary['whtFromPayoff']) }}</td>
        <td class="tax">{{ $moneyDash($summary['whtFromPayoffTax']) }}</td>
      </tr>
      <tr>
        <td class="lbl">บวกเงินค่าส่งเสริมการขาย (Tax Related)</td>
        <td class="base">{{ $moneyDash($summary['salesPromoTax']) }}</td>
        <td class="tax"></td>
      </tr>
      <tr class="hl">
        <td class="lbl">ยอดหัก ณ ที่จ่ายรวม</td>
        <td class="base">{{ $moneyDash($summary['whtBaseTotal']) }}</td>
        <td class="tax">{{ $moneyDash($summary['whtTaxTotal']) }}</td>
      </tr>
      <tr>
        <td class="lbl">หักอื่นๆ (+) / บวกเงินค่าส่งเสริมการขาย (-) (Non-tax Related)</td>
        <td class="base"></td>
        <td class="tax">{{ $money($summary['nonTaxAdjust']) }}</td>
      </tr>
      <tr class="hl">
        <td class="lbl">ยอดเงินโอน</td>
        <td class="base"></td>
        <td class="tax">{{ $money($summary['netTransfer']) }}</td>
      </tr>
    </table>
  </div>

  <!-- Signatures -->
  <div class="sign">
    <div class="sign-cell"><div class="sign-line"></div>จัดเตรียมโดย</div>
    <div class="sign-cell"><div class="sign-line"></div>ผู้สอบทาน</div>
    <div class="sign-cell"><div class="sign-line"></div>ผู้อนุมัติ</div>
  </div>
  <div class="conf">Private and Confidential</div>
</body>
</html>
