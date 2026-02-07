"""Tri-color (black/white/red) image conversion for e-ink displays.

Outputs a paletted PNG with 3 colors:
	0 = white, 1 = black, 2 = red

Usage:
	# single file (keeps original size if no --width/--height)
	python encpic.py input.jpg output.png

	# batch: convert all images in a folder (keeps original size)
	python encpic.py web/py/pic web/py/pic_out
"""

from __future__ import annotations

import argparse
import colorsys
import os
import sys
from dataclasses import dataclass
from pathlib import Path


try:
	from PIL import Image
except Exception as exc:  # pragma: no cover
	raise SystemExit(
		"Missing dependency: Pillow. Install with: pip install pillow\n"
		f"Original error: {exc}"
	)


@dataclass(frozen=True)
class TriColorConfig:
	# Red detection in HSV
	hue_width: float = 0.04  # 0..0.5, ~0.04 ~= 14 degrees
	sat_threshold: float = 0.45
	val_threshold: float = 0.20

	# Red detection fallback in RGB
	min_r: int = 70
	r_ratio: float = 1.25
	red_delta: int = 60  # require R - max(G,B) >= red_delta

	# Red dithering
	red_dither: bool = False
	red_threshold: int = 127

	# Preprocess grayscale (non-red areas) to keep details on e-ink
	auto_levels: bool = True
	auto_levels_percent: float = 1.0  # clip both tails by this percent
	gamma: float = 1.0

	# Black/white threshold (after dithering it becomes a pivot)
	bw_threshold: int = 127

	# Dithering behavior
	serpentine: bool = True


def _is_red_pixel(r: int, g: int, b: int, cfg: TriColorConfig) -> bool:
	# HSV-based: catch "real" reds even under different lighting
	rf, gf, bf = r / 255.0, g / 255.0, b / 255.0
	h, s, v = colorsys.rgb_to_hsv(rf, gf, bf)
	red_bias = r - max(g, b)
	if s >= cfg.sat_threshold and v >= cfg.val_threshold and red_bias >= cfg.red_delta:
		if h <= cfg.hue_width or h >= (1.0 - cfg.hue_width):
			return True

	# RGB fallback: handle saturated reds that HSV sometimes misses
	if (
		r >= cfg.min_r
		and red_bias >= cfg.red_delta
		and r >= g * cfg.r_ratio
		and r >= b * cfg.r_ratio
	):
		return True

	return False


def _red_strength(r: int, g: int, b: int, cfg: TriColorConfig) -> float:
	"""Return 0..255 indicating how strongly this pixel should be red."""

	rf, gf, bf = r / 255.0, g / 255.0, b / 255.0
	h, s, v = colorsys.rgb_to_hsv(rf, gf, bf)
	red_bias = r - max(g, b)

	# Only consider red-ish hues
	if not (h <= cfg.hue_width or h >= (1.0 - cfg.hue_width)):
		return 0.0

	# Soft ramps above thresholds so dithering can create gradients.
	# Keep it conservative to avoid turning skin into red.
	if s < cfg.sat_threshold or v < cfg.val_threshold or red_bias < cfg.red_delta:
		return 0.0

	s01 = (s - cfg.sat_threshold) / max(1e-6, (1.0 - cfg.sat_threshold))
	v01 = (v - cfg.val_threshold) / max(1e-6, (1.0 - cfg.val_threshold))
	bias01 = (red_bias - cfg.red_delta) / max(1e-6, (255.0 - cfg.red_delta))
	s01 = 0.0 if s01 < 0.0 else 1.0 if s01 > 1.0 else s01
	v01 = 0.0 if v01 < 0.0 else 1.0 if v01 > 1.0 else v01
	bias01 = 0.0 if bias01 < 0.0 else 1.0 if bias01 > 1.0 else bias01

	return 255.0 * (s01 * v01 * bias01)


def _to_luma(r: int, g: int, b: int) -> float:
	# ITU-R BT.601 luma
	return 0.299 * r + 0.587 * g + 0.114 * b


