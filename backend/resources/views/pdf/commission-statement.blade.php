<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>ใบแจ้งการจ่ายค่าคอมมิชชั่น — {{ $agent['code'] }}</title>
  @include('pdf.partials.styles')
</head>
<body>
@use('App\Support\ThaiDate')
@php
  $m = fn ($v) => number_format((float) $v, 2);
  $fmtTax = function (?string $id): string {
      $d = preg_replace('/\D/', '', (string) $id);
      return strlen($d) === 13 ? substr($d, 0, 1).'-'.substr($d, 1, 4).'-'.substr($d, 5, 5).'-'.substr($d, 10, 2).'-'.substr($d, 12, 1) : ((string) $id ?: '—');
  };
  $maskAcc = function (?string $acc): string {
      $d = preg_replace('/\D/', '', (string) $acc);
      return strlen($d) >= 4 ? str_repeat('x', max(0, strlen($d) - 4)).substr($d, -4) : '—';
  };
  $vatLabel = ['1' => 'ไม่จดทะเบียน VAT', '2' => 'จด VAT — บวก VAT 7% เพิ่ม', '3' => 'จด VAT — VAT 7% รวมอยู่ในยอด'][$vatType] ?? 'ไม่จดทะเบียน VAT';
  $statusLabel = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'PAID' => 'จ่ายแล้ว', 'CANCELLED' => 'ยกเลิก'][$batch['status']] ?? $batch['status'];
@endphp

<div class="footer">
  <table><tr>
    <td>{{ $company['name'] }} · ใบแจ้งการจ่ายค่าคอมมิชชั่น เลขที่ {{ $batch['docNo'] }} · พิมพ์เมื่อ {{ $generatedAt }}</td>
    <td style="text-align:right">เอกสารนี้ออกจากระบบ InsureHub</td>
  </tr></table>
</div>

@include('pdf.partials.letterhead', [
  'docTitle' => 'ใบแจ้งการจ่ายค่าคอมมิชชั่น',
  'docTitleEn' => 'COMMISSION PAYMENT STATEMENT',
  'docMeta' => [
    'เลขที่' => $batch['docNo'],
    'วันที่จ่าย' => $batch['paymentDate'] ? ThaiDate::long($batch['paymentDate']) : '—',
    'รอบวันแจ้งงาน' => ThaiDate::short($batch['from']).' – '.ThaiDate::short($batch['to']),
    'สถานะ' => $statusLabel,
  ],
])

<div class="box">
  <table class="kv">
    <tr>
      <td class="k">ผู้รับเงิน</td><td class="v">{{ $agent['name'] ?: '—' }}</td>
      <td class="k">รหัสตัวแทน</td><td class="v">{{ $agent['code'] }}</td>
    </tr>
    <tr>
      <td class="k">ประเภทผู้รับเงิน</td><td class="v">{{ $agent['kind'] === 'corporate' ? 'นิติบุคคล' : 'บุคคลธรรมดา' }} · {{ $vatLabel }}</td>
      <td class="k">เลขประจำตัวผู้เสียภาษี</td><td class="v">{{ $fmtTax($agent['taxId'] ?? null) }}</td>
    </tr>
    <tr>
      <td class="k">ที่อยู่</td><td class="v" colspan="3" style="font-weight:400">{{ $agent['address'] ?? '—' }}</td>
    </tr>
    <tr>
      <td class="k">โอนเข้าบัญชี</td><td class="v" colspan="3">{{ $agent['bankName'] ?: '—' }} · เลขที่ {{ $maskAcc($agent['bankAccountNo'] ?? null) }}</td>
    </tr>
  </table>
</div>

@foreach ($sections as $section)
  <h2 class="sec">{{ $section['label'] }}</h2>
  <table class="grid">
    <thead>
      <tr>
        <th style="width:15%">เลขกรมธรรม์</th>
        <th style="width:14%">ใบคำขอ</th>
        <th>ลูกค้า</th>
        @if ($section['type'] === 'base')
          <th class="num" style="width:13%">เบี้ยประกัน</th>
          <th style="width:15%">ลูกค้าชำระ</th>
        @elseif ($section['type'] !== 'self_mgmt')
          <th style="width:22%">จากยอดขายของ</th>
        @else
          <th class="num" style="width:13%">ฐานคำนวณ</th>
        @endif
        <th class="num" style="width:13%">จำนวนเงิน (บาท)</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($section['rows'] as $r)
        <tr>
          <td>{{ $r['policyNo'] ?: '—' }}</td>
          <td>{{ $r['applicationNo'] ?: '—' }}</td>
          <td>{{ $r['customerName'] ?: '—' }}</td>
          @if ($section['type'] === 'base')
            <td class="num">{{ $m($r['base']) }}</td>
            <td>{{ $r['ratioLabel'] ?: 'ชำระแล้ว' }}@if ($r['override']) <span class="badge warn">ยกเว้นเงื่อนไข</span>@endif</td>
          @elseif ($section['type'] !== 'self_mgmt')
            <td>{{ $r['source'] ?: '—' }}</td>
          @else
            <td class="num">{{ $m($r['base']) }}</td>
          @endif
          <td class="num">{{ $m($r['commission']) }}</td>
        </tr>
      @endforeach
      <tr class="sub">
        <td colspan="{{ $section['type'] === 'base' ? 5 : 4 }}">รวม{{ $section['label'] }}</td>
        <td class="num">{{ $m($section['total']) }}</td>
      </tr>
    </tbody>
  </table>
