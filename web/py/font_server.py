# -*- coding: utf-8 -*-
"""
字体子集服务器
动态生成只包含指定文字的字体子集，大幅减少字体文件大小
"""

from http.server import HTTPServer, SimpleHTTPRequestHandler
from urllib.parse import urlparse, parse_qs, unquote
from fontTools.subset import Subsetter, Options
from fontTools.ttLib import TTFont
import os
import io
import hashlib

# 字体文件路径（需要先下载霞鹜文楷字体）
FONT_PATH = os.path.join(os.path.dirname(__file__), 'LXGWWenKai-Regular.ttf')

# 缓存已生成的子集
font_cache = {}

def generate_font_subset(text):
    """生成只包含指定文字的字体子集"""
    if not text:
        return None
    
    # 去重并排序，生成缓存 key
    chars = ''.join(sorted(set(text)))
    cache_key = hashlib.md5(chars.encode()).hexdigest()
    
    if cache_key in font_cache:
        return font_cache[cache_key]
    
    if not os.path.exists(FONT_PATH):
        print(f"字体文件不存在: {FONT_PATH}")
        print("请下载霞鹜文楷字体: https://github.com/lxgw/LxgwWenKai/releases")
        return None
    
    try:
        # 加载字体
        font = TTFont(FONT_PATH)
        
        # 配置子集化选项
        options = Options()
        options.flavor = 'woff2'  # 输出 woff2 格式，更小
        
        subsetter = Subsetter(options=options)
        subsetter.populate(text=chars)
        subsetter.subset(font)
        
        # 输出到内存
        output = io.BytesIO()
        font.save(output)
        font.close()
        
        data = output.getvalue()
        font_cache[cache_key] = data
        print(f"生成字体子集: {len(chars)} 字符, {len(data)} 字节")
        return data
        
    except Exception as e:
        print(f"生成字体子集失败: {e}")
        return None


class FontHandler(SimpleHTTPRequestHandler):
    def do_GET(self):
        parsed = urlparse(self.path)
        
        # 字体子集 API
        if parsed.path == '/api/font':
            params = parse_qs(parsed.query)
            text = params.get('text', [''])[0]
            text = unquote(text)
            
            font_data = generate_font_subset(text)
            
            if font_data:
                self.send_response(200)
                self.send_header('Content-Type', 'font/woff2')
                self.send_header('Content-Length', len(font_data))
                self.send_header('Access-Control-Allow-Origin', '*')
                self.send_header('Cache-Control', 'public, max-age=31536000')
                self.end_headers()
                self.wfile.write(font_data)
            else:
                self.send_error(404, "Font not available")
            return
        
        # 其他请求走静态文件
        super().do_GET()
    
    def end_headers(self):
        # 添加 CORS 支持
        self.send_header('Access-Control-Allow-Origin', '*')
        super().end_headers()


def main():
    port = 8000
    
    # 检查字体文件
    if not os.path.exists(FONT_PATH):
        print("=" * 50)
        print("⚠️  字体文件不存在!")
        print(f"请下载霞鹜文楷字体并放到: {FONT_PATH}")
        print("下载地址: https://github.com/lxgw/LxgwWenKai/releases")
        print("下载 LXGWWenKai-Regular.ttf 文件")
        print("=" * 50)
    else:
        print(f"✓ 字体文件已就绪: {FONT_PATH}")
    
    os.chdir(os.path.dirname(os.path.abspath(__file__)))
    
    server = HTTPServer(('0.0.0.0', port), FontHandler)
    print(f"\n🚀 服务器启动: http://localhost:{port}")
    print(f"   局域网访问: http://<你的IP>:{port}")
    print(f"   字体API: /api/font?text=要显示的文字")
    print("\n按 Ctrl+C 停止服务器")
    
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n服务器已停止")
        server.shutdown()


if __name__ == '__main__':
    main()
