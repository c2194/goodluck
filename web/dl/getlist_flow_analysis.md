# getlist.php 工作流程分析

## 概述

`getlist.php` 是一个 HTTP JSON 接口，供嵌入式设备（如 ESP32）完成签到后拉取当前批次所有条目的状态列表，同时下发设备控制参数。

> **数据源已改为 SQLite 数据库**（`data.db`），不再读取 `config.json` 文件。

---

## 请求格式

```
GET /dl/getlist.php?{MMYY}{DeviceID9}{Key8}
```

QUERY_STRING 为一段无分隔符的 21 字符字符串，由正则 `/^(\d{4})([A-Za-z0-9]{9})([A-Za-z0-9]{8})$/` 约束，分三段：

| 段       | 位数 | 类型      | 说明                               | 示例        |
|----------|------|-----------|------------------------------------|-------------|
| MMYY     |  4   | 纯数字    | 月份+年份后两位，用于定位批次      | `0226`      |
| DeviceID |  9   | 字母+数字 | 设备 MAC 的 Base62 编码标识        | `a6ZLeZ8ka` |
| Key      |  8   | 字母+数字 | 请求令牌（当前仅做格式校验）       | `Oel7saVb`  |

**完整示例：**
```
GET /dl/getlist.php?0226a6ZLeZ8kaOel7saVb
```

---

## 数据库结构

数据存储在 `dl/data.db`（SQLite），涉及两张表：

### devices 表（设备信息 + 控制参数）

| 字段           | 类型    | 默认值 | 说明                                            |
|----------------|---------|--------|-------------------------------------------------|
| `id`           | INTEGER | —      | 主键                                            |
| `month_year`   | TEXT    | —      | MMYY 批次标识                                   |
| `mac_b62`      | TEXT    | —      | Base62 MAC，9位                                 |
| `mac_hex`      | TEXT    | —      | 十六进制 MAC，12位大写                          |
| `registered_at`| INTEGER | —      | 注册时间 Unix 时间戳                            |
| `sleep`        | INTEGER | 15     | 正常电量祝福切换间隔（秒）                      |
| `sleep_low`    | INTEGER | 30     | 低电量祝福切换间隔（秒）                        |
| `attime`       | INTEGER | 0      | 每日数据更新时刻（分钟，0=00:00，480=08:00）    |
| `time_start`   | INTEGER | 0      | 设备每日开始工作时刻（分钟）                    |
| `time_end`     | INTEGER | 1439   | 设备每日停止工作时刻（分钟，1439=23:59）        |

### entries 表（条目/祝福列表）

| 字段        | 类型    | 说明                         |
|-------------|---------|------------------------------|
| `device_id` | INTEGER | 关联 devices.id              |
| `key`       | TEXT    | 8位 Base62 条目标识          |
| `pw`        | TEXT    | 密码（预留，当前为空）       |
| `state`     | INTEGER | 条目状态（0=禁用，1+=已启用）|

---

## 执行流程

```
请求到达
   │
   ▼
1. 读取 QUERY_STRING
   ├─ 为空 → 400 {"error": "缺少参数。"}
   │
   ▼
2. 正则校验格式
   ├─ 不匹配 → 400 {"error": "参数格式不正确。"}
   │
   ▼
3. 拆分参数
   ├─ $monthYear = MMYY（前4位）
   ├─ $mac62     = DeviceID（中间9位）
   └─ $key       = Key（后8位，当前仅做格式校验）
   │
   ▼
4. 查询数据库：devices 表
   └─ WHERE month_year = ? AND mac_b62 = ?
   ├─ 未找到 → 404 {"error": "设备未注册。"}
   │
   ▼
5. 查询数据库：entries 表
   └─ WHERE device_id = ?
   │
   ▼
6. 构建条目结果
   └─ 遍历 entries，以 key 为键、state（int）为值
   │
   ▼
7. 附加 SETUP 控制参数（从 devices 表读取真实值）
   └─ "SETUP": {
         "systime":    "<当前Unix时间戳>",
         "sleep":      "<正常电量切换间隔秒>",
         "sleep_low":  "<低电量切换间隔秒>",
         "attime":     "<每日更新时刻分钟>",
         "time_start": "<每日开始时刻分钟>",
         "time_end":   "<每日结束时刻分钟>"
      }
   │
   ▼
8. 输出 JSON，响应码 200
```

---

## 响应示例

```json
{
    "Oel7saVb": 10,
    "lDgLC9QF": 1,
    "ZIHxwkAJ": 1,
    "RcRHD0x4": 1,
    "SETUP": {
        "systime":    "1742860800",
        "sleep":      "15",
        "sleep_low":  "30",
        "attime":     "480",
        "time_start": "0",
        "time_end":   "1439"
    }
}
```

### SETUP 字段说明

| 字段              | 类型   | 说明                                                         |
|-------------------|--------|--------------------------------------------------------------|
| `SETUP.systime`   | string | 服务器当前 Unix 时间戳，供设备校时使用                       |
| `SETUP.sleep`     | string | 正常电量时祝福切换间隔（秒）                                 |
| `SETUP.sleep_low` | string | 低电量时祝福切换间隔（秒），通常更大以省电                   |
| `SETUP.attime`    | string | 每日数据更新时刻（分钟整数，480 = 08:00）                    |
| `SETUP.time_start`| string | 设备每日开始工作时刻（分钟整数，0 = 00:00）                  |
| `SETUP.time_end`  | string | 设备每日停止工作时刻（分钟整数，1439 = 23:59）               |

---

## 错误响应汇总

| HTTP 状态码 | 触发条件                           | 响应体                           |
|-------------|------------------------------------|----------------------------------|
| 400         | QUERY_STRING 为空                  | `{"error": "缺少参数。"}`        |
| 400         | 格式不匹配（非21位或字符类型不符） | `{"error": "参数格式不正确。"}`  |
| 404         | 设备在数据库中不存在               | `{"error": "设备未注册。"}`      |

---

## 注意事项

1. **Key 字段暂未做服务端验证**：提取了 `$key` 但未与 entries 内容进行比对，任何符合格式的请求均可获取数据。
2. **state 返回类型**：数据库中 `state` 为整数，接口直接以 `int` 返回，客户端按整数处理即可。
3. **SETUP 参数来自数据库**：所有控制参数均从 `devices` 表读取，通过 `dirmanager.php` 的设置面板修改后即时生效。
4. **分钟制时间字段**：`attime`、`time_start`、`time_end` 均以"当天已过分钟数"表示，设备端需自行换算为时:分格式。
