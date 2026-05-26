#!/usr/bin/env python3

from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile


PLUGIN_KEY = "timetracker"
EXCLUDED_DIRS = {".git", ".github", ".agents", ".codex", "dist", "tools", "vendor"}
EXCLUDED_FILES = {".gitignore", ".gitattributes"}


def main() -> None:
    root = Path.cwd()
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

