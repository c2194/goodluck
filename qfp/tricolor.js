/**
 * 三色墨水屏图片转换 (黑/白/红)
 * 基于 Floyd-Steinberg 抖动算法
 * 
 * Usage:
 *   const converter = new TriColorConverter(config);
 *   const resultCanvas = converter.convert(sourceCanvas);
 */

class TriColorConverter {
  constructor(config = {}) {
    this.config = {
      // 红色检测 HSV 阈值
      hueWidth: config.hueWidth ?? 0.06,       // 0..0.5, 红色色相宽度
      satThreshold: config.satThreshold ?? 0.45, // 饱和度阈值
      valThreshold: config.valThreshold ?? 0.20, // 明度阈值

      // 红色检测 RGB 备用
      minR: config.minR ?? 70,
      rRatio: config.rRatio ?? 1.25,
      redDelta: config.redDelta ?? 60,         // R - max(G,B) >= redDelta

      // 红色抖动
      redDither: config.redDither ?? false,
      redThreshold: config.redThreshold ?? 127,
      redBoost: config.redBoost ?? 1.0,        // 红色增强倍数 1.0-3.0

      // 灰度预处理
      autoLevels: config.autoLevels ?? true,
      autoLevelsPercent: config.autoLevelsPercent ?? 1.0,
      gamma: config.gamma ?? 1.0,

      // 黑白阈值
      bwThreshold: config.bwThreshold ?? 127,

      // 抖动行为
      serpentine: config.serpentine ?? true,

      // 输出颜色
      colors: {
        white: config.colors?.white ?? [255, 255, 255],
        black: config.colors?.black ?? [0, 0, 0],
        red: config.colors?.red ?? [255, 0, 0],
      }
    };
  }

  /**
   * RGB 转 HSV
   */
  rgbToHsv(r, g, b) {
    const rf = r / 255, gf = g / 255, bf = b / 255;
    const max = Math.max(rf, gf, bf);
    const min = Math.min(rf, gf, bf);
    const d = max - min;
    
    let h = 0;
    const s = max === 0 ? 0 : d / max;
    const v = max;

    if (d !== 0) {
      switch (max) {
        case rf: h = ((gf - bf) / d + (gf < bf ? 6 : 0)) / 6; break;
        case gf: h = ((bf - rf) / d + 2) / 6; break;
        case bf: h = ((rf - gf) / d + 4) / 6; break;
      }
    }
    return { h, s, v };
  }

  /**
   * 判断是否为红色像素
   */
  isRedPixel(r, g, b) {
    const cfg = this.config;
    const { h, s, v } = this.rgbToHsv(r, g, b);
    const redBias = r - Math.max(g, b);

    // HSV 检测
    if (s >= cfg.satThreshold && v >= cfg.valThreshold && redBias >= cfg.redDelta) {
      if (h <= cfg.hueWidth || h >= (1.0 - cfg.hueWidth)) {
        return true;
      }
    }

    // RGB 备用检测
    if (r >= cfg.minR && redBias >= cfg.redDelta && 
        r >= g * cfg.rRatio && r >= b * cfg.rRatio) {
      return true;
    }

    return false;
  }

  /**
   * 计算红色强度 (用于抖动)
   * 使用更宽松的渐变计算，配合 redBoost 增强
   */
  redStrength(r, g, b) {
    const cfg = this.config;
    const { h, s, v } = this.rgbToHsv(r, g, b);
    const redBias = r - Math.max(g, b);

    // 扩大色相检测范围用于渐变 (比硬阈值宽一些)
    const hueRange = cfg.hueWidth * 1.5;
    let hueFactor = 0;
    if (h <= hueRange) {
      hueFactor = 1.0 - (h / hueRange) * 0.5; // 越接近0越强
    } else if (h >= (1.0 - hueRange)) {
      hueFactor = 1.0 - ((1.0 - h) / hueRange) * 0.5;
    } else {
      return 0; // 不在红色范围
    }

    // 使用软阈值，允许渐变
    const satSoft = cfg.satThreshold * 0.6; // 更宽松的下限
    const valSoft = cfg.valThreshold * 0.6;
    const deltaSoft = cfg.redDelta * 0.5;

    if (s < satSoft || v < valSoft || redBias < deltaSoft) {
      return 0;
    }

    // 计算各因素贡献 (使用更平滑的曲线)
    let s01 = (s - satSoft) / Math.max(1e-6, 1.0 - satSoft);
    let v01 = (v - valSoft) / Math.max(1e-6, 1.0 - valSoft);
    let bias01 = (redBias - deltaSoft) / Math.max(1e-6, 255.0 - deltaSoft);

    s01 = Math.max(0, Math.min(1, s01));
    v01 = Math.max(0, Math.min(1, v01));
    bias01 = Math.max(0, Math.min(1, bias01));

    // 使用平方根使中间值更高，增加红色面积
    const base = Math.sqrt(s01) * Math.sqrt(v01) * Math.sqrt(bias01) * hueFactor;
    
    // 应用红色增强
    let strength = 255 * base * cfg.redBoost;
    return Math.min(255, strength);
  }

