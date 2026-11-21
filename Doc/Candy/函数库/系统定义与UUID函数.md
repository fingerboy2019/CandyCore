# 系统定义与UUID函数

## doDefine

```php
/**
 * 安全定义常量
 *
 * 该函数用于安全地定义常量，只有在常量尚未定义的情况下才会进行定义操作。
 * 这是一种防御性编程实践，可以避免重复定义常量导致的PHP错误。
 *
 * 使用示例：
 * - 定义单个常量: doDefine('APP_VERSION', '1.0.0')
 * - 定义多个常量: 
 *   doDefine('DEBUG_MODE', true);
 *   doDefine('MAX_UPLOAD_SIZE', 1024 * 1024 * 5);
 *
 * @param string $key 常量名称，遵循PHP常量命名规则（通常全大写，下划线分隔）
 * @param mixed $value 常量值，可以是字符串、数字、布尔值或数组
 * @return void 无返回值
 * @note 该函数是defined()和define()函数的组合封装，确保常量只被定义一次
 */
function doDefine(string $key, mixed $value): void
```

## createUuidV4

```php
/**
 * 生成UUID v4版本
 *
 * 该函数用于生成符合RFC 4122标准的UUID（通用唯一标识符）版本4，
 * 使用随机字节生成，具有极高的唯一性概率。
 *
 * UUID v4的格式为：xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx，其中：
 * - 前32位是随机生成的16进制数
 * - 第13位固定为4，表示版本4
 * - 第17位为8、9、A或B，表示变体
 * - 其他位均为随机生成的16进制数
 *
 * 使用示例：
 * - 生成UUID: $uuid = createUuidV4(); // 输出类似: 550e8400-e29b-41d4-a716-446655440000
 * - 作为唯一标识符: saveRecord(['id' => createUuidV4(), 'name' => 'test'])
 *
 * @return string 返回生成的UUID字符串
 * @note 生成的UUID长度固定为36个字符（包括连字符）
 */
function createUuidV4(): string
```
