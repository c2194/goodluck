#!/usr/bin/env python3
"""
字体子集化服务 - 根据输入文字提取字体子集
用法: python subset.py <字体文件> <文字> [输出格式:woff2|woff|ttf]
"""

import sys
import os
import hashlib
from fontTools.ttLib import TTFont
from fontTools.subset import Subsetter, Options

def subset_font(font_path: str, text: str, output_format: str = 'woff2') -> bytes:
    """
    从字体文件中提取包含指定文字的子集
    
    Args:
        font_path: 字体文件路径
        text: 需要的文字
        output_format: 输出格式 (woff2, woff, ttf)
    
    Returns:
        子集字体的二进制数据
    """
    # 去重并添加基础字符
    chars = set(text)
    chars.add(' ')  # 确保包含空格
    
    # 加载字体
    font = TTFont(font_path)
    
    # 配置子集化选项
    options = Options()
    options.flavor = output_format if output_format in ['woff', 'woff2'] else None
    options.desubroutinize = True  # 提高兼容性
    
    # 创建子集化器
    subsetter = Subsetter(options=options)
    subsetter.populate(text=text)
    subsetter.subset(font)
    
    # 输出到内存
    from io import BytesIO
    output = BytesIO()
    font.save(output)
    font.close()
    
    return output.getvalue()


def main():
    if len(sys.argv) < 3:
        print("用法: python subset.py <字体文件> <文字> [输出格式:woff2|woff|ttf]", file=sys.stderr)
        sys.exit(1)
    
    font_path = sys.argv[1]
    text = sys.argv[2]
    output_format = sys.argv[3] if len(sys.argv) > 3 else 'woff2'
    
    if not os.path.exists(font_path):
        print(f"错误: 字体文件不存在: {font_path}", file=sys.stderr)
        sys.exit(1)
    
    try:
        data = subset_font(font_path, text, output_format)
        # 输出二进制数据到 stdout
        sys.stdout.buffer.write(data)
    except Exception as e:
        print(f"错误: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()
