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

# ===== Preprocess config (modify here) =====
DEFAULT_CONTRAST = 50   # 对比度 1-99, 50=不变
DEFAULT_RT = 0          # 红色阈值 (%)
DEFAULT_RB = 0          # 红色增量 (% of 255)
DEFAULT_BT = 0          # 黑色阈值 (%)
DEFAULT_BB = 0          # 黑色增量 (% of 255)
DEFAULT_WT = 0          # 白色阈值 (%)
DEFAULT_WB = 0          # 白色增量 (% of 255)
# ============================================


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


def preprocess_colors(src: Image.Image,
                      rt: int, rb: int,
                      bt: int, bb: int,
                      wt: int, wb: int) -> Image.Image:
    """Preprocess image by boosting dominant color channels.

    For each pixel, compute black/white/red proportions.
    If a proportion >= threshold, push that pixel toward the target color.

    Args:
      rt, rb: red threshold (%) and red boost (% of 255)
      bt, bb: black threshold (%) and black boost (% of 255)
      wt, wb: white threshold (%) and white boost (% of 255)
    """
    src = src.copy()
    w, h = src.size
    pix = src.load()
    for y in range(h):
        for x in range(w):
            r, g, b = pix[x, y]
            # Compute proportions
            min_gb = min(g, b)
            w_white = min_gb / 255.0
            w_red = max(0.0, (r - min_gb) / 255.0)
            w_black = max(0.0, 1.0 - w_white - w_red)
            pct_white = w_white * 100
            pct_red = w_red * 100
            pct_black = w_black * 100
            # Apply boosts
            delta = 255.0
            nr, ng, nb = float(r), float(g), float(b)
            if pct_red >= rt and rb != 0:
                nr = nr + delta * rb / 100.0  # push R up
                ng = ng - delta * rb / 100.0  # push G down
                nb = nb - delta * rb / 100.0  # push B down
            if pct_black >= bt and bb != 0:
                nr = nr - delta * bb / 100.0  # push RGB down
                ng = ng - delta * bb / 100.0
                nb = nb - delta * bb / 100.0
            if pct_white >= wt and wb != 0:
                nr = nr + delta * wb / 100.0  # push RGB up
                ng = ng + delta * wb / 100.0
                nb = nb + delta * wb / 100.0
            pix[x, y] = (max(0, min(255, int(nr))),
                         max(0, min(255, int(ng))),
                         max(0, min(255, int(nb))))
    return src


def minimize_tricolor_png(input_path: Path, output_path: Path,
                          contrast: int = 50,
                          rt: int = 0, rb: int = 0,
                          bt: int = 0, bb: int = 0,
                          wt: int = 0, wb: int = 0) -> None:
    """Convert image to minimal 3-color paletted PNG.

    Processing pipeline:
      1. Pad to 300x400 (white fill, no scaling, original centered horizontally).
      2. Rotate 90° clockwise → 400x300.
      3. (Optional) Adjust contrast.
      4. (Optional) Color preprocess — boost black/white/red.
      5. Quantize to 3-color palette.
    """
    with Image.open(input_path) as img:
        src = img.convert("RGB")
        w, h = src.size

        # Step 1: pad to 300x400, center horizontally, white fill
        target_w, target_h = 300, 400
        if w != target_w or h != target_h:
            padded = Image.new("RGB", (target_w, target_h), (255, 255, 255))
            offset_x = 0
            offset_y = (target_h - h) // 2
            padded.paste(src, (offset_x, offset_y))
            src = padded

        # Step 2: rotate 90° counter-clockwise → 400x300
        src = src.transpose(Image.Transpose.ROTATE_90)

        # Step 2.5: adjust contrast (50=unchanged, 1=min, 99=max)
        if contrast != 50:
            from PIL import ImageEnhance
            factor = contrast / 50.0  # 1→0.02, 50→1.0, 99→1.98
            src = ImageEnhance.Contrast(src).enhance(factor)

        # Step 3: color preprocess — boost dominant colors
        has_preprocess = any(v != 0 for v in (rb, bb, wb))
        if has_preprocess:
            src = preprocess_colors(src, rt, rb, bt, bb, wt, wb)
            src.show(title="Preprocess Result")

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
    parser.add_argument("-c", type=str, default=None,
                        help="Config: contrast,rt,rb,bt,bb,wt,wb (e.g. -c 50,5,20,10,15,10,15)")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    input_path = Path(args.input).resolve()
    output_path = Path(args.output).resolve()

    # Parse -c parameter or use defaults
    if args.c:
        parts = [int(x.strip()) for x in args.c.split(',')]
        if len(parts) != 7:
            print('Error: -c requires exactly 7 values: contrast,rt,rb,bt,bb,wt,wb')
            return 1
        contrast, rt, rb, bt, bb, wt, wb = parts
    else:
        contrast = DEFAULT_CONTRAST
        rt, rb = DEFAULT_RT, DEFAULT_RB
        bt, bb = DEFAULT_BT, DEFAULT_BB
        wt, wb = DEFAULT_WT, DEFAULT_WB
    contrast = max(1, min(99, contrast))

    output_path.parent.mkdir(parents=True, exist_ok=True)

    minimize_tricolor_png(input_path, output_path,
                           contrast=contrast,
                           rt=rt, rb=rb,
                           bt=bt, bb=bb,
                           wt=wt, wb=wb)

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
