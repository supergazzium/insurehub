<style>
  @font-face { font-family: 'Sarabun'; font-style: normal; font-weight: 400; src: url("{{ storage_path('fonts/Sarabun-Regular.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Sarabun'; font-style: normal; font-weight: 700; src: url("{{ storage_path('fonts/Sarabun-Bold.ttf') }}") format('truetype'); }
  @page { margin: 26px 30px 40px 30px; }
  * { font-family: 'Sarabun', DejaVu Sans, sans-serif; }
  body { font-size: 10.5px; color: #1e293b; margin: 0; }
  .lh { width: 100%; border-collapse: collapse; }
  .lh td { vertical-align: top; padding: 0; }
  .lh-logo { width: 150px; padding-right: 12px !important; }
  .lh-logo img { width: 140px; }
  .lh-co-name { font-size: 14px; font-weight: 700; color: #0f3d4c; line-height: 1.25; }
  .lh-co-en { font-size: 9.5px; color: #475569; }
  .lh-co-line { font-size: 9px; color: #475569; line-height: 1.45; }
  .lh-doc { width: 220px; }
  .lh-doc-box { border: 1.5px solid #1f7a93; border-radius: 4px; }
  .lh-doc-title { background: #1f7a93; color: #ffffff; text-align: center; padding: 5px 6px; font-weight: 700; font-size: 12.5px; line-height: 1.25; }
  .lh-doc-title .en { display: block; font-size: 8.5px; font-weight: 400; letter-spacing: 0.6px; }
  .lh-doc-meta { width: 100%; border-collapse: collapse; }
  .lh-doc-meta td { padding: 3px 7px; font-size: 9.5px; border-top: 1px solid #d4e7ee; }
  .lh-doc-meta td.k { color: #475569; width: 42%; }
  .lh-doc-meta td.v { font-weight: 700; text-align: right; }
  .rule { height: 3px; background: #1f7a93; margin: 10px 0 2px; }
  .rule-accent { height: 2px; background: #f6a623; width: 120px; margin: 0 0 12px; }
  .box { border: 1px solid #cbd5e1; border-radius: 4px; padding: 8px 10px; }
  .kv { width: 100%; border-collapse: collapse; }
  .kv td { padding: 2px 4px; font-size: 10px; vertical-align: top; }
  .kv td.k { color: #64748b; width: 26%; }
  .kv td.v { font-weight: 700; }
  h2.sec { font-size: 11.5px; color: #0f3d4c; margin: 14px 0 4px; padding-left: 7px; border-left: 4px solid #1f7a93; }
  table.grid { width: 100%; border-collapse: collapse; }
  table.grid th { background: #eaf4f7; color: #0f3d4c; font-weight: 700; font-size: 9px; padding: 5px 6px; border-bottom: 1.5px solid #1f7a93; text-align: left; }
  table.grid td { font-size: 9.5px; padding: 4px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
  table.grid .num { text-align: right; }
  table.grid tr.sub td { font-weight: 700; background: #f8fafc; border-bottom: 1px solid #cbd5e1; }
  .muted { color: #64748b; }
  .small { font-size: 8.5px; }
  .badge { display: inline-block; padding: 0 6px; border-radius: 3px; font-size: 8.5px; font-weight: 700; background: #eaf4f7; color: #1f7a93; }
  .badge.warn { background: #fff4e0; color: #b45309; }
  .sum { width: 100%; border-collapse: collapse; }
  .sum td { padding: 3.5px 8px; font-size: 10.5px; }
  .sum td.k { color: #334155; }
  .sum td.v { text-align: right; font-weight: 700; width: 34%; }
  .sum tr.neg td.v { color: #b91c1c; }
  .sum tr.total td { border-top: 2px solid #1f7a93; background: #eaf4f7; font-size: 13px; color: #0f3d4c; padding-top: 6px; padding-bottom: 6px; }
  .words { font-size: 9.5px; color: #334155; text-align: right; margin-top: 3px; }
  .signs { width: 100%; border-collapse: collapse; margin-top: 34px; }
  .signs td { width: 33%; text-align: center; font-size: 9.5px; color: #334155; padding: 0 10px; vertical-align: bottom; }
  .signs .line { border-top: 1px dotted #64748b; margin: 0 6px 4px; height: 1px; }
  .note { font-size: 8.5px; color: #64748b; margin-top: 12px; line-height: 1.5; }
  .footer { position: fixed; bottom: -26px; left: 0; right: 0; font-size: 8px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 3px; }
  .footer table { width: 100%; border-collapse: collapse; }
  .footer td { padding: 0; }
</style>
