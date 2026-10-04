<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>หนังสือรับรองการหักภาษี ณ ที่จ่าย — {{ $cert['label'] }}</title>
  @include('pdf.partials.styles')
  <style>
    .cert { border: 1.5px solid #0f3d4c; padding: 10px 12px; }
    .cert-head { width: 100%; border-collapse: collapse; }
    .cert-head td { vertical-align: top; }
    .cert-title { text-align: center; font-size: 15px; font-weight: 700; color: #0f3d4c; line-height: 1.3; }
    .cert-sub { text-align: center; font-size: 10.5px; color: #334155; }
    .copy { font-size: 9px; color: #475569; text-align: right; }
    .party { border: 1px solid #94a3b8; padding: 6px 8px; margin-top: 7px; }
    .party .lbl { font-weight: 700; color: #0f3d4c; font-size: 10.5px; }
    .tin { display: inline-block; letter-spacing: 1px; font-weight: 700; border: 1px solid #94a3b8; padding: 1px 6px; font-size: 11px; }
    .forms { margin-top: 7px; font-size: 9.5px; }
    .forms span { display: inline-block; margin-right: 10px; }
    .chk { display: inline-block; width: 8px; height: 8px; border: 1px solid #334155; margin-right: 3px; }
    .chk.on { background: #0f3d4c; }
    table.inc { width: 100%; border-collapse: collapse; margin-top: 7px; }
    table.inc th, table.inc td { border: 1px solid #94a3b8; padding: 3px 5px; font-size: 9px; vertical-align: top; }
    table.inc th { background: #eaf4f7; color: #0f3d4c; text-align: center; font-weight: 700; }
    table.inc td.num { text-align: right; white-space: nowrap; }
    table.inc tr.tot td { font-weight: 700; background: #f8fafc; }
    .page-break { page-break-after: always; }
  </style>
</head>
<body>
@use('App\Support\ThaiDate')
@php
  $m = fn ($v) => number_format((float) $v, 2);
  $tin = function (?string $id): string {
      $d = preg_replace('/\D/', '', (string) $id);
      return strlen($d) === 13 ? substr($d, 0, 1).' '.substr($d, 1, 4).' '.substr($d, 5, 5).' '.substr($d, 10, 2).' '.substr($d, 12, 1) : ((string) $id ?: '-');
  };
  $logoPath = public_path('brand/logo.png');
  $rows = [
      '1. เงินเดือน ค่าจ้าง เบี้ยเลี้ยง โบนัส ฯลฯ ตามมาตรา 40 (1)',
      '2. ค่าธรรมเนียม ค่านายหน้า ฯลฯ ตามมาตรา 40 (2)',
      '3. ค่าแห่งลิขสิทธิ์ ฯลฯ ตามมาตรา 40 (3)',
      '4. ดอกเบี้ย เงินปันผล ฯลฯ ตามมาตรา 40 (4)',
      '5. การจ่ายเงินได้ที่ต้องหักภาษี ณ ที่จ่าย ตามคำสั่งกรมสรรพากรที่ออกตามมาตรา 3 เตรส — ค่านายหน้า/ค่าบริการ (ค่าคอมมิชชั่นตัวแทน)',
      '6. อื่น ๆ (ระบุ)',
  ];
  $copies = ['ฉบับที่ 1 (สำหรับผู้ถูกหักภาษี ณ ที่จ่าย ใช้แนบพร้อมกับแบบแสดงรายการภาษี)', 'ฉบับที่ 2 (สำหรับผู้ถูกหักภาษี ณ ที่จ่าย เก็บไว้เป็นหลักฐาน)'];
@endphp

@foreach ($copies as $ci => $copy)
<div class="{{ $ci < count($copies) - 1 ? 'page-break' : '' }}">
  <div class="cert">
    <table class="cert-head">
      <tr>
        <td style="width:120px">@if (file_exists($logoPath))<img src="{{ $logoPath }}" style="width:110px" alt="" />@endif</td>
        <td>
          <div class="cert-title">หนังสือรับรองการหักภาษี ณ ที่จ่าย</div>
          <div class="cert-sub">ตามมาตรา 50 ทวิ แห่งประมวลรัษฎากร</div>
        </td>
        <td style="width:150px">
          <div class="copy">{{ $copy }}</div>
          <div style="text-align:right; margin-top:4px; font-size:10.5px">เล่มที่ <b>{{ $cert['book'] }}</b> &nbsp; เลขที่ <b>{{ sprintf('%05d', (int) $cert['number']) }}</b></div>
        </td>
      </tr>
    </table>

    <div class="party">
      <div class="lbl">ผู้มีหน้าที่หักภาษี ณ ที่จ่าย :</div>
      <table style="width:100%; border-collapse:collapse"><tr>
        <td style="font-size:10.5px">ชื่อ <b>{{ $cert['company']['name'] }}</b></td>
        <td style="text-align:right; font-size:9.5px">เลขประจำตัวผู้เสียภาษีอากร <span class="tin">{{ $tin($cert['company']['taxId']) }}</span></td>
      </tr></table>
      <div style="font-size:9.5px; margin-top:2px">ที่อยู่ {{ $cert['company']['address'] ?: '-' }}</div>
    </div>

    <div class="party">
      <div class="lbl">ผู้ถูกหักภาษี ณ ที่จ่าย :</div>
      <table style="width:100%; border-collapse:collapse"><tr>
        <td style="font-size:10.5px">ชื่อ <b>{{ $cert['payee']['name'] }}</b> <span class="muted small">(รหัสตัวแทน {{ $cert['payee']['code'] }})</span></td>
        <td style="text-align:right; font-size:9.5px">เลขประจำตัวผู้เสียภาษีอากร <span class="tin">{{ $tin($cert['payee']['taxId']) }}</span></td>
      </tr></table>
      <div style="font-size:9.5px; margin-top:2px">ที่อยู่ {{ $cert['payee']['address'] ?: '-' }}</div>
    </div>

    <div class="forms">
      ลำดับที่ ................ ในแบบ &nbsp;
      @foreach (['pnd1a' => '(1) ภ.ง.ด.1ก', 'pnd1a_s' => '(2) ภ.ง.ด.1ก พิเศษ', 'pnd2' => '(3) ภ.ง.ด.2', 'pnd3' => '(4) ภ.ง.ด.3', 'pnd2a' => '(5) ภ.ง.ด.2ก', 'pnd3a' => '(6) ภ.ง.ด.3ก', 'pnd53' => '(7) ภ.ง.ด.53'] as $key => $label)
        <span><span class="chk {{ $cert['form'] === $key ? 'on' : '' }}"></span>{{ $label }}</span>
      @endforeach
    </div>

    <table class="inc">
      <thead>
        <tr>
          <th>ประเภทเงินได้พึงประเมินที่จ่าย</th>
          <th style="width:16%">วัน เดือน หรือปีภาษี ที่จ่าย</th>
          <th style="width:15%">จำนวนเงินที่จ่าย</th>
          <th style="width:15%">ภาษีที่หัก และนำส่งไว้</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($rows as $i => $label)
          <tr>
            <td>{{ $label }}</td>
            @if ($i === 4)
              <td style="text-align:center">{{ ThaiDate::long($cert['date']) }}</td>
              <td class="num">{{ $m($cert['paidAmount']) }}</td>
              <td class="num">{{ $m($cert['whtAmount']) }}</td>
            @else
              <td></td><td></td><td></td>
            @endif
          </tr>
        @endforeach
        <tr class="tot">
          <td colspan="2" style="text-align:right">รวมเงินที่จ่ายและภาษีที่หักนำส่ง</td>
          <td class="num">{{ $m($cert['paidAmount']) }}</td>
          <td class="num">{{ $m($cert['whtAmount']) }}</td>
        </tr>
        <tr>
          <td colspan="4">รวมเงินภาษีที่หักนำส่ง (ตัวอักษร) <b>{{ $cert['whtText'] }}</b> <span class="muted">(อัตรา {{ $m($cert['rate']) }}%)</span></td>
        </tr>
      </tbody>
    </table>

    <div style="margin-top:6px; font-size:9.5px">
      เงินที่จ่ายเข้า กบข./กสจ./กองทุนสงเคราะห์ครูโรงเรียนเอกชน ........ บาท &nbsp; กองทุนประกันสังคม ........ บาท &nbsp; กองทุนสำรองเลี้ยงชีพ ........ บาท
    </div>
    <div class="forms">
      ผู้จ่ายเงิน &nbsp;
      <span><span class="chk on"></span>(1) หัก ณ ที่จ่าย</span>
      <span><span class="chk"></span>(2) ออกให้ตลอดไป</span>
      <span><span class="chk"></span>(3) ออกให้ครั้งเดียว</span>
      <span><span class="chk"></span>(4) อื่น ๆ (ระบุ) ..........</span>
    </div>

    <table style="width:100%; border-collapse:collapse; margin-top:10px">
      <tr>
        <td style="width:48%; font-size:8.5px; color:#475569; vertical-align:top; border:1px solid #94a3b8; padding:6px">
          <b>คำเตือน</b> ผู้มีหน้าที่ออกหนังสือรับรองการหักภาษี ณ ที่จ่าย ฝ่าฝืนไม่ปฏิบัติตามมาตรา 50 ทวิ แห่งประมวลรัษฎากร ต้องรับโทษทางอาญาตามมาตรา 35 แห่งประมวลรัษฎากร
        </td>
        <td style="width:52%; text-align:center; font-size:9.5px; vertical-align:top; padding:4px 6px">
          ขอรับรองว่าข้อความและตัวเลขดังกล่าวข้างต้นถูกต้องตรงกับความจริงทุกประการ
          <div style="margin-top:26px">ลงชื่อ ............................................................ ผู้จ่ายเงิน</div>
          <div style="margin-top:4px">{{ ThaiDate::long($cert['date']) }}</div>
          <div class="small muted">(วัน เดือน ปี ที่ออกหนังสือรับรองฯ) · ประทับตรานิติบุคคล (ถ้ามี)</div>
        </td>
      </tr>
    </table>
  </div>
  <div class="note">ออกจากระบบ InsureHub · รอบจ่ายเลขที่ {{ $cert['batchId'] }} · พิมพ์เมื่อ {{ $generatedAt }}</div>
</div>
@endforeach
</body>
</html>
