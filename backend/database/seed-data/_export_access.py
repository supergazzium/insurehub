#!/usr/bin/env python3.11
"""Export the latest version of each Access XLSX to a clean CSV in this directory."""
from __future__ import annotations
import re
import sys
import unicodedata
from pathlib import Path
from collections import defaultdict

import pandas as pd

SRC = Path("/Users/prachumchanman/Documents/insurehub/Access Database/Database")
DST = Path(__file__).parent

VERSION_RE = re.compile(r"^(?P<stem>.+?)(?:\((?P<n>\d+)\))?\.xlsx$")


# Tables we deliberately don't export — legacy plaintext-password file.
# New backend starts with no legacy passwords; users get a reset link instead.
SKIP_STEMS: frozenset[str] = frozenset({"PW_InsHub"})


def latest_versions(src: Path) -> dict[str, Path]:
    """Group `Foo.xlsx`, `Foo(1).xlsx`, `Foo(2).xlsx` → keep highest n."""
    groups: dict[str, list[tuple[int, Path]]] = defaultdict(list)
    for p in src.glob("*.xlsx"):
        m = VERSION_RE.match(p.name)
        if not m:
            continue
        stem = m.group("stem")
        if stem in SKIP_STEMS:
            continue
        n = int(m.group("n") or 0)
        groups[stem].append((n, p))
    return {stem: max(items)[1] for stem, items in groups.items()}


def normalize_col(col: str) -> str:
    """Make a column name SQL-safe-ish: strip, drop weird whitespace, no commas."""
    s = unicodedata.normalize("NFKC", str(col)).strip()
    s = re.sub(r"\s+", "_", s)
    s = s.replace("/", "_")
    return s


def export_one(stem: str, path: Path, dst: Path) -> tuple[str, int, list[str]]:
    df = pd.read_excel(path, dtype=str, keep_default_na=False)
    df.columns = [normalize_col(c) for c in df.columns]
    # Replace NaN-equivalents with empty string for clean CSV
    df = df.where(df.notna(), "")
    out = dst / f"{stem.lower()}.csv"
    df.to_csv(out, index=False)
    return stem, len(df), list(df.columns)


def main() -> int:
    dst = DST
    dst.mkdir(parents=True, exist_ok=True)
    latest = latest_versions(SRC)
    print(f"# Found {len(latest)} unique tables")
    summary: list[str] = []
    for stem, p in sorted(latest.items()):
        name, rows, cols = export_one(stem, p, dst)
        line = f"{name:40s} rows={rows:>6}  cols={len(cols):>3}"
        print(line)
        summary.append(line)
    (dst / "_access_export_summary.txt").write_text("\n".join(summary) + "\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