  /**
   * 计算亮度 (ITU-R BT.601)
   */
  toLuma(r, g, b) {
    return 0.299 * r + 0.587 * g + 0.114 * b;
  }

  /**
   * 自动对比度和 gamma 校正
   */
  applyAutoLevelsAndGamma(lumaRows, redMask, width, height) {
    const cfg = this.config;
    if (!cfg.autoLevels && Math.abs(cfg.gamma - 1.0) <= 1e-6) {
      return lumaRows;
    }

    const clipPercent = Math.max(0, Math.min(20, cfg.autoLevelsPercent));
    
    // 构建直方图 (只统计非红色像素)
    const hist = new Array(256).fill(0);
    let count = 0;
    
    for (let y = 0; y < height; y++) {
      for (let x = 0; x < width; x++) {
        if (redMask[y * width + x]) continue;
        const v = Math.max(0, Math.min(255, Math.round(lumaRows[y * width + x])));
        hist[v]++;
        count++;
      }
    }

    if (count === 0) return lumaRows;

    // 找低/高截断点
    const cut = Math.floor(count * (clipPercent / 100));
    
    let low = 0, acc = 0;
    for (let i = 0; i < 256; i++) {
      acc += hist[i];
      if (acc > cut) { low = i; break; }
    }

    let high = 255;
    acc = 0;
    for (let i = 255; i >= 0; i--) {
      acc += hist[i];
      if (acc > cut) { high = i; break; }
    }

    if (high <= low) return lumaRows;

    const scale = 255 / (high - low);
    const useGamma = Math.abs(cfg.gamma - 1.0) > 1e-6;
    const out = new Float32Array(width * height);

    for (let y = 0; y < height; y++) {
      for (let x = 0; x < width; x++) {
        const idx = y * width + x;
        if (redMask[idx]) {
          out[idx] = 255;
          continue;
        }
        
        let v = (lumaRows[idx] - low) * scale;
        v = Math.max(0, Math.min(255, v));
        
        if (useGamma) {
          v = 255 * Math.pow(v / 255, cfg.gamma);
        }
        out[idx] = v;
      }
    }

    return out;
  }

  /**
   * Floyd-Steinberg 抖动
   */
  floydSteinbergDither(values, width, height, threshold) {
    const cfg = this.config;
    const buf = new Float32Array(values);
    const out = new Uint8Array(width * height);

    for (let y = 0; y < height; y++) {
      const leftToRight = (y % 2 === 0) || !cfg.serpentine;
      
      const xStart = leftToRight ? 0 : width - 1;
      const xEnd = leftToRight ? width : -1;
      const xStep = leftToRight ? 1 : -1;

      for (let x = xStart; x !== xEnd; x += xStep) {
        const idx = y * width + x;
        const old = buf[idx];
        const newVal = old < threshold ? 0 : 255;
        out[idx] = newVal;
        const err = old - newVal;

        if (leftToRight) {
          if (x + 1 < width) buf[idx + 1] += err * 7 / 16;
          if (y + 1 < height) {
            if (x - 1 >= 0) buf[(y + 1) * width + (x - 1)] += err * 3 / 16;
            buf[(y + 1) * width + x] += err * 5 / 16;
            if (x + 1 < width) buf[(y + 1) * width + (x + 1)] += err * 1 / 16;
          }
        } else {
          if (x - 1 >= 0) buf[idx - 1] += err * 7 / 16;
          if (y + 1 < height) {
            if (x + 1 < width) buf[(y + 1) * width + (x + 1)] += err * 3 / 16;
            buf[(y + 1) * width + x] += err * 5 / 16;
            if (x - 1 >= 0) buf[(y + 1) * width + (x - 1)] += err * 1 / 16;
          }
        }
      }
    }

    return out;
  }