def _apply_auto_levels_and_gamma(
	luma_rows: list[list[float]],
	red_mask: list[list[bool]],
	clip_percent: float,
	gamma: float,
) -> list[list[float]]:
	"""Stretch contrast on non-red pixels, then apply gamma. Returns new luma rows."""

	height = len(luma_rows)
	width = len(luma_rows[0]) if height else 0
	if height == 0 or width == 0:
		return luma_rows

	clip_percent = max(0.0, min(20.0, float(clip_percent)))
	gamma = float(gamma)

	hist = [0] * 256
	count = 0
	for y in range(height):
		for x in range(width):
			if red_mask[y][x]:
				continue
			v = int(round(luma_rows[y][x]))
			v = 0 if v < 0 else 255 if v > 255 else v
			hist[v] += 1
			count += 1

	if count == 0:
		return luma_rows

	# Find low/high cut based on percentiles
	cut = int(count * (clip_percent / 100.0))

	low = 0
	acc = 0
	for i in range(256):
		acc += hist[i]
		if acc > cut:
			low = i
			break

	high = 255
	acc = 0
	for i in range(255, -1, -1):
		acc += hist[i]
		if acc > cut:
			high = i
			break

	if high <= low:
		return luma_rows

	scale = 255.0 / (high - low)

	# Apply stretch and gamma (only to non-red)
	out = [row[:] for row in luma_rows]
	use_gamma = abs(gamma - 1.0) > 1e-6
	for y in range(height):
		for x in range(width):
			if red_mask[y][x]:
				out[y][x] = 255.0
				continue
			v = out[y][x]
			v = (v - low) * scale
			if v < 0.0:
				v = 0.0
			elif v > 255.0:
				v = 255.0
			if use_gamma:
				v01 = v / 255.0
				v01 = 0.0 if v01 < 0.0 else 1.0 if v01 > 1.0 else v01
				v = 255.0 * (v01 ** gamma)
			out[y][x] = v

	return out


def _floyd_steinberg_dither_plane(values: list[list[float]], threshold: int, serpentine: bool) -> list[list[int]]:
	"""Generic Floyd–Steinberg dithering on 0..255 values.

	Returns 0/255 plane.
	"""

	height = len(values)
	width = len(values[0]) if height else 0
	out = [[255] * width for _ in range(height)]

	# Work on a mutable copy
	buf = [row[:] for row in values]

	for y in range(height):
		left_to_right = (y % 2 == 0) or (not serpentine)
		x_iter = range(width) if left_to_right else range(width - 1, -1, -1)

		for x in x_iter:
			old = buf[y][x]
			new = 0.0 if old < threshold else 255.0
			out[y][x] = int(new)
			err = old - new

			if left_to_right:
				if x + 1 < width:
					buf[y][x + 1] += err * 7 / 16
				if y + 1 < height:
					if x - 1 >= 0:
						buf[y + 1][x - 1] += err * 3 / 16
					buf[y + 1][x] += err * 5 / 16
					if x + 1 < width:
						buf[y + 1][x + 1] += err * 1 / 16
			else:
				if x - 1 >= 0:
					buf[y][x - 1] += err * 7 / 16
				if y + 1 < height:
					if x + 1 < width:
						buf[y + 1][x + 1] += err * 3 / 16
					buf[y + 1][x] += err * 5 / 16
					if x - 1 >= 0:
						buf[y + 1][x - 1] += err * 1 / 16

	return out


def _floyd_steinberg_dither_bw(luma_rows: list[list[float]], threshold: int, serpentine: bool) -> list[list[int]]:
	"""Return 0/255 image (0=black, 255=white)."""
	return _floyd_steinberg_dither_plane(luma_rows, threshold, serpentine)


def convert_to_tricolor(
	img: Image.Image,
	width: int | None = None,
	height: int | None = None,
	cfg: TriColorConfig | None = None,
) -> Image.Image:
	"""Convert an image to a 3-color paletted image: white/black/red."""

	cfg = cfg or TriColorConfig()

	src = img.convert("RGB")
	if width and height:
		src = src.resize((width, height), Image.Resampling.LANCZOS)

	w, h = src.size
	pix = src.load()

	# 1) Compute base grayscale and (optional) red strength
	luma_base = [[255.0] * w for _ in range(h)]
	red_strength = [[0.0] * w for _ in range(h)]
	red_mask = [[False] * w for _ in range(h)]

	for y in range(h):
		for x in range(w):
			r, g, b = pix[x, y]
			luma_base[y][x] = _to_luma(r, g, b)
			if cfg.red_dither:
				rs = _red_strength(r, g, b, cfg)
				red_strength[y][x] = rs
			else:
				red_mask[y][x] = _is_red_pixel(r, g, b, cfg)

	# 2) Red plane: either hard mask or dithered strength
	if cfg.red_dither:
		red_plane = _floyd_steinberg_dither_plane(red_strength, cfg.red_threshold, cfg.serpentine)
		for y in range(h):
			for x in range(w):
				red_mask[y][x] = red_plane[y][x] == 255

	# 3) Prepare grayscale for B/W dithering (force red pixels to white)
	luma = [[255.0] * w for _ in range(h)]
	for y in range(h):
		for x in range(w):
			luma[y][x] = 255.0 if red_mask[y][x] else luma_base[y][x]

	# 4) Dither the remaining pixels into black/white
	if cfg.auto_levels or (abs(cfg.gamma - 1.0) > 1e-6):
		luma = _apply_auto_levels_and_gamma(
			luma,
			red_mask,
			clip_percent=cfg.auto_levels_percent if cfg.auto_levels else 0.0,
			gamma=cfg.gamma,
		)
	bw = _floyd_steinberg_dither_bw(luma, cfg.bw_threshold, cfg.serpentine)

	# 3) Compose final 3-color indexed image
	out = Image.new("P", (w, h))
	# Palette: index 0=white, 1=black, 2=red
	palette = [255, 255, 255, 0, 0, 0, 255, 0, 0] + [0, 0, 0] * 253
	out.putpalette(palette)

	out_px = out.load()
	for y in range(h):
		for x in range(w):
			if red_mask[y][x]:
				out_px[x, y] = 2
			else:
				out_px[x, y] = 1 if bw[y][x] == 0 else 0

	return out


