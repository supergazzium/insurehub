<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>ใบสรุปค่าคอมมิชชั่น — {{ $agent['code'] }}</title>
  @include('pdf.partials.styles')
</head>
<body>
@use('App\Support\ThaiDate')
@php
  $m = fn ($v) => number_format((float) $v, 2);
  $vatLabel = ['1' => 'ไม่จดทะเบียน VAT', '2' => 'จด VAT — บวก VAT 7% เพิ่ม', '3' => 'จด VAT — VAT 7% รวมอยู่ในยอด'][$vatType] ?? 'ไม่จดทะเบียน VAT';
@endphp

<div class="footer">
  <table><tr>
    <td>{{ $company['name'] ?? 'InsureHub' }} · ใบสรุปค่าคอมมิชชั่น (รอบจ่ายแบบเดิม) · รอบ #{{ $batch['id'] }} · พิมพ์เมื่อ {{ $generatedAt }}</td>
    <td style="text-align:right">เอกสารนี้ออกจากระบบ InsureHub</td>
  </tr></table>
</div>

@include('pdf.partials.letterhead', [
  'company' => $company ?? ['name' => 'InsureHub'],
  'docTitle' => 'ใบสรุปค่าคอมมิชชั่น',
  'docTitleEn' => 'COMMISSION SUMMARY',
  'docMeta' => [
    'รอบจ่าย' => '#'.$batch['id'],
    'รอบวันแจ้งงาน' => ThaiDate::short($batch['from']).' – '.ThaiDate::short($batch['to']),
  ],
])

<div class="box">
  <table class="kv">
    <tr>
      <td class="k">ผู้รับเงิน</td><td class="v">{{ $agent['name'] ?: $agent['code'] }}</td>
      <td class="k">รหัสตัวแทน</td><td class="v">{{ $agent['code'] }}</td>
    </tr>
    <tr>
      <td class="k">ภาษีมูลค่าเพิ่ม</td><td class="v">{{ $vatLabel }}</td>
      <td class="k">จำนวนรายการ</td><td class="v">{{ count($items) }} รายการ</td>
    </tr>
  </table>
</div>

<h2 class="sec">รายการค่าคอมมิชชั่น</h2>
<table class="grid">
  <thead>
    <tr>
      <th style="width:16%">เลขกรมธรรม์</th>
      <th style="width:15%">ใบคำขอ</th>
      <th>ลูกค้า</th>
      <th class="num" style="width:15%">เบี้ย (ฐาน)</th>
      <th class="num" style="width:15%">ค่าคอม (บาท)</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($items as $it)
      <tr>
        <td>{{ $it['policyNo'] ?: '—' }}</td>
        <td>{{ $it['applicationNo'] ?: '—' }}</td>
        <td>{{ $it['customerName'] ?: '—' }}</td>
        <td class="num">{{ $m($it['base']) }}</td>
        <td class="num">{{ $m($it['commission']) }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

<table style="width:100%; margin-top:14px; border-collapse:collapse">
  <tr>
    <td style="width:52%"></td>
    <td style="width:48%">
      <table class="sum">
        <tr><td class="k">รวมค่าคอมมิชชั่น</td><td class="v">{{ $m($totals['commission']) }}</td></tr>
        @if ($totals['deduct'] > 0)
          <tr class="neg"><td class="k">หักพิเศษ</td><td class="v">-{{ $m($totals['deduct']) }}</td></tr>
        @endif
        @if ($vatType === '2')
          <tr><td class="k">ฐานภาษี (ก่อน VAT)</td><td class="v">{{ $m($totals['net']) }}</td></tr>
          <tr><td class="k">VAT 7%</td><td class="v">{{ $m($totals['vat']) }}</td></tr>
        @elseif ($vatType === '3')
          <tr><td class="k">มูลค่าก่อน VAT</td><td class="v">{{ $m($totals['baseExVat']) }}</td></tr>
          <tr><td class="k">VAT 7% (รวมอยู่ในยอด)</td><td class="v">{{ $m($totals['vat']) }}</td></tr>
        @endif
        <tr class="total"><td class="k"><b>ยอดจ่ายสุทธิ</b></td><td class="v">฿{{ $m($totals['payable']) }}</td></tr>
      </table>
    </td>
  </tr>
</table>

<table class="signs">
  <tr>
    <td><div class="line"></div>ผู้จัดทำ</td>
    <td><div class="line"></div>ผู้อนุมัติจ่าย</td>
    <td><div class="line"></div>ผู้รับเงิน</td>
  </tr>
</table>
</body>
</html>
