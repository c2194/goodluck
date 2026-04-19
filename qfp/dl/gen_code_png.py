#!/usr/bin/env python3
"""生成设备 code.png（400x300，居中二维码 + 下方 MAC-b62 文字）。

Usage:
    python3 gen_code_png.py <mac_b62> <output_path>
"""
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


W, H = 400, 300
QR_MAX = 210   # 二维码在画布上最大占用像素
GAP = 12       # 二维码与文字的间距


def _load_font(size: int) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
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
    # Pillow >= 10 支持 size 参数
    try:
        return ImageFont.load_default(size=size)
    except TypeError:
        return ImageFont.load_default()


def generate(mac_b62: str, output_path: str) -> None:
    # 生成二维码
    qr = qrcode.QRCode(box_size=8, border=2, error_correction=qrcode.constants.ERROR_CORRECT_M)
    qr.add_data(mac_b62)
    qr.make(fit=True)
    qr_img = qr.make_image(fill_color="black", back_color="white").convert("RGB")

    # 缩放到不超过 QR_MAX×QR_MAX
    qr_w, qr_h = qr_img.size
    scale = min(QR_MAX / qr_w, QR_MAX / qr_h, 1.0)
    if scale < 1.0:
        qr_img = qr_img.resize((int(qr_w * scale), int(qr_h * scale)), Image.NEAREST)
    qr_w, qr_h = qr_img.size

    # 准备文字
    font = _load_font(22)
    text = mac_b62

    # 计算布局（垂直居中）
    draw_tmp = ImageDraw.Draw(Image.new("RGB", (1, 1)))
    bbox = draw_tmp.textbbox((0, 0), text, font=font)
    tw = bbox[2] - bbox[0]
    th = bbox[3] - bbox[1]

    total_h = qr_h + GAP + th
    top = max(0, (H - total_h) // 2)

    qr_x = (W - qr_w) // 2
    qr_y = top
    tx = (W - tw) // 2
    ty = qr_y + qr_h + GAP

    # 绘制
    img = Image.new("RGB", (W, H), "white")
    img.paste(qr_img, (qr_x, qr_y))
    draw = ImageDraw.Draw(img)
    draw.text((tx, ty), text, fill="black", font=font)

    img.save(output_path, "PNG")


if __name__ == "__main__":
    if len(sys.argv) < 3:
        raise SystemExit("Usage: gen_code_png.py <mac_b62> <output_path>")
    generate(sys.argv[1], sys.argv[2])
