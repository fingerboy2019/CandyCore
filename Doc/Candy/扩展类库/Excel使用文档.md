# Excel 使用文档

## 文档概述

Excel类是Candy框架的Excel处理工具类，**属于核心类库的补充类库**。该类提供了一套完整的CSV文件导出功能和多格式Excel导入功能，主要用于将数据列表导出为CSV格式文件，同时支持导入CSV和XLSX格式的Excel文件。支持自定义表头设置、数据规则配置和带点语法的数据字段解析。

## 快速入门

### 基本导出功能

```php
// 导入Excel类
use Extend\Excel;

// 设置CSV文件头部信息
Excel::header('用户数据表.csv', ['ID', '用户名', '邮箱']);

// 准备数据
$userList = [
    ['id' => 1, 'username' => '张三', 'contact' => ['email' => 'zhangsan@example.com']],
    ['id' => 2, 'username' => '李四', 'contact' => ['email' => 'lisi@example.com']],
];

// 输出CSV数据内容（支持点语法访问嵌套字段）
Excel::body($userList, ['id', 'username', 'contact.email']);
```

### 快捷导出方法

```php
// 使用export方法一次性完成导出
$headers = ['ID', '用户名', '邮箱', '手机号'];
$list = [
    ['id' => 1, 'username' => '张三', 'contact' => ['email' => 'zhangsan@example.com', 'phone' => '13800138000']],
    ['id' => 2, 'username' => '李四', 'contact' => ['email' => 'lisi@example.com', 'phone' => '13900139000']],
];
$rules = ['id', 'username', 'contact.email', 'contact.phone'];

Excel::export('用户信息表.csv', $headers, $list, $rules);
```

### 导入Excel文件

```php
// 导入CSV格式文件
use Extend\Excel;

try {
    $filePath = '/path/to/your/file.csv';
    $result = Excel::import($filePath);
    
    // 获取导入的数据
    $headers = $result['headers']; // 表头数据
    $list = $result['list'];       // 数据列表
    
    // 处理导入的数据
    echo "导入成功！共 " . count($list) . " 条数据\n";
    echo "表头：" . implode(', ', $headers) . "\n";
    
    foreach ($list as $index => $row) {
        echo "第 " . ($index + 1) . " 行：" . implode(', ', $row) . "\n";
    }
} catch (\Exception $e) {
    echo '导入失败: ' . $e->getMessage();
}
```

## 核心功能详解

### 导出CSV文件

Excel类提供了灵活的CSV文件导出功能，支持自定义表头和复杂数据结构。

#### `header()`方法

```php
/**
 * 设置并输出CSV文件头部信息
 * 
 * @param string $name 导出文件的名称，包含文件扩展名(.csv)
 * @param array $headers 表格头部列标题数组，一维数组
 * @return void
 */
public static function header($name, $headers): void
```

**功能特点**：
- 设置适当的HTTP响应头，指示浏览器下载文件
- 自动处理UTF-8到GBK的编码转换，确保中文表头正确显示
- 使用fputcsv函数格式化并写入表头数据

**使用示例**：

```php
// 设置简单表头
Excel::header('simple.csv', ['列1', '列2', '列3']);

// 设置复杂表头
Excel::header('complex.csv', [
    '订单号', 
    '客户信息', 
    '商品详情', 
    '购买时间', 
    '支付金额'
]);
```

#### `body()`方法

```php
/**
 * 处理并输出CSV文件主体数据内容
 * 
 * @param array $list 待导出的数据列表，二维数组
 * @param array $rules 数据字段提取规则数组，支持点语法
 * @return void
 */
public static function body(array $list, $rules): void
```

**功能特点**：
- 支持使用点语法访问嵌套数组中的字段值
- 自动处理UTF-8到GBK的编码转换
- 对于不存在的键会返回空字符串

**使用示例**：

```php
// 准备数据
$data = [
    ['id' => 1, 'name' => '产品A', 'price' => 100],
    ['id' => 2, 'name' => '产品B', 'price' => 200],
    ['id' => 3, 'name' => '产品C', 'price' => 300]
];

// 简单字段规则
Excel::body($data, ['id', 'name', 'price']);

// 复杂嵌套数据
$complexData = [
    [
        'id' => 1,
        'product' => [
            'name' => '产品A',
            'category' => ['name' => '分类1', 'code' => 'CAT001']
        ],
        'supplier' => ['name' => '供应商A', 'contact' => ['email' => 'supplier@example.com']]
    ]
];

// 使用点语法访问嵌套字段
Excel::body($complexData, [
    'id',
    'product.name',
    'product.category.name',
    'product.category.code',
    'supplier.name',
    'supplier.contact.email'
]);
```

