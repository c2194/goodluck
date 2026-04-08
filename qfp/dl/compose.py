"""合成祝福牌：缩放背景图 + 叠加透明文字层 → 三色 300x400 → 旋转 → 400x300 调色板 PNG。

Usage:
  python compose.py bg.png text_layer.png output.png
  python compose.py bg.png output.png              # 无文字叠加层，仅处理背景
"""
import sys
from pathlib import Path
from PIL import Image
import numpy as np

COMPOSE_W, COMPOSE_H = 270, 400   # 合成尺寸（与模板编辑器坐标系一致）
OUTPUT_W, OUTPUT_H   = 300, 400   # 最终画布尺寸

PALETTE = np.array([
    [255, 255, 255],  # 白
    [0,   0,   0],    # 黑
    [255, 0,   0],    # 红
], dtype=np.float32)

PALETTE_TUPLES = [(255, 255, 255), (0, 0, 0), (255, 0, 0)]


def nearest_index(r, g, b):
    best, best_d = 0, float('inf')
    for i, (pr, pg, pb) in enumerate(PALETTE_TUPLES):
        d = (r - pr) ** 2 + (g - pg) ** 2 + (b - pb) ** 2
        if d < best_d:
            best_d = d
            best = i
    return best


def quantize_tricolor(img):
    """将 RGB 图片每个像素量化为最近的黑/白/红。"""
    arr = np.array(img.convert('RGB'), dtype=np.float32)
    h, w, _ = arr.shape
    pixels = arr.reshape(-1, 3)
    dists = np.stack([np.sum((pixels - c) ** 2, axis=1) for c in PALETTE], axis=1)
    indices = np.argmin(dists, axis=1)
    result = PALETTE[indices].astype(np.uint8).reshape(h, w, 3)
    return Image.fromarray(result)


def overlay_text(base, overlay_path):
    """将透明 PNG 文字层叠加到底图上，alpha>0 的像素量化后覆盖。"""
    ov = Image.open(overlay_path).convert('RGBA')
    if ov.size != (COMPOSE_W, COMPOSE_H):
        ov = ov.resize((COMPOSE_W, COMPOSE_H), Image.LANCZOS)
    ov_arr = np.array(ov)
    base_arr = np.array(base.convert('RGB'), dtype=np.uint8)
    rgb = ov_arr[:, :, :3].astype(np.float32)

    pixels = rgb.reshape(-1, 3)
    dists = np.stack([np.sum((pixels - c) ** 2, axis=1) for c in PALETTE], axis=1)
    indices = np.argmin(dists, axis=1)
    quantized = PALETTE[indices].astype(np.uint8).reshape(ov_arr.shape[0], ov_arr.shape[1], 3)

    mask = ov_arr[:, :, 3] > 0
    base_arr[mask] = quantized[mask]

    return Image.fromarray(base_arr)


def compose(bg_path, text_path, output_path):
    # 1) 缩放背景图到合成尺寸，三色量化
    bg = Image.open(bg_path)
    bg = bg.resize((COMPOSE_W, COMPOSE_H), Image.LANCZOS)
    bg = quantize_tricolor(bg)

    # 2) 叠加透明文字层
    if text_path:
        bg = overlay_text(bg, text_path)

    # 3) 创建 300x400 白色画布，将合成图靠左贴入
    canvas = Image.new('RGB', (OUTPUT_W, OUTPUT_H), (255, 255, 255))
    offset_x = 0
    offset_y = (OUTPUT_H - COMPOSE_H) // 2
    canvas.paste(bg, (offset_x, offset_y))

    # 4) 逆时针旋转 90° → 400x300
    canvas = canvas.transpose(Image.Transpose.ROTATE_90)

    # 5) 保存为 3 色调色板 PNG
    w, h = canvas.size
    canvas_pix = canvas.load()
    out = Image.new('P', (w, h))
    pal = []
    for c in PALETTE_TUPLES:
        pal.extend(c)
    pal.extend([0, 0, 0] * (256 - len(PALETTE_TUPLES)))
    out.putpalette(pal)
    out_pix = out.load()

    for y in range(h):
        for x in range(w):
            r, g, b = canvas_pix[x, y]
            out_pix[x, y] = nearest_index(r, g, b)

    out.save(output_path, format='PNG', optimize=True)
    print(f'{bg_path} + {text_path or "(无文字层)"} -> {output_path}  ({w}x{h}, 三色调色板)')


if __name__ == '__main__':
    if len(sys.argv) < 3:
        print(f'用法: python {Path(__file__).name} bg.png text_layer.png output.png')
        print(f'      python {Path(__file__).name} bg.png output.png')
        sys.exit(1)

    if len(sys.argv) >= 4:
        compose(sys.argv[1], sys.argv[2], sys.argv[3])
    else:
        compose(sys.argv[1], None, sys.argv[2])