@endforeach

@if (! empty($deductions))
  <h2 class="sec">รายการหัก</h2>
  <table class="grid">
    <thead><tr><th style="width:30%">ประเภท</th><th style="width:18%">กรมธรรม์</th><th>รายละเอียด</th><th class="num" style="width:13%">จำนวนเงิน (บาท)</th></tr></thead>
    <tbody>
      @foreach ($deductions as $d)
        <tr><td>{{ $d['label'] }}</td><td>{{ $d['policyNo'] ?: '—' }}</td><td>{{ $d['reason'] }}</td><td class="num">-{{ $m($d['amount']) }}</td></tr>
      @endforeach
    </tbody>
  </table>
@endif

<table style="width:100%; margin-top:14px; border-collapse:collapse">
  <tr>
    <td style="width:52%; vertical-align:top; padding-right:14px">
      <div class="note" style="margin-top:0">
        <b>หมายเหตุ</b><br />
        1. ภาษีหัก ณ ที่จ่ายคำนวณจากยอดก่อนภาษีมูลค่าเพิ่ม อัตรา {{ $m($totals['whtRate']) }}%
        @if (($payee['whtFormLabel'] ?? null)) และนำส่งตามแบบ {{ $payee['whtFormLabel'] }}@endif
        @if (! empty($payee['certificate'])) · หนังสือรับรองการหักภาษี ณ ที่จ่าย เลขที่ {{ $payee['certificate'] }}@endif<br />
        2. ยอดหักคืน (เรียกคืนค่าคอม/หักพิเศษ/ค่าผ่อนชำระ) หักก่อนคำนวณภาษี<br />
        3. ค่าคอมพื้นฐานของกรมธรรม์ผ่อนชำระจ่ายตามสัดส่วนงวดที่ลูกค้าชำระแล้ว ส่วนที่เหลือจ่ายในรอบถัดไป<br />
        4. หากพบข้อผิดพลาดกรุณาแจ้งภายใน 7 วันนับจากวันที่ได้รับเอกสาร
      </div>
    </td>
    <td style="width:48%; vertical-align:top">
      <table class="sum">
        <tr><td class="k">รวมค่าคอมมิชชั่น</td><td class="v">{{ $m($totals['commission']) }}</td></tr>
        @if ($totals['deduct'] > 0)
          <tr class="neg"><td class="k">หัก รายการหัก</td><td class="v">-{{ $m($totals['deduct']) }}</td></tr>
        @endif
        <tr><td class="k">ยอดก่อนภาษีมูลค่าเพิ่ม</td><td class="v">{{ $m($totals['baseExVat']) }}</td></tr>
        @if ($vatType !== '1')
          <tr><td class="k">ภาษีมูลค่าเพิ่ม 7%{{ $vatType === '3' ? ' (รวมอยู่ในยอด)' : '' }}</td><td class="v">{{ $m($totals['vat']) }}</td></tr>
          <tr><td class="k">รวมทั้งสิ้น</td><td class="v">{{ $m($totals['totalWithVat']) }}</td></tr>
        @endif
        <tr class="neg"><td class="k">หัก ภาษีหัก ณ ที่จ่าย {{ $m($totals['whtRate']) }}%</td><td class="v">-{{ $m($totals['wht']) }}</td></tr>
        <tr class="total"><td class="k"><b>ยอดโอนสุทธิ</b></td><td class="v">฿{{ $m($totals['payable']) }}</td></tr>
      </table>
      <div class="words">({{ $totals['payableText'] }})</div>
    </td>
  </tr>
</table>

<table class="signs">
  <tr>
    <td><div class="line"></div>ผู้จัดทำ<br /><span class="small muted">วันที่ ____/____/______</span></td>
    <td><div class="line"></div>ผู้อนุมัติจ่าย<br /><span class="small muted">วันที่ ____/____/______</span></td>
    <td><div class="line"></div>ผู้รับเงิน ({{ $agent['name'] ?: $agent['code'] }})<br /><span class="small muted">วันที่ ____/____/______</span></td>
  </tr>
</table>
</body>
</html>
