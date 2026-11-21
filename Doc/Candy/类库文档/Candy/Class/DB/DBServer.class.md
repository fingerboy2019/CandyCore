# DBServer 类

## 概述

* Candy框架数据库操作基类
 * 
 * 这是一个抽象基类，为Candy框架提供统一的数据库操作接口和核心功能。
 * 主要特性：
 * - 支持连贯操作查询
 * - 内置数据验证和过滤
 * - 支持软删除功能
 * - 提供完整的增删改查操作
 * - 支持关联查询和事务处理
 * - 内置数据缓存机制
 * - 支持修改器和获取器
 * - 支持数据类型自动转换
 * 
 * 使用场景：
 * - 作为具体数据库驱动的父类（如MySQL、SQLite等）
 * - 为应用提供统一的数据访问层
 * - 简化数据库操作，提高开发效率
 *
 * @package Candy\Extend\DB 
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-05 14:31:47
 * @abstract 数据库操作基类，定义了数据库操作的基本方法和属性。

## 主要方法


### getConfigPool()


### clearConfigPool()

* 获取连接池配置映射
	 * 
	 * @return array 配置池数组


### /**
	 * 用于重置成员属性
	 */
	protected function setNull():()

* 清空连接池配置（用于测试或重置）
	 * 
	 * @return void


### /**
	 * 获取或设置SQL构建参数
	 *
	 * 用于获取或修改SQL构建过程中使用的各种参数，如where条件、字段、排序等。
	 * 支持在原子操作模式下保存和读取参数状态。
	 *
	 * @param string $name 参数名称
	 * @param mixed $value 参数值，不传则获取参数值
	 * @return mixed 获取模式下返回参数值，设置模式下返回null
	 * @example
	 * ```php
	 * // 获取参数值
	 * $where = $db->sqlArgs('where');
	 *
	 * // 设置参数值
	 * $db->sqlArgs('field', ['id', 'name', 'age']);
	 * ```
	 */
	public function sqlArgs(string $name,array $value = []):()

* 用于重置成员属性


### /**
	 * 获取当前完整的 SQL 状态（用于验证前后状态保护）
	 *
	 * @return array 完整的 SQL 状态数组
	 */
	public function getSqlState():()


### /**
	 * 恢复 SQL 状态（用于验证后恢复原有条件）
	 *
	 * @param array $state 要恢复的 SQL 状态数组
	 * @return void
	 */
	public function setSqlState(array $state):()

* 获取当前完整的 SQL 状态（用于验证前后状态保护）
	 *
	 * @return array 完整的 SQL 状态数组


### /**
	 *连贯操作调用field() where() order() limit() group() having()方法，组合SQL语句
	 */
	public function __call(string $methodName,array $args):()

* 恢复 SQL 状态（用于验证后恢复原有条件）
	 *
	 * @param array $state 要恢复的 SQL 状态数组
	 * @return void


### /**
	 * 获取符合条件的记录总数
	 * 
	 * 根据指定的条件查询数据库中满足条件的记录数量。
	 * 支持数据缓存，可提高查询性能。
	 * 
	 * @param mixed ...$args 查询条件，支持多种格式
	 * @return int 符合条件的记录总数
	 * @example
	 * ```php
	 * // 获取所有记录数
	 * $count = $db->total();
	 * 
	 * // 根据主键获取
	 * $count = $db->total(100);
	 * 
	 * // 根据条件获取
	 * $count = $db->total(['status' => 1]);
	 * 
	 * // 使用连贯操作
	 * $count = $db->where(['status' => 1])->total();
	 * ```
	 */
	public function total(...$args):()


### /**
	 * 查询多条记录
	 * 
	 * 根据指定条件查询数据库中的记录，返回二维数组结果。
	 * 支持字段筛选、条件查询、排序、分组、分页等操作。
	 * 支持左关联查询和数据缓存。
	 * 
	 * @param mixed ...$args 查询条件，支持多种格式
	 * @return array 查询结果数组，二维数组格式
	 * @example
	 * ```php
	 * // 查询所有记录
	 * $list = $db->select();
	 * 
	 * // 查询指定条件的记录
	 * $list = $db->select(['status' => 1]);
	 * 
	 * // 使用连贯操作
	 * $list = $db->field('id,name,age')
	 *           ->where(['status' => 1])
	 *           ->order('id desc')
	 *           ->limit(10)
	 *           ->select();
	 * ```
	 */
	public function select(...$args):()


### /**
	 * 查询单条记录
	 * 
	 * 根据主键或条件查询单条记录，返回一维数组。
	 * 支持左关联查询和数据缓存。
	 * 
	 * @param string $pri 主键值，不传则使用where条件
	 * @return array|null 查询结果数组，一维数组格式，未找到则返回null
	 * @example
	 * ```php
	 * // 根据主键查询
	 * $data = $db->find(100);
	 * 
	 * // 使用where条件查询
	 * $data = $db->where(['name' => '张三'])->find();
	 * 
	 * // 结合字段筛选
	 * $data = $db->field('id,name,age')
	 *           ->where(['status' => 1])
	 *           ->find();
	 * ```
	 */
	public function find(string $pri = ''):()