def _parse_args(argv: list[str]) -> argparse.Namespace:
	p = argparse.ArgumentParser(description="Convert images to tri-color e-ink palette (white/black/red).")
	p.add_argument("input", help="Input image path")
	p.add_argument("output", help="Output PNG path")
	p.add_argument("--width", type=int, default=None)
	p.add_argument("--height", type=int, default=None)

	p.add_argument("--bw-threshold", type=int, default=127, help="0..255; higher makes image darker")
	p.add_argument("--hue-width", type=float, default=0.06, help="0..0.5; red hue band width")
	p.add_argument("--sat", type=float, default=0.45, help="0..1; red saturation threshold")
	p.add_argument("--val", type=float, default=0.20, help="0..1; red value threshold")
	p.add_argument("--min-r", type=int, default=70, help="0..255; RGB fallback min red")
	p.add_argument("--r-ratio", type=float, default=1.25, help="R >= ratio*G and R >= ratio*B")
	p.add_argument("--red-delta", type=int, default=60, help="0..255; require R - max(G,B) >= delta")

	p.add_argument("--red-dither", action="store_true", help="Enable dithering on red plane (still only red/white)")
	p.add_argument("--red-threshold", type=int, default=127, help="0..255; red plane threshold when --red-dither")

	p.add_argument("--no-auto-levels", action="store_true", help="Disable grayscale auto-levels")
	p.add_argument(
		"--auto-levels-percent",
		type=float,
		default=1.0,
		help="0..20; clip both tails (percent) when stretching grayscale contrast",
	)
	p.add_argument("--gamma", type=float, default=1.0, help="Gamma on grayscale (e.g. 0.9 brighter, 1.1 darker)")

	p.add_argument("--no-serpentine", action="store_true", help="Disable serpentine scan in dithering")
	return p.parse_args(argv)


_IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".bmp", ".gif", ".tif", ".tiff", ".webp"}


def _iter_images_in_dir(dir_path: Path) -> list[Path]:
	paths: list[Path] = []
	for p in sorted(dir_path.iterdir()):
		if p.is_file() and p.suffix.lower() in _IMAGE_EXTS:
			paths.append(p)
	return paths


def main(argv: list[str]) -> int:
	args = _parse_args(argv)

	cfg = TriColorConfig(
		hue_width=args.hue_width,
		sat_threshold=args.sat,
		val_threshold=args.val,
		min_r=args.min_r,
		r_ratio=args.r_ratio,
		red_delta=args.red_delta,
		red_dither=args.red_dither,
		red_threshold=args.red_threshold,
		bw_threshold=args.bw_threshold,
		serpentine=not args.no_serpentine,
		auto_levels=not args.no_auto_levels,
		auto_levels_percent=args.auto_levels_percent,
		gamma=args.gamma,
	)

	input_path = Path(args.input)
	output_path = Path(args.output)

	# Allow running from anywhere; normalize relative paths.
	if not input_path.is_absolute():
		input_path = (Path(os.getcwd()) / input_path).resolve()
	if not output_path.is_absolute():
		output_path = (Path(os.getcwd()) / output_path).resolve()

	if input_path.is_dir():
		# Batch mode: output must be a directory
		output_path.mkdir(parents=True, exist_ok=True)
		images = _iter_images_in_dir(input_path)
		if not images:
			raise SystemExit(f"No images found in: {input_path}")

		for src_path in images:
			dst_path = output_path / f"{src_path.stem}_3c.png"
			with Image.open(src_path) as im:
				out = convert_to_tricolor(im, width=args.width, height=args.height, cfg=cfg)
				out.save(dst_path, format="PNG", optimize=True)
			print(f"OK: {src_path.name} -> {dst_path.name}")
	else:
		# Single file mode
		output_path.parent.mkdir(parents=True, exist_ok=True)
		with Image.open(input_path) as im:
			out = convert_to_tricolor(im, width=args.width, height=args.height, cfg=cfg)
			out.save(output_path, format="PNG", optimize=True)
		print(f"OK: {input_path.name} -> {output_path.name}")

	return 0


if __name__ == "__main__":
	raise SystemExit(main(sys.argv[1:]))
