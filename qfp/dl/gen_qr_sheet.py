#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
gen_qr_sheet.py
生成二维码排版图片：300mm × 200mm，300DPI，8列 × 3行，每个二维码 20mm × 20mm。

用法:
    python3 gen_qr_sheet.py <urls_json_file> <output_path.png>

urls_json_file 内容为 JSON 数组，例如：
    ["https://example.com/1", "https://example.com/2", ...]
最多取前 24 个 URL。
"""

import json
import os
import sys

try:
    from PIL import Image, ImageDraw, ImageFont
except ImportError:
    raise SystemExit("Missing dependency: pip install pillow")

try:
    import qrcode
except ImportError:
    raise SystemExit("Missing dependency: pip install qrcode[pil]")


# ── 尺寸常量 ────────────────────────────────────────────
DPI       = 300
MM_TO_PX  = DPI / 25.4          # ≈ 11.811 px/mm

PAGE_W    = round(300 * MM_TO_PX)   # 3543 px
PAGE_H    = round(200 * MM_TO_PX)   # 2362 px
QR_PX     = round(20  * MM_TO_PX)   #  236 px  (20mm 二维码)

COLS      = 8
ROWS      = 3
MAX_QR    = COLS * ROWS              # 24

# ── 固定排位坐标（mm）─────────────────────────────────
# 各行第 1 个二维码左边缘 x（mm），步进 35mm
ROW_X_BASE_MM = [24.5, 24.3, 24.0]
X_STEP_MM  = 35
# 各行二维码上边缘 y（mm）
ROW_Y_MM   = [12.5, 56.5, 164.5]


def _load_font(size: int):
    candidates = [
        "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
        "/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf",
        "/usr/share/fonts/truetype/freefont/FreeSans.ttf",
        "/usr/share/fonts/ttf-dejavu/DejaVuSans.ttf",
        "/usr/share/fonts/dejavu/DejaVuSans.ttf",
    ]
    for path in candidates:
        if os.path.isfile(path):
            try:
                return ImageFont.truetype(path, size)
            except Exception:
                pass
    try:
        return ImageFont.load_default(size=size)
    except TypeError:
        return ImageFont.load_default()


def generate(urls: list, output_path: str) -> None:
    urls = urls[:MAX_QR]

    canvas = Image.new("RGB", (PAGE_W, PAGE_H), "white")

    # 左上角定位点（1px 黑点）
    canvas.putpixel((0, 0), (0, 0, 0))

    # 预计算各行各列的像素起始坐标
    xs_per_row = [
        [round((ROW_X_BASE_MM[r] + col * X_STEP_MM) * MM_TO_PX) for col in range(COLS)]
        for r in range(ROWS)
    ]
    ys = [round(y_mm * MM_TO_PX) for y_mm in ROW_Y_MM]

    for idx, url in enumerate(urls):
        row = idx // COLS
        col = idx % COLS
        x   = xs_per_row[row][col]
        y   = ys[row]

        qr = qrcode.QRCode(
            box_size=4,
            border=1,
            error_correction=qrcode.constants.ERROR_CORRECT_M,
        )
        qr.add_data(url)
        qr.make(fit=True)
        qr_img = qr.make_image(fill_color="black", back_color="white").convert("RGB")
        qr_img = qr_img.resize((QR_PX, QR_PX), Image.NEAREST)

        canvas.paste(qr_img, (x, y))

    canvas.save(output_path, "PNG", dpi=(DPI, DPI))
    print(f"saved: {output_path}  ({PAGE_W}x{PAGE_H} px, {DPI} dpi, {len(urls)} QR codes)")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("Usage: gen_qr_sheet.py <urls_json_file> <output_path.png>", file=sys.stderr)
        sys.exit(1)

    json_file   = sys.argv[1]
    output_path = sys.argv[2]

    with open(json_file, "r", encoding="utf-8") as f:
        data = json.load(f)

    if isinstance(data, list):
        urls = data
    elif isinstance(data, dict):
        urls = data.get("urls", [])
    else:
        print("Error: JSON must be a list or {'urls': [...]}", file=sys.stderr)
        sys.exit(1)

    generate(urls, output_path)
