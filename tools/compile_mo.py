#!/usr/bin/env python3
"""Compile .po files to .mo binary format (GNU MO format, little-endian)."""

import re
import struct
from pathlib import Path


def unescape(s: str) -> str:
    return (
        s.replace("\\n", "\n")
         .replace("\\t", "\t")
         .replace('\\"', '"')
         .replace("\\\\", "\\")
    )


def parse_po(content: str) -> list[tuple[str, str]]:
    entries: list[tuple[str, str]] = []
    lines = content.splitlines()
    i = 0
    while i < len(lines):
        line = lines[i].strip()
        if line.startswith("msgid "):
            msgid = unescape(line[7:-1])
            i += 1
            while i < len(lines) and lines[i].strip().startswith('"'):
                msgid += unescape(lines[i].strip()[1:-1])
                i += 1
            while i < len(lines) and not lines[i].strip().startswith("msgstr "):
                i += 1
            if i < len(lines) and lines[i].strip().startswith("msgstr "):
                msgstr = unescape(lines[i].strip()[8:-1])
                i += 1
                while i < len(lines) and lines[i].strip().startswith('"'):
                    msgstr += unescape(lines[i].strip()[1:-1])
                    i += 1
                if msgstr:
                    entries.append((msgid, msgstr))
        else:
            i += 1
    return entries


def compile_mo(entries: list[tuple[str, str]], output: Path) -> None:
    entries = sorted(entries, key=lambda x: x[0])
    n = len(entries)

    orig_tab_off = 28
    trans_tab_off = orig_tab_off + n * 8
    data_off = trans_tab_off + n * 8

    orig_data = b""
    trans_data = b""
    orig_table: list[tuple[int, int]] = []
    trans_table: list[tuple[int, int]] = []

    off = data_off
    for msgid, _ in entries:
        b = msgid.encode("utf-8")
        orig_table.append((len(b), off))
        orig_data += b + b"\x00"
        off += len(b) + 1

    for _, msgstr in entries:
        b = msgstr.encode("utf-8")
        trans_table.append((len(b), off))
        trans_data += b + b"\x00"
        off += len(b) + 1

    with output.open("wb") as f:
        f.write(struct.pack("<IIIIIII",
            0x950412DE,  # magic (little-endian)
            0,           # revision
            n,           # number of strings
            orig_tab_off,
            trans_tab_off,
            0,           # hash table size (unused)
            data_off,    # hash table offset (unused)
        ))
        for length, offset in orig_table:
            f.write(struct.pack("<II", length, offset))
        for length, offset in trans_table:
            f.write(struct.pack("<II", length, offset))
        f.write(orig_data)
        f.write(trans_data)


def main() -> None:
    root = Path(__file__).parent.parent
    locales_dir = root / "locales"

    for po_file in sorted(locales_dir.glob("*.po")):
        mo_file = po_file.with_suffix(".mo")
        content = po_file.read_text(encoding="utf-8")
        entries = parse_po(content)
        compile_mo(entries, mo_file)
        print(f"Compiled {po_file.name} -> {mo_file.name} ({len(entries)} strings)")


if __name__ == "__main__":
    main()
