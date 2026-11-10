#!/usr/bin/env python3.11
"""
Build the authoritative collapse manifest from the legacy Application.xlsx.

Unified rule (user-confirmed 2026-11-10):
  GROUP BY Client_Code + Policy_Number + Product_Name  -> 1 surviving policy.
  Keeper = latest App_date (then highest Application_code). Survivor shows latest premium.
  All member rows' payment -> payment rows on the keeper.

Per-group overrides from pattern_C_decisions.csv:
  - flag-coinsurance-leave-asis (#5)  -> emit each row as its own policy (NO collapse)
  - everything else collapses by the unified rule (the data-fixes #3/#4 and
    carrier-rename E1/E2 all still collapse per-product; product renames happen
    in-app, not here — the grouping is by product string which already keeps
    the pairs the user said to keep/collapse).

Output CSV columns:
  group_id, client_code, policy_no, product, keeper_app, member_apps(semicolon), n_rows, action
Only groups with n_rows>1 are emitted (single-row policies are untouched).
"""
import sys, csv, pandas as pd, re
from collections import defaultdict

import os
APP = os.environ.get("APP_XLSX", "Application.xlsx")  # path to legacy Application.xlsx
DEC = os.environ.get("PATTERN_C_DECISIONS", "pattern_C_decisions.csv")
OUT = os.environ.get("OUT_MANIFEST", "collapse_manifest.csv")

df = pd.read_excel(APP, dtype=str, keep_default_na=False)
# normalize key fields
for col in ['Client_Code','Policy_Number','Product_Name','Application_code','App_date']:
    df[col] = df[col].fillna('').map(lambda s: s.strip())

# ---- NAMED PER-GROUP OVERRIDES (user verdicts #3/#4) ----
# These (client_code, policy_no) pairs are handled EXPLICITLY, bypassing the
# product-string grouping below. Key -> list of (keeper_app, [member_apps], action).
#
# #3 C0005661/509-1037126: ONE policy. iProtect85(PDKTA0004) is main; the 4
#    "iCare"(PDKTA0011) rows are a mis-entered rider. Collapse ALL 12 rows into
#    1 policy (keeper = latest iProtect A2608240025). Rider product correction
#    PDKTA0011->PDKTA0016/0019 is a separate in-app metadata fix, not here.
#
# #4 C8000343/83002286: NOT a full collapse (user: "keep the rest separate").
#    (a) product-fix 2 health rows -> accident (A2406180002, A2501210001);
#    (b) fold $1 endorsement A2206060006 INTO A2108260001;
#    (c) every other #1/1 row stays its OWN annual policy.
NAMED_OVERRIDES = {
    ('C0005661', '509-1037126'): [
        ('A2608240025',
         ['A2608240024','A2108160006','A2108160010','A2209010007','A2209010008',
          'A2409250001','A2409250002','A2308190020','A2308190021','A2510210002','A2510210003'],
         'collapse-datafix3'),
    ],
    ('C8000343', '83002286'): [
        # only the $1 endorsement folds into its base annual policy; rest stay separate
        ('A2108260001', ['A2206060006'], 'endorsement-fold4'),
        # the 2 health rows get a product-fix but remain their own policies (no collapse)
        # recorded as productfix markers so the command can apply them
    ],
}
# product-fix instructions (client, policy, application_code) -> corrected product note
PRODUCT_FIXES = [
    ('C8000343','83002286','A2406180002','Group ประกันอุบัติเหตุ Package'),
    ('C8000343','83002286','A2501210001','Group ประกันอุบัติเหตุ Package'),
]

# load co-insurance leave-as-is groups (and any other explicit no-collapse) from decisions
leave_asis = set()   # (client_code, policy_no) pairs to NOT collapse at all
dec_rows = []
with open(DEC, encoding='utf-8') as fh:
    for line in fh:
        line=line.rstrip('\n')
        if not line or line.startswith('n,client_code'): continue
        # file is pipe-delimited for the detailed rows, comma for the first header-ish rows
        parts = line.split('|') if '|' in line else line.split(',')
        if len(parts) < 4: continue
        n, cc, pn, verdict = parts[0], parts[1], parts[2], parts[3]
        dec_rows.append((cc, pn, verdict))
        if 'coinsurance' in verdict or 'leave-asis' in verdict:
            leave_asis.add((cc, pn))