#### `export()`方法

```php
/**
 * 导出Excel文件
 * 
 * @param string $name 导出文件的名称
 * @param array $headers 表格头部列标题数组
 * @param array $list 待导出的数据列表
 * @param array $rules 数据字段提取规则数组
 * @return void
 */
public static function export($name, $headers, $list, $rules): void
```

**功能特点**：
- 一次性完成CSV文件的头部设置和数据导出
- 内部自动调用header()和body()方法
- 提供便捷的方式导出完整的CSV文件

**使用示例**：

```php
// 准备数据
$users = [
    [
        'id' => 1,
        'name' => '张三',
        'profile' => ['age' => 25, 'gender' => '男', 'address' => '北京市'],
        'contact' => ['email' => 'zhangsan@example.com', 'phone' => '13800138000']
    ],
    [
        'id' => 2,
        'name' => '李四',
        'profile' => ['age' => 30, 'gender' => '女', 'address' => '上海市'],
        'contact' => ['email' => 'lisi@example.com', 'phone' => '13900139000']
    ]
];

// 导出用户信息
Excel::export('用户详细信息.csv', 
    ['ID', '姓名', '年龄', '性别', '地址', '邮箱', '手机'],
    $users,
    ['id', 'name', 'profile.age', 'profile.gender', 'profile.address', 'contact.email', 'contact.phone']
);
```

### 导入Excel文件

Excel类支持导入CSV和XLSX格式的Excel文件。

#### `import()`方法

```php
/**
 * 导入Excel文件
 * 
 * @param string $file 待导入的Excel文件路径
 * @return array 导入的Excel数据，包含headers和list两个键
 * @throws \Exception 当文件不存在、无法访问或格式不支持时抛出异常
 */
public static function import(string $file): array
```

**功能特点**：
- 支持CSV和XLSX格式
- 对于XLS格式提供友好的错误提示
- 自动解析表头和数据
- 返回统一格式的导入结果

**返回格式**：

```php
[
    'headers' => ['列1', '列2', '列3'], // 表头数据
    'list' => [                          // 数据列表
        ['值1', '值2', '值3'],
        ['值4', '值5', '值6']
    ]
]
```

**使用示例**：

```php
// 导入CSV文件
$csvPath = '/path/to/data.csv';
try {
    $csvResult = Excel::import($csvPath);
    echo "CSV导入成功：" . count($csvResult['list']) . " 条数据\n";
} catch (\Exception $e) {
    echo "CSV导入失败：" . $e->getMessage() . "\n";
}

// 导入XLSX文件
$xlsxPath = '/path/to/data.xlsx';
try {
    $xlsxResult = Excel::import($xlsxPath);
    echo "XLSX导入成功：" . count($xlsxResult['list']) . " 条数据\n";
} catch (\Exception $e) {
    echo "XLSX导入失败：" . $e->getMessage() . "\n";
}
```

### 解析嵌套数据

Excel类提供了`parseKeyDotValue()`方法，用于根据点语法路径从数组中提取字段值。

#### `parseKeyDotValue()`方法

```php
/**
 * 根据点语法路径从数组中提取字段值
 * 
 * @param array $data 源数据数组，可能包含多层嵌套结构
 * @param string $rule 字段提取规则，支持点语法路径
 * @return mixed 提取到的字段值
 */
public static function parseKeyDotValue(array $data, $rule): mixed
```

**功能特点**：
- 支持使用点语法(如 'user.contact.email') 访问嵌套数组中的深层字段值
- 自动处理UTF-8到GBK的编码转换
- 对于不存在的键会返回空字符串

**使用示例**：

```php
// 准备复杂数据
$user = [
    'id' => 1,
    'name' => '张三',
    'profile' => [
        'age' => 25,
        'gender' => '男',
        'address' => [
            'province' => '北京市',
            'city' => '北京市',
            'district' => '朝阳区'
        ]
    ],
    'contacts' => [
        ['type' => 'email', 'value' => 'zhangsan@example.com'],
        ['type' => 'phone', 'value' => '13800138000']
    ]
];

// 提取简单字段
$id = Excel::parseKeyDotValue($user, 'id');
echo "ID: " . $id . "\n";

// 提取嵌套字段
$city = Excel::parseKeyDotValue($user, 'profile.address.city');
echo "城市: " . $city . "\n";

// 提取不存在的字段（返回空字符串）
$nickname = Excel::parseKeyDotValue($user, 'nickname');
echo "昵称: " . (empty($nickname) ? '未设置' : $nickname) . "\n";

// 提取数组元素
$email = Excel::parseKeyDotValue($user, 'contacts.0.value');
echo "邮箱: " . $email . "\n";
```

