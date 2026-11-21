<?php
/***
 * HashMap类 - 键值对存储与管理工具
 * 
 * 该类提供了一个基于PHP数组实现的哈希表数据结构，用于高效存储和管理键值对数据。
 * 支持键值对的添加、获取、删除、查询等操作，提供了类似Java HashMap的API接口。
 * 
 * 主要功能：
 * - 添加键值对（put）
 * - 获取指定键的值（get）
 * - 删除键值对（remove）
 * - 批量操作（putAll, removeAll）
 * - 集合操作（keys, values）
 * - 查询操作（containsKey, containsValue, size, isEmpty）
 * 
 * 使用示例：
 * ```php
 * $map = new HashMap();
 * $map->put('name', '张三');
 * $name = $map->get('name'); // 返回: '张三'
 * $map->remove('name');
 * ```
 * 
 * @package Candy\Extend
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-17 22:12:00 $
 */
declare(strict_types=1);
namespace Candy\Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class HashMap
{
	/**
	 * HashMap内部存储键值对的数据结构
	 * 
	 * 这是HashMap类的核心数据存储容器，使用PHP数组实现哈希表功能。
	 * 该属性存储所有的键值对数据，支持数组的各种操作和特性。
	 * 初始值为一个空数组，通过removeAll方法可将其设置为null。
	 * 
	 * @var array|null 存储键值对的数组，键为任意可哈希类型，值为任意PHP类型
	 */
	protected array|null $hashTable = [];

	/**
	 * 向HashMap中添加或更新一个键值对
	 * 
	 * 该方法将指定的键值对添加到HashMap中。如果键已存在，则替换其对应的值，并返回旧值；
	 * 如果键不存在，则添加新的键值对，并返回null。
	 * 
	 * @param mixed $key 要存储的键，可以是任意可哈希的PHP类型
	 * @param mixed $value 要存储的值，可以是任意PHP类型
	 * @return mixed|null 如果键已存在则返回被替换的旧值，否则返回null
	 */
	public function put($key, $value)
	{
		if (!$this->containsKey($key)) {
			$this->hashTable[$key] = $value;
			return null;
		}

		$_temp = $this->hashTable[$key];
		$this->hashTable[$key] = $value;

		return $_temp;
	}

	/**
	 * 根据指定的键获取对应的值
	 * 
	 * 该方法查找并返回HashMap中与给定键关联的值。如果键存在，则返回其对应的值；
	 * 如果键不存在，则返回null。
	 * 
	 * @param mixed $key 要查找的键，可以是任意可哈希的PHP类型
	 * @return mixed|null 如果键存在则返回对应的值，否则返回null
	 */
	public function get($key)
	{
		if ($this->containsKey($key)) {
			return $this->hashTable[$key];
		}

		return null;
	}

	/**
	 * 从HashMap中删除指定键的键值对
	 * 
	 * 该方法从HashMap中移除与给定键关联的键值对。如果键存在，则删除对应的键值对，并返回被删除的值；
	 * 如果键不存在，则返回null，HashMap保持不变。
	 * 
	 * @param mixed $key 要删除的键，可以是任意可哈希的PHP类型
	 * @return mixed|null 如果键存在则返回被删除的值，否则返回null
	 */
	public function remove($key)
	{
		if ($this->containsKey($key)) {
			$tempTable = [];
			$tempValue = $this->hashTable[$key];
			foreach ($this->hashTable as $k => $v) {
				if ($k !== $key) {
					$tempTable[$k] = $v;
				}
			}
			$this->hashTable = $tempTable;
			return $tempValue;
		}

		return null;
	}

	/**
	 * 获取HashMap中所有键的列表
	 * 
	 * 该方法返回HashMap中所有键组成的数组。返回的数组保持与PHP array_keys()函数相同的索引顺序。
	 * 
	 * @return array 包含HashMap中所有键的数组
	 */
	public function keys()
	{
		return array_keys($this->hashTable);
	}

	/**
	 * 获取HashMap中所有值的列表
	 * 
	 * 该方法返回HashMap中所有值组成的数组。返回的数组保持与PHP array_values()函数相同的索引顺序，
	 * 与keys()方法返回的数组索引一一对应。
	 * 
	 * @return array 包含HashMap中所有值的数组
	 */
	public function values()
	{
		return array_values($this->hashTable);
	}

	/**
	 * 将另一个HashMap中的所有键值对添加到当前HashMap中
	 * 
	 * 该方法遍历传入的HashMap对象，将其所有键值对逐一添加到当前HashMap中。
	 * 如果当前HashMap中已存在相同的键，则对应的值将被替换。
	 * 
	 * @param self $map 另一个HashMap对象，包含要添加的键值对
	 * @return void
	 */
	public function putAll($map)
	{
		if (!$map->isEmpty() && $map->size() > 0) {
			$keys = $map->keys();
			foreach ($keys as $key) {
				$this->put($key, $map->get($key));
			}
		}

		return ;
	}

	/**
	 * 清空HashMap中的所有键值对
	 * 
	 * 该方法通过将内部存储的$hashTable设置为null来清空HashMap中的所有数据。
	 * 调用此方法后，HashMap将变为空状态，所有之前存储的键值对将被删除。
	 * 
	 * @return bool 始终返回true，表示清空操作成功完成
	 */
	public function removeAll(): bool
	{
		$this->hashTable = [];
		return true;
	}

	/**
	 * 检查HashMap中是否包含指定的值
	 * 
	 * 该方法通过线性遍历HashMap中的所有值，检查是否存在与给定值相等的值。
	 * 使用==运算符进行值的比较，而不是===运算符。
	 * 
	 * @param mixed $value 要查找的值，可以是任意PHP类型
	 * @return bool 如果HashMap中存在相等的值则返回true，否则返回false
	 */
	public function containsValue($value): bool
	{
		foreach ($this->hashTable as $curValue) {
			if ($curValue == $value) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 检查HashMap中是否包含指定的键
	 * 
	 * 该方法使用PHP的array_key_exists()函数检查给定的键是否存在于HashMap中。
	 * array_key_exists()函数仅检查键是否存在，不考虑值是否为null。
	 * 
	 * @param mixed $key 要检查的键，可以是任意可哈希的PHP类型
	 * @return bool 如果HashMap中存在该键则返回true，否则返回false
	 */
	public function containsKey($key)
	{
		return array_key_exists($key, $this->hashTable);
	}

	/**
	 * 获取HashMap中键值对的数量
	 * 
	 * 该方法使用PHP的count()函数计算HashMap中存储的键值对数量。
	 * 如果内部存储的$hashTable为null，则count()函数会将其视为空数组处理。
	 * 
	 * @return int HashMap中存储的键值对数量
	 */
	public function size()
	{
		return count($this->hashTable);
	}

	/**
	 * 检查HashMap是否为空
	 * 
	 * 该方法通过计算HashMap中键值对的数量是否为0来判断HashMap是否为空。
	 * 如果内部存储的$hashTable为null或不包含任何键值对，则返回true。
	 * 
	 * @return bool 如果HashMap为空则返回true，否则返回false
	 */
	public function isEmpty()
	{
		return (count($this->hashTable) == 0);
	}
}

