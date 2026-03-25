# cmdir.php 工作流程分析

## 概述

`cmdir.php` 是设备注册入口，负责将设备 MAC 地址转换为 Base62 编码的目录路径，并在服务端初始化该设备本月的 `config.json` 配置文件。提供两种操作模式：**API 模式**（供嵌入式设备自动调用）和**网页表单模式**（供管理员手动操作）。

---

## 核心工具函数

### `normalizeMacInput($input, &$error)`
清洗并校验 MAC 地址输入。

| 步骤 | 规则 |
|------|------|
| 去首尾空格 | `trim()` |
| 非法字符检测 | 只允许 `[A-Fa-f0-9:-]` |
| 去除分隔符 | 移除 `-` 和 `:` 后保留纯十六进制 |
| 长度校验 | 必须恰好 12 位十六进制字符 |

输出：大写纯十六进制字符串（如 `BC6EE2357E24`），失败时通过引用参数返回错误信息。

### `toBase62($number)`
将十进制整数转换为 Base62 字符串。

- 字母表：`a-z A-Z 0-9`（共 62 个字符）
- 类似进制转换，从低位到高位依次取余拼接
- 结果调用方用 `str_pad(..., 9, 'a', STR_PAD_LEFT)` 补齐为 **9 位**

### `generateBase62Key($length = 8)`
生成指定长度的随机 Base62 字符串，使用 `random_int()` 保证密码学安全随机。

---

## MAC → 目录路径 转换规则

```
MAC 地址（任意格式）
    → 清洗为 12 位大写十六进制 (BC6EE2357E24)
    → hexdec() 转为十进制整数 (208059258527268)
    → toBase62() 转为 Base62 字符串 (a6ZLeZ8ka)
    → str_pad(..., 9, 'a') 补齐为 9 位 (a6ZLeZ8ka)

目录结构：
dl/{MMYY}/{Base62Mac9位}/config.json
例：dl/0226/a6ZLeZ8ka/config.json
```

> `{MMYY}` = `date('my')`，即当前月份 + 年份后两位（如 2026年3月 → `0326`）

---

## 模式一：API 模式（GET ?reg=）

### 请求格式

```
GET /dl/cmdir.php?reg={MAC地址}
```

`reg` 参数支持任意合法 MAC 格式：`BC-6E-E2-35-7E-24`、`BC:6E:E2:35:7E:24`、`BC6EE2357E24` 均可。

### 执行流程

```
收到 ?reg= 请求
    │
    ▼
1. 校验 MAC 地址 (normalizeMacInput)
    ├─ 失败 → 返回 JSON {cmd:"reg", re:"error", msg:"...", monthDir:null, macDir:null}
    │
    ▼
2. 转换为 Base62（9位），获取当前月份目录名
    │
    ▼
3. 创建月份目录（若不存在）
    ├─ 失败 → 返回 JSON {cmd:"reg", re:"error", msg:"创建月份目录失败", ...}
    │
    ▼
4. 创建 MAC 目录（若不存在）
    ├─ 失败 → 返回 JSON {cmd:"reg", re:"error", msg:"创建 MAC 目录失败", ...}
    │
    ▼
5. 检查 config.json 是否已存在
    ├─ 已存在 → 跳过写入（幂等保护，避免覆盖已有数据）
    │
    ▼
6. 生成 config.json（首次注册）
    ├─ 循环生成 30 个唯一 8位 Base62 Key，每项 {pw:"", state:"1"}
    ├─ 附加 SETUP 节点 {systime, sleep:"15", attime:"3000"}
    ├─ 写入失败 → 返回 JSON {cmd:"reg", re:"error", msg:"写入配置失败", ...}
    │
    ▼
7. 返回成功 JSON {cmd:"reg", re:"ok", monthDir:"0326", macDir:"a6ZLeZ8ka"}
```

### 响应示例（成功）

```json
{
    "cmd": "reg",
    "re": "ok",
    "monthDir": "0326",
    "macDir": "a6ZLeZ8ka"
}
```

### 响应示例（失败）