  /**
   * 转换图片为三色
   * @param {HTMLCanvasElement|HTMLImageElement} source - 源图片或 canvas
   * @param {number} [targetWidth] - 目标宽度 (可选)
   * @param {number} [targetHeight] - 目标高度 (可选)
   * @returns {HTMLCanvasElement} - 三色结果 canvas
   */
  convert(source, targetWidth = null, targetHeight = null) {
    const cfg = this.config;

    // 获取源 canvas
    let srcCanvas;
    if (source instanceof HTMLCanvasElement) {
      srcCanvas = source;
    } else if (source instanceof HTMLImageElement) {
      srcCanvas = document.createElement('canvas');
      srcCanvas.width = source.naturalWidth || source.width;
      srcCanvas.height = source.naturalHeight || source.height;
      const ctx = srcCanvas.getContext('2d');
      ctx.drawImage(source, 0, 0);
    } else {
      throw new Error('source must be HTMLCanvasElement or HTMLImageElement');
    }

    // 确定输出尺寸
    let w = targetWidth || srcCanvas.width;
    let h = targetHeight || srcCanvas.height;

    // 创建工作 canvas (缩放)
    const workCanvas = document.createElement('canvas');
    workCanvas.width = w;
    workCanvas.height = h;
    const workCtx = workCanvas.getContext('2d');
    workCtx.drawImage(srcCanvas, 0, 0, w, h);

    const imageData = workCtx.getImageData(0, 0, w, h);
    const data = imageData.data;

    // 1) 计算基础灰度和红色信息
    const lumaBase = new Float32Array(w * h);
    const redStrengthArr = new Float32Array(w * h);
    const redMask = new Uint8Array(w * h);

    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        const idx = y * w + x;
        const pIdx = idx * 4;
        const r = data[pIdx], g = data[pIdx + 1], b = data[pIdx + 2];

        lumaBase[idx] = this.toLuma(r, g, b);

        if (cfg.redDither) {
          redStrengthArr[idx] = this.redStrength(r, g, b);
        } else {
          redMask[idx] = this.isRedPixel(r, g, b) ? 1 : 0;
        }
      }
    }

    // 2) 红色平面处理
    if (cfg.redDither) {
      const redPlane = this.floydSteinbergDither(redStrengthArr, w, h, cfg.redThreshold);
      for (let i = 0; i < w * h; i++) {
        redMask[i] = redPlane[i] === 255 ? 1 : 0;
      }
    }

    // 3) 准备灰度 (红色区域设为白)
    const luma = new Float32Array(w * h);
    for (let i = 0; i < w * h; i++) {
      luma[i] = redMask[i] ? 255 : lumaBase[i];
    }

    // 4) 自动对比度和 gamma
    const lumaProcessed = this.applyAutoLevelsAndGamma(luma, redMask, w, h);

    // 5) 黑白抖动
    const bw = this.floydSteinbergDither(lumaProcessed, w, h, cfg.bwThreshold);

    // 6) 生成输出
    const outCanvas = document.createElement('canvas');
    outCanvas.width = w;
    outCanvas.height = h;
    const outCtx = outCanvas.getContext('2d');
    const outData = outCtx.createImageData(w, h);
    const out = outData.data;

    const white = cfg.colors.white;
    const black = cfg.colors.black;
    const red = cfg.colors.red;

    for (let i = 0; i < w * h; i++) {
      const pIdx = i * 4;
      let color;
      
      if (redMask[i]) {
        color = red;
      } else if (bw[i] === 0) {
        color = black;
      } else {
        color = white;
      }

      out[pIdx] = color[0];
      out[pIdx + 1] = color[1];
      out[pIdx + 2] = color[2];
      out[pIdx + 3] = 255;
    }

    outCtx.putImageData(outData, 0, 0);
    return outCanvas;
  }

  /**
   * 从 canvas/image 区域裁剪并转换
   * @param {HTMLCanvasElement|HTMLImageElement} source
   * @param {number} sx - 源区域 x
   * @param {number} sy - 源区域 y
   * @param {number} sw - 源区域宽度
   * @param {number} sh - 源区域高度
   * @param {number} [targetWidth] - 输出宽度
   * @param {number} [targetHeight] - 输出高度
   */
  convertRegion(source, sx, sy, sw, sh, targetWidth = null, targetHeight = null) {
    const cropCanvas = document.createElement('canvas');
    cropCanvas.width = sw;
    cropCanvas.height = sh;
    const ctx = cropCanvas.getContext('2d');
    ctx.drawImage(source, sx, sy, sw, sh, 0, 0, sw, sh);
    return this.convert(cropCanvas, targetWidth, targetHeight);
  }
}

// 默认导出
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { TriColorConverter };
}
