<?php
/**
 * 缓存管理 Trait
 * 
 * @package Candy\Extend\DB\Trait
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-04-27
 */

declare(strict_types=1);
namespace Candy\Extend\DB\Trait;
use \Candy\Core\Debug;

trait Result {
    
	
	/**
	 * 返回JSON格式数据
	 * 
	 * 将数组数据转换为JSON字符串格式返回。
	 * 通常用于API接口响应。
	 * 
	 * @param array $data 要转换的数据数组，默认为空数组
	 * @return string JSON格式的字符串
	 * @example
	 * ```php
	 * // 返回简单的数据
	 * $json = $db->jsonResult(['status' => 1, 'msg' => '操作成功']);
	 * 
	 * // 返回查询结果
	 * $result = $db->select();
	 * $json = $db->jsonResult(['data' => $result, 'count' => count($result)]);
	 * ```
	 */
	public function jsonResult(array $data = []): string
	{
		return json($data);
	}
	
	/**
	 * 返回数组格式数据
	 * 
	 * 直接返回数据数组，可用于数据处理或格式转换。
	 * 提供统一的返回方法接口。
	 * 
	 * @param array $data 要返回的数据数组，默认为空数组
	 * @return array 原始数据数组
	 * @example
	 * ```php
	 * // 返回简单的数据
	 * $array = $db->arrayResult(['status' => 1, 'msg' => '操作成功']);
	 * 
	 * // 返回查询结果
	 * $result = $db->select();
	 * $array = $db->arrayResult(['data' => $result, 'count' => count($result)]);
	 * ```
	 */
	public function arrayResult(array $data = []): array
	{
		return $data;
	}
	
	/**
	 * 分页查询
	 *
	 * 执行分页查询，返回分页数据和分页信息。
	 * 支持传入每页记录数和当前页码。
	 *
	 * @param mixed ...$args 参数，可以是 [每页记录数] 或 [每页记录数, 当前页码]
	 * @return array 包含分页数据和分页信息的数组
	 *   - data: 当前页的数据数组
	 *   - total: 总记录数
	 *   - page: 当前页码
	 *   - limit: 每页记录数
	 *   - pageCount: 总页数
	 */
	public function page(...$args): array
	{
		$simple = true;
		$config = [];
		$select = true;
		if(empty($args)){
			$page = C('page') ?: (I('get.page') ?: 1);	//页数
			$number = C('limit') ?: (I('get.limit') ?: 10);	//每页的数量
			//分页配置
			if(C('pageConfig')){
				$config = C('pageConfig');
				$simple = false;
			}
		}else{
			$page = is_numeric($args[0]) ? $args[0] : (C('page') ?: 1);	//页数
			$number = is_numeric($args[1]) ? $args[1] : (C('number') ?: 10);	//页数
			//分页配置
			if(!empty($args[3]) || C('pageConfig')){
				$config = empty($args[3]) ? C('pageConfig') : $args[3];
				$simple = false;
			}
			if(!empty($args[2])){
				$select = false;
				$r_select = $args[2];
			}
		}
		
		$listNumber = C('listNumber') ?: 5;	//页数表个数
		$start = floor($listNumber/2);
		$fetchConfig = $this->sql['fetch'] ?? '';
		$skip = ($page - 1) * $number;

		if ($select) {
			$pageData = $this->total($skip, $number);
			$total = $pageData['total'];
			$list = $pageData['list'];
		} else {
			$sqlSnapshot = $this->getSqlState();
			$total = $this->executeCount();
			$this->setSqlState($sqlSnapshot);
			$list = $this->limit($skip, $number)->r_select($r_select);
		}
		
		//分页模式
		if($simple){
			$totalpage = ceil($total/$number);
			$first = 1;
			$pre = $page > 1 ? $page - 1 : 1;
			$next = $page < $totalpage ? $page + 1 : $totalpage;
			$last = $totalpage;
			$data = ['number'=>$number, 'total'=>$total, 'totalpage'=>$totalpage, 'first'=>$first, 'last'=>$last, 'nowpage'=>$page];
			
			//上一页
			if($pre != $page)
				$data['pre'] = $pre;
			
			//下一页
			if($next != $page)
				$data['next'] = $next;
			
			//list
			$pages = [];
			if($totalpage > $listNumber){
				$start = ($page + $start) > $totalpage ? 
				(($page - $start) > 1 ? ($page + $start - $totalpage) > ($page - $start) ? 1 : ($totalpage - 2 * $start) : 1) : (($page - $start) > 1 ? $page - $start : 1);
				$end = $start + $listNumber;
				for($i=$start;$i<$end;$i++){
					$pages[] = $i;
				}
			}else{
				for($i=1;$i<=$totalpage;$i++){
					$pages[] = $i;
				}
			}
			$data['pages'] = $pages;
			
			//fetch
			if($fetchConfig != '' && $totalpage != 1){
				$fetchName = $fetchConfig[0];
				$view = N('View');
				$view->assign('page', $data);
				$data = $view->compileFile($fetchName);
			}else{
				$data = '';
			}
		}else{
			$pageClass = N('Page');
			$pageClass->loadConfig($config);
			$pageClass->total($total);
			$pageClass->num($number);
			$pageClass->page($page);
			$pageClass->center($start);
			$pageClass->circle($listNumber);
			$data = $pageClass->output();
		}
		
		return ['list'=>$list,'page'=>$data];
	}
}