def datekey(dt):
    # dt like '2024-02-16 00:00:00' or '' -> sortable 'YYYY-MM-DD'
    m = re.match(r'(\d{4})-(\d{2})-(\d{2})', dt or '')
    return m.group(0) if m else '0000-00-00'

LIFE_KW = ['ตลอดชีพ','สะสมทรัพย์','ออมทรัพย์','เงินปันผล','ซิมเพิลไลฟ์','ไลฟ์','โพรเทคชั่น',
           'mrta','ห่วงรัก','คุ้มธนกิจ','คุ้มสุข','อินคัม','ไฮ แวลู','ออล ไลฟ์','เรดดี้','สมาร์ท',
           'wl ','w99','@90','@85','ชำระเบี้ย','เวลธ์','เพรสทีจ','ธนกิจ','บำนาญ','แอนนิวตี้',
           'เซฟวิ่ง','savings','เลกาซี่','annuity','ซูเปอร์คุ้ม','แฮปปี้เซฟ','สุขบำนาญ','เวลธ์ตี้']
ANNUAL_KW = ['รถยนต์','พรบ','เดินทาง','travel','อัคคีภัย','สัตว์เลี้ยง','วิชาชีพ','ทรัพย์สิน',
             'covid','โควิด','อุบัติเหตุ','pa ','พีเอ','ขนส่ง','มะเร็ง','กอล์ฟ','ทางทะเล',
             'สุขภาพ','ไวรัส','sme','ความเสี่ยงภัย','iar','ความรับผิด','ต่อภาษี','ต่อ ภาษี',
             'ประกันกลุ่ม','ประกันชีวิตกลุ่ม','ประกันภัย']
_RATIO = re.compile(r'\b\d{1,3}/\d{1,2}\b')

def is_wholelife(prod):
    """#1/1 annual rows: whole-life / savings / pension / paying-term products
    collapse to 1 policy; annual-renewable (motor/พรบ/travel/fire/health/PA/covid)
    stay separate. Default (no clear signal) = annual (keep separate, safer)."""
    p = prod.lower()
    if any(k in p for k in LIFE_KW):
        return True
    if any(k in p for k in ANNUAL_KW):
        return False
    if _RATIO.search(prod):   # a paying-term ratio with no annual keyword = life
        return True
    return False

def inst_n(note):
    """Installment index N from a '[prefix]#N/M' note. The leading prefix
    (e.g. '3#','4#') is the contract-year marker and is skipped. Returns the N
    of the #N/M, or None if the note has no #N/M (e.g. '$1' endorsement, '')."""
    m = re.search(r'#(\d+)/(\d+)', note or '')
    return int(m.group(1)) if m else None

def inst_mn(note):
    """(N, M) from '[prefix]#N/M', or (None, None) if the note has no #N/M."""
    m = re.search(r'#(\d+)/(\d+)', note or '')
    return (int(m.group(1)), int(m.group(2))) if m else (None, None)

# CORRECTED RULE v3 (2026-11-10): A renewal/installment policy is "one yearly
# contract paid in M installments". The reliable contract boundary is the
# installment sequence RESET: a new contract starts at every '#1/M'. App_date
# alone split a contract whose rows drifted by a day (50170797 yr4). The '$N'
# endorsement rows (no #N/M) attach to the current contract, they don't start one.
#
# Stage 1: bucket rows by client+policy+product (sorted by application_code).
# Stage 2: within a bucket, split into contracts on each #1/M (or first #N/M run).
override_policies = set(NAMED_OVERRIDES.keys())
buckets = defaultdict(list)   # (cc,pn,prod) -> [(app, dt, note), ...]
for _, r in df.iterrows():
    cc, pn, prod, app, dt = r['Client_Code'], r['Policy_Number'], r['Product_Name'], r['Application_code'], r['App_date']
    note = r['Note_Type_of_paid']
    if cc=='' or pn=='' or app=='':
        continue
    if pn == '-':
        continue
    if (cc, pn) in override_policies:
        continue
    buckets[(cc, pn, prod)].append((app, dt, note))

