<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Commission Statement — {{ $payout->agent?->agent_code }}</title>
  <style>
    /* DomPDF ships a limited font set — sans-serif renders Thai reasonably on
       modern DomPDF (>=3) with DejaVu fallback. If your dompdf config points at
       a Thai font (e.g. Sarabun), use font-family: 'Sarabun' below. */
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 11px; color: #111; margin: 24px 32px; }
    h1 { font-size: 18px; margin: 0 0 4px; color: #0e74e8; }
    h2 { font-size: 13px; margin: 20px 0 6px; color: #334155; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; }
    .head { display: table; width: 100%; margin-bottom: 20px; }
    .head-l { display: table-cell; vertical-align: top; width: 60%; }
    .head-r { display: table-cell; vertical-align: top; text-align: right; }
    .kv { margin: 2px 0; }
    .kv .k { color: #64748b; margin-right: 6px; }
    table.txns { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.txns th, table.txns td { border-bottom: 1px solid #e2e8f0; padding: 4px 6px; text-align: left; font-size: 10px; }
    table.txns th { background: #f1f5f9; color: #475569; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }
    table.txns .amt { text-align: right; font-family: DejaVu Sans Mono, monospace; }
    table.txns tr.reversal td { color: #b91c1c; }
    .totals { margin-top: 14px; text-align: right; font-size: 12px; }
    .totals table { display: inline-table; border-collapse: collapse; }
    .totals td { padding: 3px 12px; }
    .totals .label { color: #64748b; }
    .totals .val { text-align: right; font-family: DejaVu Sans Mono, monospace; }
    .totals .net { font-size: 14px; font-weight: 700; color: #0e74e8; }
    .footer { margin-top: 40px; font-size: 9px; color: #94a3b8; }
    .badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 9px; text-transform: uppercase; }
    .b-draft { background: #f1f5f9; color: #475569; }
    .b-issued { background: #dbeafe; color: #1e40af; }
    .b-paid { background: #d1fae5; color: #065f46; }
    .b-void { background: #fee2e2; color: #991b1b; }
  </style>
</head>
<body>
  <div class="head">
    <div class="head-l">
      <h1>Commission Statement</h1>
      <div class="kv"><span class="k">Statement no.:</span>{{ $payout->id }}</div>
      <div class="kv"><span class="k">Period:</span>{{ \App\Services\Format\BuddhistDate::format($payout->period_from) }} → {{ \App\Services\Format\BuddhistDate::format($payout->period_to) }}</div>
      <div class="kv"><span class="k">Status:</span><span class="badge b-{{ $payout->status }}">{{ strtoupper($payout->status) }}</span></div>
      @if ($payout->paid_at)
        <div class="kv"><span class="k">Paid at:</span>{{ \App\Services\Format\BuddhistDate::format($payout->paid_at) }} {{ $payout->paid_at->format('H:i') }}</div>
      @endif
      @if ($payout->bank_ref)
        <div class="kv"><span class="k">Bank ref:</span>{{ $payout->bank_ref }}</div>
      @endif
    </div>
    <div class="head-r">
      <div style="font-weight:700;font-size:12px;">{{ $tenant?->name ?? 'InsureHub' }}</div>
      @if ($tenant?->email)<div style="color:#64748b;">{{ $tenant->email }}</div>@endif
      @if ($tenant?->phone)<div style="color:#64748b;">{{ $tenant->phone }}</div>@endif
      @if ($tenant?->tax_id)<div style="color:#64748b;">Tax ID: {{ $tenant->tax_id }}</div>@endif
    </div>
  </div>

  <h2>Recipient</h2>
  <div class="kv"><span class="k">Agent code:</span>{{ $payout->agent?->agent_code }}</div>
  <div class="kv"><span class="k">Name:</span>{{ trim(($payout->agent?->first_name ?? '').' '.($payout->agent?->last_name ?? '')) }}</div>

  <h2>Commission transactions ({{ $payout->transactions->count() }})</h2>
  <table class="txns">
    <thead>
      <tr>
        <th style="width:80px;">Type</th>
        <th>Policy</th>
        <th style="width:60px;">Party</th>
        <th style="width:70px;" class="amt">Base</th>
        <th style="width:50px;" class="amt">%</th>
        <th style="width:80px;" class="amt">Amount</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($payout->transactions as $t)
        <tr @if ($t->reverses_txn_id) class="reversal" @endif>
          <td>{{ $t->type }}@if ($t->reverses_txn_id) (reversal)@endif</td>
          <td>{{ $t->policy?->policy_no ?: $t->policy?->application_no ?: ('#'.$t->policy_id) }}</td>
          <td>{{ $t->payer_level }}</td>
          <td class="amt">{{ number_format((float)$t->base_premium, 2) }}</td>
          <td class="amt">{{ number_format((float)$t->diff_pct * 100, 2) }}</td>
          <td class="amt">{{ number_format((float)$t->amount, 2) }}</td>
        </tr>
      @empty
        <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:12px;">No transactions</td></tr>
      @endforelse
    </tbody>
  </table>

  <div class="totals">
    <table>
      <tr><td class="label">Gross commission</td><td class="val">{{ number_format((float)$payout->gross_amount, 2) }}</td></tr>
      <tr>
        <td class="label">Withholding tax ({{ number_format((float)$payout->wht_rate * 100, 2) }}%)</td>
        <td class="val">- {{ number_format((float)$payout->wht_amount, 2) }}</td>
      </tr>
      <tr><td class="label net">Net payable</td><td class="val net">{{ number_format((float)$payout->net_amount, 2) }} THB</td></tr>
    </table>
    <div style="font-size:10px;color:#64748b;margin-top:6px;">
      ({{ \App\Services\Format\BahtText::convert((float)$payout->net_amount) }})
    </div>
  </div>

  <div class="footer">
    Generated {{ \App\Services\Format\BuddhistDate::format(now()) }} {{ now()->format('H:i:s') }} · InsureHub commission engine
  </div>
</body>
</html>
