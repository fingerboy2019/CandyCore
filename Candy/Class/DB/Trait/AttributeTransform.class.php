<?php
/***
 * 查询结果属性转换
 *
 * 业务说明：checkAttr 获取器、sql/psql 调试输出
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-17 13:18:42 $
 *
 * @package Candy\Extend\DB\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\DB\Trait;

use Candy\Core\Debug;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait AttributeTransform {
	/**
	 * 处理数据获取器
	 *
	 * 对查询结果数据进行处理，包括：
	 * - 调用获取器方法转换数据
	 * - 处理时间戳字段格式
	 * - 类型转换
	 *
	 * @param array $data 查询结果数据数组
	 * @param string $fields 查询的字段列表
	 * @return array 处理后的数据数组
	 */
	private function checkAttr(array $data,string $fields = ''): array
	{
		if(is_null($data)) return [];
		
		//获取器列表
		$funcList = [];
		
		//过滤字段
		$fields = $fields == '*' ? $this->fieldList :  explode('`,`', trim(str_replace(' ','', $fields),'`'));
		isset($this->createTime) OR $this->createTime = 'AddTime';
		isset($this->updateTime) OR $this->updateTime = 'UpTime';
		$timeFuncList = [strtolower($this->createTime), strtolower($this->updateTime)];
		isset($this->type) OR $this->type = [];
		
		foreach($fields as $k=>$field){
			$field = trim(str_replace('`', '', $field));
			//获取器 命名规则：get + 字段名 + Attr
			$funcName = 'get'. $field .'Attr';
			if(method_exists($this, $funcName)){
				unset($fields[$k]);
				$funcList[$field] = $funcName;
			}else{
					if(in_array($field, $timeFuncList)){
						unset($fields[$k]);
						// datetime 字符串已可读则原样返回；仅 Unix 时间戳才格式化
						$funcList[$field] = function($val){
							if ($val === null || $val === '') {
								return $val;
							}
							if (is_numeric($val)) {
								return date('Y-m-d H:i:s', (int) $val);
							}
							return $val;
						};
				}elseif(!isset($this->type[$field])){
					unset($fields[$k]);
				}
			}
		}
		
		//循环处理
		foreach($data as $key=>$row){
			//优先处理过滤器
			foreach($funcList as $field=>$funcName){
				if($funcName instanceof \Closure){
					$data[$key][$field] = $funcName($row[$field]);
				}else{
					$data[$key][$field] = call_user_func([$this, $funcName], $row[$field]);
				}
			}
			//类型转换
			foreach($fields as $field){
				$data[$key][$field] = $this->typeChange($this->type[$field], $row[$field], 'de');
			}
		}
		
		return $data;
	}

	/**
	 * 构建完整的 SQL 语句（用于调试）
	 *
	 * 将带有参数占位符（?）的 SQL 语句和参数数组组合成完整的 SQL 语句。
	 * 主要用于调试和日志记录，展示实际执行的 SQL。
	 *
	 * @param string $sql 带有参数占位符的 SQL 语句
	 * @param array $queryParams 参数数组
	 * @return string 完整的 SQL 语句
	 */
	protected function sql(string $sql,array $queryParams): string
	{
		if (false === strpos($sql, '?') || count($queryParams) == 0) return $sql;
		
   		// 进行 ? 的替换，变量替换
    	if (false === strpos($sql, '%')){
       		// 不存在%，替换问号为s%，进行字符串格式化
       		$sql = str_replace('?', "'%s'", $sql);
			array_unshift($queryParams, $sql);
        	return call_user_func_array('sprintf', $queryParams); //调用函数和所用参数
    	}

		return '';
	}

	/**
	 * 显示SQL语句
	 * 
	 * 输出最后执行的SQL语句，用于调试目的。
	 * 如果没有执行过SQL语句，则提示"没有SQL语句执行！"
	 * 
	 * @return string
	 * @example
	 * ```php
	 * // 执行查询操作后显示SQL语句
	 * $db->where(['id' => 100])->select();
	 * $db->psql(); // 输出执行的SQL语句
	 * ```
	 */
	public function psql(): string
	{
		if(empty(self::$echoSql)){
			return '<br>没有SQL语句执行！<br>';
		}else {
			return '<br>最后执行的SQL语句：<b>' . self::$echoSql . '</b><br>';
		}
	}

}