## 高级用法

### 处理复杂数据结构

```php
// 准备复杂嵌套数据
$orderData = [
    [
        'order_id' => 'ORD-001',
        'customer' => [
            'name' => '王五',
            'phone' => '13800138000'
        ],
        'items' => [
            ['product' => '商品A', 'quantity' => 2, 'price' => 100],
            ['product' => '商品B', 'quantity' => 1, 'price' => 200]
        ],
        'total_amount' => 400,
        'order_time' => '2023-01-01 10:00:00'
    ],
    [
        'order_id' => 'ORD-002',
        'customer' => [
            'name' => '赵六',
            'phone' => '13900139000'
        ],
        'items' => [
            ['product' => '商品C', 'quantity' => 3, 'price' => 50],
            ['product' => '商品A', 'quantity' => 1, 'price' => 100]
        ],
        'total_amount' => 250,
        'order_time' => '2023-01-02 14:30:00'
    ]
];

// 导出订单详情（为每个订单项创建一行）
$headers = ['订单号', '客户姓名', '客户电话', '商品名称', '数量', '单价', '小计', '订单时间'];
Excel::header('订单详细数据.csv', $headers);

foreach ($orderData as $order) {
    foreach ($order['items'] as $item) {
        $rowData = [
            'order_id' => $order['order_id'],
            'customer_name' => $order['customer']['name'],
            'customer_phone' => $order['customer']['phone'],
            'product' => $item['product'],
            'quantity' => $item['quantity'],
            'price' => $item['price'],
            'subtotal' => $item['quantity'] * $item['price'],
            'order_time' => $order['order_time']
        ];
        
        // 只处理一行数据时使用数组包装
        Excel::body([$rowData], array_keys($rowData));
    }
}
```

### 处理大型数据集

对于大型数据集，建议使用分页或分批处理，以避免内存溢出问题。

```php
// 模拟从数据库获取大量数据
$total = 10000; // 总记录数
$pageSize = 1000; // 每页大小
$pages = ceil($total / $pageSize);

// 设置表头
$headers = ['ID', '姓名', '邮箱', '创建时间'];
Excel::header('large_dataset.csv', $headers);

// 分批导出数据
for ($page = 1; $page <= $pages; $page++) {
    // 模拟获取分页数据
    // 在实际应用中，这里应该是数据库查询：SELECT * FROM users LIMIT ?, ?
    $offset = ($page - 1) * $pageSize;
    $data = [];
    
    for ($i = 0; $i < $pageSize; $i++) {
        $index = $offset + $i + 1;
        if ($index > $total) break;
        
        $data[] = [
            'id' => $index,
            'name' => '用户' . $index,
            'email' => 'user' . $index . '@example.com',
            'created_at' => date('Y-m-d H:i:s', time() - rand(0, 86400 * 365))
        ];
    }
    
    // 导出当前页数据
    Excel::body($data, ['id', 'name', 'email', 'created_at']);
    
    // 释放内存
    unset($data);
    gc_collect_cycles();
}
```

### 自定义导出逻辑

```php
// 准备数据
$users = [
    [
        'id' => 1,
        'name' => '张三',
        'gender' => 'male',
        'status' => 1,
        'created_at' => '2023-01-01 10:00:00'
    ],
    [
        'id' => 2,
        'name' => '李四',
        'gender' => 'female',
        'status' => 0,
        'created_at' => '2023-01-02 14:30:00'
    ]
];

// 自定义数据转换
$processedData = [];
foreach ($users as $user) {
    // 将性别转换为中文
    $genderText = $user['gender'] === 'male' ? '男' : '女';
    
    // 将状态转换为中文
    $statusText = $user['status'] === 1 ? '启用' : '禁用';
    
    $processedData[] = [
        'id' => $user['id'],
        'name' => $user['name'],
        'gender' => $genderText,
        'status' => $statusText,
        'created_at' => $user['created_at']
    ];
}

// 导出处理后的数据
Excel::export(
    '用户数据.csv',
    ['ID', '姓名', '性别', '状态', '创建时间'],
    $processedData,
    ['id', 'name', 'gender', 'status', 'created_at']
);
```