```json
{
    "cmd": "reg",
    "re": "error",
    "msg": "MAC 地址格式不正确。",
    "monthDir": null,
    "macDir": null
}
```

---

## 模式二：网页表单模式（POST）

管理员通过浏览器打开 `cmdir.php`，手动输入 MAC 地址提交创建。

### 页面效果

深色主题表单页，提示格式示例 `BC-6E-E2-35-7E-24` 或 `BC6EE2357E24`，提交后在页面下方显示操作结果。

### 执行流程

```
GET 访问 → 渲染空表单
    │
POST 提交（mac 字段）
    │
    ▼
1. 校验 MAC（同 API 模式）
    ├─ 失败 → $message = 错误信息，显示在页面
    │
    ▼
2. 转换为 Base62，获取路径
    │
    ▼
3. 创建月份目录（若不存在）
    ├─ 失败 → $message = '创建月份目录失败。'
    │
    ▼
4. 创建 MAC 目录（若不存在）
    ├─ 失败 → $message = '创建 MAC 目录失败。'
    │
    ▼
5. 生成 30 个唯一 Key + SETUP，写入 config.json
    ├─ 注意：表单模式无幂等保护，会覆盖已有 config.json
    ├─ 生成失败 → $message = '生成 JSON 失败。'
    ├─ 写入失败 → $message = '写入 JSON 失败。'
    │
    ▼
6. $message = '目录创建成功：{MMYY}/{Base62Mac}'
    渲染到页面
```

---

## 生成的 config.json 结构

```json
{
    "Oel7saVb": { "pw": "", "state": "1" },
    "lDgLC9QF": { "pw": "", "state": "1" },
    ...（共 30 条，Key 为随机 8位 Base62）
    "SETUP": {
        "systime": "1742860800",
        "sleep":   "15",
        "attime":  "3000"
    }
}
```

| 字段           | 说明                                       |
|----------------|--------------------------------------------|
| Key（8位）     | 随机 Base62，作为该设备下的子条目 ID       |
| `pw`           | 密码（预留字段，初始为空）                 |
| `state`        | 初始状态 `"1"`                             |
| `SETUP.systime`| 创建时的 Unix 时间戳                       |
| `SETUP.sleep`  | 设备轮询间隔（秒），硬编码 `15`            |
| `SETUP.attime` | AT 指令超时（毫秒），硬编码 `3000`         |

---

## 两种模式对比

| 维度            | API 模式 (`?reg=`)            | 表单模式 (POST)              |
|-----------------|-------------------------------|------------------------------|
| 调用方          | 嵌入式设备（ESP32 等）        | 管理员浏览器                 |
| 响应格式        | JSON                          | HTML 页面                    |
| 幂等保护        | ✅ config.json 已存在则跳过   | ❌ 每次都会覆盖写入           |
| MAC 校验        | 相同逻辑                      | 相同逻辑                     |
| 目录创建        | 相同逻辑                      | 相同逻辑                     |

---

## 与 getlist.php 的关系

```
cmdir.php (注册/初始化)
    → 创建 {MMYY}/{Base62Mac}/config.json

getlist.php (状态查询)
    → 读取 {MMYY}/{Base62Mac}/config.json
    → 返回各条目 state 值 + SETUP 控制参数
```

两者共用相同的目录结构，`cmdir.php` 负责写，`getlist.php` 负责读。

---

## 注意事项

1. **表单模式无幂等保护**：POST 提交会无条件覆盖 `config.json`，若设备已使用该配置（state 已被更新），重复提交将丢失已有状态。如需保持一致性，建议加入与 API 模式相同的 `file_exists()` 检查。

2. **目录权限**：创建目录使用 `0777`，生产环境建议收紧为 `0755`。

3. **SETUP 参数硬编码**：`sleep` 和 `attime` 写入 config.json 但 `getlist.php` 并不从中读取（而是自己硬编码返回），两处需保持同步。

4. **Key 碰撞概率**：30 个 Key 从 62^8 ≈ 218 万亿空间中随机采样，碰撞概率极低，`while` 循环去重仅作保险。
