@php
  $logoPath = public_path('brand/logo.png');
  $fmtTax = function (?string $id): string {
      $d = preg_replace('/\D/', '', (string) $id);
      return strlen($d) === 13 ? substr($d, 0, 1).'-'.substr($d, 1, 4).'-'.substr($d, 5, 5).'-'.substr($d, 10, 2).'-'.substr($d, 12, 1) : (string) $id;
  };
@endphp
<table class="lh">
  <tr>
    <td class="lh-logo">
      @if (file_exists($logoPath))
        <img src="{{ $logoPath }}" alt="InsureHub" />
      @endif
    </td>
    <td>
      <div class="lh-co-name">{{ $company['name'] ?? 'InsureHub' }}</div>
      @if (! empty($company['nameEn']))<div class="lh-co-en">{{ $company['nameEn'] }}</div>@endif
      @if (! empty($company['address']))<div class="lh-co-line">{{ $company['address'] }}</div>@endif
      <div class="lh-co-line">
        @if (! empty($company['taxId']))เลขประจำตัวผู้เสียภาษีอากร {{ $fmtTax($company['taxId']) }} (สำนักงานใหญ่)@endif
        @if (! empty($company['oicLicense'])) · ใบอนุญาตนายหน้า {{ $company['oicLicense'] }}@endif
      </div>
      <div class="lh-co-line">
        @if (! empty($company['phone']))โทร {{ $company['phone'] }}@endif
        @if (! empty($company['email'])) · {{ $company['email'] }}@endif
        @if (! empty($company['website'])) · {{ $company['website'] }}@endif
      </div>
    </td>
    <td class="lh-doc">
      <div class="lh-doc-box">
        <div class="lh-doc-title">{{ $docTitle }}<span class="en">{{ $docTitleEn }}</span></div>
        <table class="lh-doc-meta">
          @foreach ($docMeta as $k => $v)
            <tr><td class="k">{{ $k }}</td><td class="v">{{ $v }}</td></tr>
          @endforeach
        </table>
      </div>
    </td>
  </tr>
</table>
<div class="rule"></div>
<div class="rule-accent"></div>
