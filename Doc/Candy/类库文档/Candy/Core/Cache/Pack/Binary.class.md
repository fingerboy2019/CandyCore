# Binary 类

## 概述

自主实现的高性能二进制打包/解包工具，替代 PHP 原生序列化，无对象注入风险。

- 自动区分索引/关联数组以节省空间
- 支持 LZ4 / Snappy / Brotli / Zlib 压缩与 CRC 完整性校验
- 提供 `atomicDump` / `loadDump` 原子落盘
- 线格式 `PROTOCOL_VERSION = 3`：32 位整数按有符号补码还原；unpack 在整包上按 `offset/end` 流式解析（不为每个数组元素 `substr`）
- 落盘头 `DUMP_VERSION = 2`（与协议一并升级，无历史包兼容）

`@package Candy\Core\Cache\Pack`，`final` 禁止继承。

## 主要方法

### pack()

将数组（或判定为二进制的字符串）打包为带魔数、版本、CRC 的二进制包。

### unpack()

校验长度/魔数/版本/CRC 后流式解包；失败返回空数组。版本不匹配（如旧 v2 包）直接拒绝。

### atomicDump()

临时文件写入 + rename，原子落盘。

### loadDump()

校验落盘头与 body CRC 后 unpack。

### getDumpTime()

读取落盘头中的写入时间戳。
