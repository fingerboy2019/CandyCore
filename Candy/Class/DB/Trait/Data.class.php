<?php
/**
 * 数据处理 Trait
 * 
 * @package Candy\Extend\DB\Trait
 * @author 刘森 (fingerboy@qq.com)
 * $Date: 2026-08-18 20:25:00 $
 */

declare(strict_types=1);
namespace Candy\Extend\DB\Trait;

trait Data {
	/**
	 * 数据过滤和验证
	 * 
	 * 对输入数据进行过滤、验证和处理，包括：
	 * - 字段名转换为小写
	 * - 调用修改器处理数据
	 * - 类型转换
	 * - 数据过滤（去除引号和HTML实体）
	 * - 自动添加时间字段（insert 写创建/更新时间，update 仅写更新时间）
	 * 
	 * @param array $array 要处理的数据数组
	 * @param int $filter 是否过滤数据（1:过滤，0:不过滤）
	 * @param string $scene insert|update，控制自动时间字段策略
	 * @return array 处理后的数据数组
	 */
	private function check(array $array, int $filter, string $scene = 'insert'): array
	{
		$arr = [];
		$lowerArray = array_change_key_case($array, CASE_LOWER);
		
		foreach ($array as $key => $value) {
			$lowerKey = strtolower($key);
			
			if ($filter === 0 || in_array($lowerKey, $this->fieldList, true)) {
				// 处理修改器
				$funcName = 'set' . ucfirst($lowerKey) . 'Attr'; // 修改器命名规则：set + 字段名（首字母大写） + Attr
				if (method_exists($this, $funcName)) {
					$value = call_user_func([$this, $funcName], $value);
				}
				
				// 类型转换
				$value = $this->typeChange($lowerKey, $value);
				
				// 过滤设置
				//参数绑定模式 移除不必要的转义和HTML实体过滤
				//if ($filter && is_string($value)) {
				//	$arr[$lowerKey] = stripslashes(htmlspecialchars($value));
				//} else {
					$arr[$lowerKey] = $value;
				//}
			}
		}
		
		// 添加时间字段
		$this->addTimeFields($arr, $lowerArray, $scene);
		
		return $arr;
	}
    
    /**
	 * 添加时间字段
	 *
	 * 自动添加创建时间和更新时间字段到数据数组中。
	 * 根据配置决定使用 Unix 时间戳还是日期时间格式。
	 * 仅在字段存在且未手动设置时添加；update 场景不改写创建时间
	 *
	 * @param array &$arr 数据数组（引用传递，直接修改）
	 * @param array $lowerArray 小写键名的原始数据数组，用于检查字段是否已存在
	 * @param string $scene insert|update
	 * @return void
	 */
	private function addTimeFields(array &$arr, array $lowerArray, string $scene = 'insert'): void
	{
		$auto = G('auto_timestamp') || !empty($this->autoWriteTimestamp);
		if (!$auto) {
			return;
		}

		$fieldList = is_array($this->fieldList) ? $this->fieldList : [];

		// 创建时间字段：仅 insert
		$createTime = strtolower((string) ($this->createTime ?? 'AddTime'));
		if (
			$scene === 'insert'
			&& $createTime !== ''
			&& !isset($lowerArray[$createTime])
			&& in_array($createTime, $fieldList, true)
		) {
			$arr[$createTime] = $this->getTimestampValue();
		}

		// 更新时间字段：insert / update 均写入
		$updateTime = strtolower((string) ($this->updateTime ?? 'UpTime'));
		if (
			$updateTime !== ''
			&& !isset($lowerArray[$updateTime])
			&& in_array($updateTime, $fieldList, true)
		) {
			$arr[$updateTime] = $this->getTimestampValue();
		}
	}
	
	/**
	 * 数据类型转换
	 *
	 * 根据字段类型对数据进行编码或解码转换。
	 * 支持的类型包括：integer、float、boolean、array、object、json、serialize、timestamp、datetime。
	 *
	 * @param string $key 字段类型或字段名
	 * @param mixed $value 要转换的值
	 * @param string $type 转换方向（'en': 编码/存储，'de': 解码/读取）
	 * @return mixed 转换后的值
	 */
	private function typeChange(string $key, mixed $value, string $type = 'en'): mixed{
		//类型转换
		$value = match($key){
			'integer' => intval($value),
			'float' => floatval($value),
			'boolean' => boolval($value),
			'array', 'object', 'json' => json($value, $type),
			'serialize' => $type == 'en'
				? serialize($value)
				: @unserialize($value, ['allowed_classes' => false]),
			default => (function() use ($key, $value, $type) {
				if(isset($this->type[$key])){
					if(stripos($this->type[$key],':')){
						[$type, $format] = explode(':', $this->type[$key], 2);
					}else{
						$type = $this->type[$key];
						$format = "Y-m-d H:i:s";
					}
					if(in_array($type, ['timestamp', 'datetime'])){
						return $type == 'en' ? strtotime($value) : date($format, $value);
					}
				}
				return $value;
			})(),
		};
		
		return $value;
	}
	
	/**
	 * 转义字符串数组
	 *
	 * 对数组中的字符串进行转义处理，防止 SQL 注入。
	 * 当前实现依赖于数据库驱动的自动转义，此方法保留用于未来扩展。
	 *
	 * @param array $array 要转义的字符串数组
	 * @return array 转义后的数组
	 */
	protected function escapeStringArray(array $array): array
	{
		//驱动自带转义暂停处理
		return $array;
		if(empty($array))
			return [];
		
	 	$value = [];
		foreach($array as $val){
			$value[] = str_replace(['"', "'"], '', $val);
		}
	 	return $value;
	}
	
	/**
	 * 获取时间戳值
	 *
	 * 根据配置返回当前时间的时间戳或日期时间字符串。
	 * 支持 Unix 时间戳（默认）和日期时间格式（datetime）。
	 *
	 * @return string|int 返回 Unix 时间戳或格式化的日期时间字符串
	 */
	private function getTimestampValue(): string|int
	{
		if (isset($this->autoWriteTimestamp) && $this->autoWriteTimestamp === 'datetime') {
			return date('Y-m-d H:i:s');
		}
		return time();
	}
}
