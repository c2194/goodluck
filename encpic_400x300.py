"""Resize and encode tri-color PNG (black/white/red) for e-ink.

Usage:
  python encpic_400x300.py input.png output.png
  python encpic_400x300.py input.png output.png --width 400 --height 300

Defaults: 400x300
"""
from __future__ import annotations

import argparse
from pathlib import Path

from PIL import Image  # type: ignore

import encpic


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Resize and encode tri-color PNG (black/white/red).")
    parser.add_argument("input", help="Input image path")
    parser.add_argument("output", help="Output PNG path")
    parser.add_argument("--width", type=int, default=400, help="Output width, default 400")
    parser.add_argument("--height", type=int, default=300, help="Output height, default 300")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    input_path = Path(args.input)
    output_path = Path(args.output)

    if not input_path.is_absolute():
        input_path = input_path.resolve()
    if not output_path.is_absolute():
        output_path = output_path.resolve()

    output_path.parent.mkdir(parents=True, exist_ok=True)

    with Image.open(input_path) as im:
        out = encpic.convert_to_tricolor(im, width=args.width, height=args.height, cfg=encpic.TriColorConfig())
        out.save(output_path, format="PNG", optimize=True)

    print(f"OK: {input_path} -> {output_path} ({args.width}x{args.height})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
