"""将 540x800 图片缩小为 270x400，并量化为黑白红三色。

Usage:
  python resize.py input.png output.png
  python resize.py input.png output.png overlay.png   # 叠加透明文字图层
  python resize.py input.png              # 覆盖原文件
"""
import sys
from pathlib import Path
from PIL import Image
import numpy as np

TARGET_W, TARGET_H = 270, 400

# 三色调色板
PALETTE = np.array([
    [255, 255, 255],  # 白
    [0,   0,   0],    # 黑
    [255, 0,   0],    # 红
], dtype=np.float32)


def find_nearest(pixel):
    """找到最近的调色板颜色，返回 (索引, 调色板色)。"""
    dists = np.sum((PALETTE - pixel) ** 2, axis=1)
    idx = np.argmin(dists)
    return PALETTE[idx]


def quantize_tricolor(img: Image.Image) -> Image.Image:
    """将图片每个像素量化为最近的黑/白/红三色。"""
    arr = np.array(img.convert('RGB'), dtype=np.float32)
    h, w, _ = arr.shape
    pixels = arr.reshape(-1, 3)
    dists = np.stack([np.sum((pixels - c) ** 2, axis=1) for c in PALETTE], axis=1)
    indices = np.argmin(dists, axis=1)
    result = PALETTE[indices].astype(np.uint8).reshape(h, w, 3)
    return Image.fromarray(result)


def quantize_nearest(pixel):
    """不带抖动的最近色量化（用于叠加层）。"""
    dists = np.sum((PALETTE - pixel[:3]) ** 2, axis=1)
    return PALETTE[np.argmin(dists)]


def overlay_tricolor(base: Image.Image, overlay_path: str) -> Image.Image:
    """将透明 PNG 叠加层量化为三色后覆盖到底图上，忽略透明度。"""
    ov = Image.open(overlay_path).convert('RGBA')
    if ov.size != (TARGET_W, TARGET_H):
        ov = ov.resize((TARGET_W, TARGET_H), Image.LANCZOS)
    ov_arr = np.array(ov)
    base_arr = np.array(base.convert('RGB'), dtype=np.uint8)
    rgb = ov_arr[:, :, :3].astype(np.float32)

    # 忽略透明度，对每个像素的 RGB 做最近色量化
    pixels = rgb.reshape(-1, 3)
    dists = np.stack([np.sum((pixels - c) ** 2, axis=1) for c in PALETTE], axis=1)
    indices = np.argmin(dists, axis=1)
    quantized = PALETTE[indices].astype(np.uint8).reshape(ov_arr.shape[0], ov_arr.shape[1], 3)

    # 完全透明(alpha==0)的像素保持底图，其余像素覆盖底图
    alpha = ov_arr[:, :, 3]
    mask = alpha > 0
    base_arr[mask] = quantized[mask]

    return Image.fromarray(base_arr)


def resize(src: str, dst: str | None = None, overlay: str | None = None):
    dst = dst or src
    img = Image.open(src)
    img = img.resize((TARGET_W, TARGET_H), Image.LANCZOS)
    img = quantize_tricolor(img)
    if overlay:
        img = overlay_tricolor(img, overlay)
    img.save(dst)
    extra = ' + 叠加层' if overlay else ''
    print(f"{src} -> {dst}  ({TARGET_W}x{TARGET_H}, 三色{extra})")


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(f"用法: python {Path(__file__).name} input.png [output.png] [overlay.png]")
        sys.exit(1)
    src = sys.argv[1]
    dst = sys.argv[2] if len(sys.argv) > 2 else src
    ov  = sys.argv[3] if len(sys.argv) > 3 else None
    resize(src, dst, ov)
