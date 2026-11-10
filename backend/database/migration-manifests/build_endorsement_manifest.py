#!/usr/bin/env python3.11
"""
Build the group-insurance endorsement-fold manifest from Application.xlsx.

Pattern C #1 verdict: group insurance (ประกันกลุ่ม PA/health) is ONE policy
whose $N rows are endorsements — positive = member added (collect more),
negative = member left (refund). The legacy export stored each endorsement
as its own Application row sharing Client_Code + Policy_Number. They must
fold into the base policy: base survives, each $N becomes an endorsement
event + its payment moves onto the base.

Grouping: Client_Code + Policy_Number (NOT product — a group policy is one
policy_no). A group qualifies when it has >=1 '$N' endorsement row.

Base (keeper) selection, in order:
  1. the '#N/N' base row if present (the real base application);
  2. else the single dominant positive-premium row (>5x the next positive);
  3. else AMBIGUOUS -> emit action 'flag-ambiguous' (base lives in another
     year/policy_no; leave as-is for manual review).

Endorsement rows (every non-keeper row) carry their Premium as the signed
delta (additionalTotal). Payment_Amount sign in source is unreliable (some
refunds stored positive) — the Premium sign is authoritative.

Output: endorsement_manifest.csv
  group_id, client_code, policy_no, product, keeper_app, member_apps(;),
  n_rows, action
"""
import os, csv, re, pandas as pd
from collections import defaultdict

APP = os.environ.get("APP_XLSX", "Application.xlsx")  # path to legacy Application.xlsx
OUT = os.environ.get("OUT_MANIFEST", "endorsement_manifest.csv")

df = pd.read_excel(APP, dtype=str, keep_default_na=False)
for c in ['Client_Code','Policy_Number','Product_Name','Application_code','App_date','Premium','Note_Type_of_paid']:
    df[c] = df[c].fillna('').map(lambda s: s.strip())

def num(v):
    try:
        f = float(str(v).replace(',',''))
        return f if f == f else None
    except Exception:
        return None

def kind(note):
    if re.match(r'^\$\s*\d', note): return 'endorse'
    if re.search(r'#\d+/\d+', note): return 'base'
    return 'other'

def datekey(dt):
    m = re.match(r'(\d{4})-(\d{2})-(\d{2})', dt or '')
    return m.group(0) if m else '0000-00-00'

groups = defaultdict(list)
for _, r in df.iterrows():
    cc, pn, app = r['Client_Code'], r['Policy_Number'], r['Application_code']
    if cc == '' or pn in ('', '-') or app == '':
        continue
    groups[(cc, pn)].append((app, r['App_date'], r['Product_Name'],
                             num(r['Premium']), kind(r['Note_Type_of_paid']),
                             r['Note_Type_of_paid']))

manifest = []
gid = 0
for (cc, pn), rows in groups.items():
    if not any(k == 'endorse' for (_, _, _, _, k, _) in rows):
        continue                       # not an endorsement group
    if len(rows) <= 1:
        continue
    gid += 1
    product = rows[0][2]
    # base selection
    bases = [r for r in rows if r[4] == 'base']
    keeper = None
    if len(bases) >= 1:
        # most-recent base (largest premium among bases as tie-break)
        keeper = sorted(bases, key=lambda r: (datekey(r[1]), r[3] or 0))[-1][0]
    else:
        pos = sorted([r for r in rows if (r[3] or 0) > 0], key=lambda r: -(r[3] or 0))
        if len(pos) == 1 or (len(pos) >= 2 and (pos[0][3] or 0) > (pos[1][3] or 0) * 5):
            keeper = pos[0][0]
    if keeper is None:
        manifest.append([gid, cc, pn, product, '', '', len(rows), 'flag-ambiguous'])
        continue
    members = [r[0] for r in rows if r[0] != keeper]
    manifest.append([gid, cc, pn, product, keeper, ';'.join(members), len(rows), 'fold-endorsements'])

with open(OUT, 'w', newline='', encoding='utf-8') as fh:
    w = csv.writer(fh)
    w.writerow(['group_id','client_code','policy_no','product','keeper_app','member_apps','n_rows','action'])
    w.writerows(manifest)

fold = [m for m in manifest if m[7] == 'fold-endorsements']
amb = [m for m in manifest if m[7] == 'flag-ambiguous']
rows_fold = sum(m[6] for m in fold)
print(f"endorsement groups: {len(manifest)}  (fold {len(fold)}, ambiguous {len(amb)})")
print(f"  fold: {len(fold)} groups / {rows_fold} rows -> {len(fold)} base policies (-{rows_fold-len(fold)} folded)")
print(f"  ambiguous (left as-is): {len(amb)}")
for m in amb:
    print(f"     {m[1]}/{m[2]} ({m[6]} rows)")
print(f"manifest -> {OUT}")
