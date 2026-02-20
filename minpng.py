"""Minimize tri-color PNG size without resizing.

Converts any image to a 3-color paletted PNG (white/black/red) for minimal file size.
Assumes input is already a tri-color image; will quantize to nearest of the 3 colors.

Usage:
  python minpng.py input.png output.png
"""
from __future__ import annotations

import argparse
import math
from pathlib import Path

from PIL import Image  # type: ignore


# Target palette: white, black, red
PALETTE_COLORS = [
    (255, 255, 255),  # 0 = white
    (0, 0, 0),        # 1 = black
    (255, 0, 0),      # 2 = red
]


def color_distance_sq(c1: tuple[int, int, int], c2: tuple[int, int, int]) -> int:
    """Squared Euclidean distance between two RGB colors."""
    return (c1[0] - c2[0]) ** 2 + (c1[1] - c2[1]) ** 2 + (c1[2] - c2[2]) ** 2


def nearest_palette_index(r: int, g: int, b: int) -> int:
    """Find the nearest palette color index for given RGB."""
    pixel = (r, g, b)
    best_idx = 0
    best_dist = color_distance_sq(pixel, PALETTE_COLORS[0])
    for idx, color in enumerate(PALETTE_COLORS[1:], start=1):
        dist = color_distance_sq(pixel, color)
        if dist < best_dist:
            best_dist = dist
            best_idx = idx
    return best_idx


def minimize_tricolor_png(input_path: Path, output_path: Path) -> None:
    """Convert image to minimal 3-color paletted PNG."""
    with Image.open(input_path) as img:
        src = img.convert("RGB")
        w, h = src.size
        pix = src.load()

        # Create paletted image
        out = Image.new("P", (w, h))

        # Set palette: white, black, red + padding
        palette = []
        for color in PALETTE_COLORS:
            palette.extend(color)
        # Pad to 256 colors (768 values)
        palette.extend([0, 0, 0] * (256 - len(PALETTE_COLORS)))
        out.putpalette(palette)

        out_pix = out.load()

        # Quantize each pixel to nearest palette color
        for y in range(h):
            for x in range(w):
                r, g, b = pix[x, y]
                out_pix[x, y] = nearest_palette_index(r, g, b)

        # Save with maximum optimization
        out.save(output_path, format="PNG", optimize=True)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Minimize tri-color PNG size without resizing."
    )
    parser.add_argument("input", help="Input image path")
    parser.add_argument("output", help="Output PNG path")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    input_path = Path(args.input).resolve()
    output_path = Path(args.output).resolve()

    output_path.parent.mkdir(parents=True, exist_ok=True)

    minimize_tricolor_png(input_path, output_path)

    # Report sizes
    in_size = input_path.stat().st_size
    out_size = output_path.stat().st_size
    ratio = (1 - out_size / in_size) * 100 if in_size > 0 else 0

    print(f"Input:  {input_path} ({in_size:,} bytes)")
    print(f"Output: {output_path} ({out_size:,} bytes)")
    print(f"Saved:  {ratio:.1f}%")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
