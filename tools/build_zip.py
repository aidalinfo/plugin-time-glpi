#!/usr/bin/env python3

import subprocess
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile


PLUGIN_KEY = "timetracker"
EXCLUDED_DIRS = {".git", ".github", ".agents", ".codex", "dist", "tools", "vendor"}
EXCLUDED_FILES = {".gitignore", ".gitattributes"}


def compile_locales(root: Path) -> None:
    locales_dir = root / "locales"
    for po_file in sorted(locales_dir.glob("*.po")):
        mo_file = po_file.with_suffix(".mo")
        try:
            result = subprocess.run(
                ["msgfmt", str(po_file), "-o", str(mo_file)],
                capture_output=True,
            )
            if result.returncode == 0:
                print(f"msgfmt: {po_file.name} -> {mo_file.name}")
            else:
                print(f"msgfmt unavailable, using pre-compiled {mo_file.name}")
        except FileNotFoundError:
            print(f"msgfmt unavailable, using pre-compiled {mo_file.name}")


def main() -> None:
    root = Path.cwd()
    compile_locales(root)

    output_dir = root / "dist"
    output_dir.mkdir(parents=True, exist_ok=True)
    output = output_dir / f"{PLUGIN_KEY}.zip"

    with ZipFile(output, "w", ZIP_DEFLATED) as archive:
        for path in sorted(root.rglob("*")):
            relative = path.relative_to(root)
            if path.is_dir():
                continue
            if set(relative.parts) & EXCLUDED_DIRS:
                continue
            if relative.name in EXCLUDED_FILES:
                continue
            archive.write(path, Path(PLUGIN_KEY) / relative)

    print(output)


if __name__ == "__main__":
    main()