manifest = []
gid = 0
multi_rowcount = 0
for (cc, pn, prod), rows in buckets.items():
    if (cc, pn) in leave_asis:
        if len(rows) > 1:
            gid += 1
            manifest.append([gid, cc, pn, prod, '', '', len(rows), 'leave-asis'])
        continue
    rows_sorted = sorted(rows, key=lambda x: x[0])   # by application_code
    # Classify the bucket's installment shape.
    seqs = [inst_mn(note) for (_, _, note) in rows_sorted]
    has_multi_inst = any(m is not None and m > 1 for (n, m) in seqs)   # any #N/M with M>1

    if not has_multi_inst:
        # Pure #1/1 (or no-#) annual rows: product family decides.
        #  whole-life/savings/pension -> collapse ALL rows to 1 policy.
        #  annual-renewable            -> keep each year separate (NO collapse).
        if is_wholelife(prod) and len(rows_sorted) > 1:
            multi_rowcount += len(rows_sorted)
            gid += 1
            ms = sorted(rows_sorted, key=lambda x: (datekey(x[1]), x[0]))
            keeper = ms[-1][0]
            members = [a for (a, _, _) in ms if a != keeper]
            manifest.append([gid, cc, pn, prod, keeper, ';'.join(members), len(rows_sorted), 'collapse'])
        # else: annual-renewable #1/1 -> leave as separate policies (emit nothing)
        continue

    # Has #N/M installments (M>1): split into contracts on installment resets;
    # each yearly contract -> 1 policy + its installment payments.
    contracts = []   # list of lists of (app, dt)
    cur = []
    for (app, dt, note) in rows_sorted:
        n = inst_n(note)
        if n == 1 and cur:        # a new #1/M after we already have rows -> new contract
            contracts.append(cur); cur = []
        cur.append((app, dt))
    if cur:
        contracts.append(cur)
    for members_rows in contracts:
        if len(members_rows) <= 1:
            continue
        multi_rowcount += len(members_rows)
        gid += 1
        ms = sorted(members_rows, key=lambda x: (datekey(x[1]), x[0]))
        keeper = ms[-1][0]
        members = [a for (a, _) in ms if a != keeper]
        manifest.append([gid, cc, pn, prod, keeper, ';'.join(members), len(members_rows), 'collapse'])

# append named overrides (#3/#4)
for (cc, pn), specs in NAMED_OVERRIDES.items():
    for (keeper, members, action) in specs:
        gid += 1
        manifest.append([gid, cc, pn, '(override)', keeper, ';'.join(members), 1+len(members), action])

# write product-fix companion CSV
PFX = OUT.replace('collapse_manifest.csv', 'collapse_product_fixes.csv')
with open(PFX, 'w', newline='', encoding='utf-8') as fh:
    w = csv.writer(fh); w.writerow(['client_code','policy_no','application_code','corrected_product'])
    w.writerows(PRODUCT_FIXES)
print(f"product-fixes -> {PFX} ({len(PRODUCT_FIXES)} rows)")

with open(OUT, 'w', newline='', encoding='utf-8') as fh:
    w = csv.writer(fh)
    w.writerow(['group_id','client_code','policy_no','product','keeper_app','member_apps','n_rows','action'])
    w.writerows(manifest)

n_collapse = sum(1 for m in manifest if m[7]=='collapse')
n_leave = sum(1 for m in manifest if m[7]=='leave-asis')
rows_collapsed = sum(m[6] for m in manifest if m[7]=='collapse')
survivors = n_collapse  # 1 per collapse group
removed = rows_collapsed - survivors
print(f"multi-row groups: {len(manifest)}  (collapse {n_collapse}, leave-asis {n_leave})")
print(f"rows in multi-row groups: {multi_rowcount}")
print(f"  collapse groups: {n_collapse} groups / {rows_collapsed} rows -> {survivors} survivors (-{removed})")
print(f"  leave-asis groups: {n_leave}")
print(f"manifest -> {OUT}")
