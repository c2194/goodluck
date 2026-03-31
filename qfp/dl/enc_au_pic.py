#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
音频文件转换为 MP3 格式
用法: python enc_au_pic.py <wav文件路径>
"""

import sys
import os
import subprocess


def wav_to_mp3(wav_path, keep_original=False):
    """
    将 WAV 文件转换为 MP3 格式
    
    Args:
        wav_path: WAV 文件路径
        keep_original: 是否保留原始 WAV 文件，默认删除
    
    Returns:
        str: 转换后的 MP3 文件路径，失败返回 None
    """
    if not os.path.isfile(wav_path):
        print(f"错误: 文件不存在 - {wav_path}", file=sys.stderr)
        return None
    
    # 生成 MP3 文件路径
    base_path = os.path.splitext(wav_path)[0]
    mp3_path = base_path + '.mp3'
    
    try:
        # 使用 ffmpeg 进行转换
        # -y: 覆盖已有文件
        # -i: 输入文件
        # -ac 1: 单声道
        # -b:a 8k: 比特率 8kbps
        # -ar 8000: 采样率 8000Hz
        # -af apad=pad_dur=5: 末尾追加 5 秒静音
        # -c:a libmp3lame: 使用 LAME MP3 编码器
        cmd = [
            'ffmpeg',
            '-y',
            '-i', wav_path,
            '-ac', '1',
            '-b:a', '8k',
            '-ar', '8000',
            '-af', 'apad=pad_dur=5',
            '-c:a', 'libmp3lame',
            mp3_path
        ]
        
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=60
        )
        
        if result.returncode != 0:
            print(f"ffmpeg 转换失败: {result.stderr}", file=sys.stderr)
            return None
        
        # 检查输出文件是否生成
        if not os.path.isfile(mp3_path):
            print("错误: MP3 文件未生成", file=sys.stderr)
            return None
        
        # 删除原始 WAV 文件
        if not keep_original:
            try:
                os.remove(wav_path)
            except Exception as e:
                print(f"警告: 删除原始文件失败 - {e}", file=sys.stderr)
        
        print(f"转换成功: {mp3_path}")
        return mp3_path
        
    except subprocess.TimeoutExpired:
        print("错误: ffmpeg 执行超时", file=sys.stderr)
        return None
    except FileNotFoundError:
        print("错误: 未找到 ffmpeg，请确保已安装并添加到 PATH", file=sys.stderr)
        return None
    except Exception as e:
        print(f"错误: {e}", file=sys.stderr)
        return None


def main():
    if len(sys.argv) < 2:
        print("用法: python enc_au_pic.py <wav文件路径> [--keep]")
        print("  --keep: 保留原始 WAV 文件")
        sys.exit(1)
    
    wav_path = sys.argv[1]
    keep_original = '--keep' in sys.argv
    
    result = wav_to_mp3(wav_path, keep_original)
    sys.exit(0 if result else 1)


if __name__ == '__main__':
    main()