## 注意事项

1. **格式支持**：
   - 导出功能主要用于CSV文件，不支持Excel的高级格式（如XLS、XLSX）
   - 导入功能支持CSV和XLSX格式，暂不支持XLS格式

2. **环境要求**：
   - 解析XLSX格式需要PHP的ZipArchive扩展，确保服务器环境已安装此扩展
   - 确保PHP版本支持命名空间和类型声明

3. **编码处理**：
   - 方法会自动将UTF-8编码转换为GBK编码，以确保中文在Excel中正确显示
   - 如果需要其他编码，可以手动修改代码或在导出后进行编码转换

4. **输出注意事项**：
   - 在调用header方法前，确保没有输出任何内容，否则可能导致HTTP头设置失败
   - 如果需要在导出前执行其他操作，请确保这些操作不会产生任何输出

5. **性能考量**：
   - 对于大型数据集，建议使用分页或分批处理，以避免内存溢出问题
   - 导出大量数据时，可能会消耗较多的服务器资源和带宽

6. **浏览器行为**：
   - 导出CSV文件时，浏览器的下载行为取决于浏览器设置，可能会直接打开或提示保存
   - 不同浏览器对CSV文件的处理方式可能有所不同

7. **权限问题**：
   - 导入文件时，确保PHP有足够的权限读取该文件
   - 确保文件路径正确，不存在路径遍历漏洞

## 常见问题

### Q: 导出的CSV文件中文显示乱码怎么办？

A: Excel类会自动将UTF-8编码转换为GBK编码，以确保中文在Excel中正确显示。如果仍然出现乱码问题：

1. 确保原始数据是UTF-8编码
2. 尝试在Excel中手动设置文件编码：打开文件时选择"数据"->"从文本/CSV"，然后选择正确的编码
3. 检查服务器的默认字符集设置

### Q: 为什么导入XLSX文件失败？

A: 导入XLSX文件失败可能有以下原因：

1. PHP的ZipArchive扩展未安装或未启用
2. 文件路径不正确或PHP没有读取权限
3. 文件不是有效的XLSX格式（可能已损坏）
4. 文件大小超过PHP的upload_max_filesize或post_max_size限制

### Q: 如何处理大型数据集？

A: 对于大型数据集，建议使用分页或分批处理：

1. 从数据库中分批获取数据（使用LIMIT和OFFSET）
2. 每批数据处理完成后释放内存（使用unset和gc_collect_cycles）
3. 考虑在后台任务中执行大型导出操作

### Q: 可以导出其他格式的Excel文件吗？

A: Excel类主要设计用于导出CSV格式文件。如果需要导出XLS或XLSX格式，建议使用专门的Excel处理库，如PHPExcel或PhpSpreadsheet。

### Q: 为什么使用点语法访问嵌套数据时返回空值？

A: 可能的原因：

1. 点语法路径不正确，请检查数据结构和路径
2. 数据中确实不存在该字段
3. 数据类型不匹配（例如，尝试从非数组类型中访问字段）

### Q: 如何自定义CSV文件的分隔符？

A: 默认情况下，fputcsv函数使用逗号作为分隔符。如果需要自定义分隔符，可以修改Excel类的代码，在调用fputcsv函数时指定分隔符参数：

```php
// 在header()和body()方法中修改fputcsv调用
fputcsv($handle, $data, ';'); // 使用分号作为分隔符
```

## 最佳实践

1. **数据预处理**：在导出前对数据进行适当的处理和验证，确保数据质量。

2. **错误处理**：使用try-catch块捕获可能的异常，提供友好的错误提示。

3. **性能优化**：
   - 对于大型数据集使用分页或分批处理
   - 避免在循环中执行复杂的计算或数据库查询
   - 及时释放不再使用的内存

4. **安全考虑**：
   - 导入文件时验证文件类型和内容，防止恶意文件上传
   - 确保文件路径安全，避免路径遍历漏洞
   - 对导入的数据进行验证和过滤，防止SQL注入等安全问题

5. **用户体验**：
   - 使用清晰的文件名和表头
   - 提供进度反馈（对于大型导出操作）
   - 说明导出文件的格式和使用方法

6. **日志记录**：记录重要的导出和导入操作，便于审计和问题排查。

通过遵循这些最佳实践，您可以安全、高效地使用Excel类进行数据导出和导入操作。