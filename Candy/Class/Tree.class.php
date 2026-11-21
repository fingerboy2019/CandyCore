<?php
/***
 * 无限级分类树处理类
 *
 * 业务说明：菜单/分类等树形数据的构建、遍历、父子查询与 select 选项生成
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-17 22:12:00 $
 *
 * @package Candy\Extend
 */
 
declare(strict_types=1);
namespace Candy\Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Tree {
	/**
	 * 分类的父ID的键名(key)
	 * 
	 * @var string
	 */
	private $parentId;
	
	/**
	 * 分类的ID(key)
	 * 
	 * @var string
	 */
	private $id;
	
	/**
	 * 分类名的键名(key)
	 * 
	 * @var string
	 */
	private $name;
	
	/**
	 * 存储树形数据的临时数组
	 * 
	 * @var array
	 */
	private $data;
	
	/**
	 * 构造函数
	 *
	 * 初始化Tree类，设置默认的字段映射关系
	 * 默认配置：parent_id作为父ID字段，id作为主键字段，name作为名称字段
	 *
	 * @return void
	 */
	public function __construct()
	{
		$this->parentId	= 'parent_id';
		$this->id		= 'id';
		$this->name		= 'name';
		
		return true;
	}
	
	/**
	 * 无限级分类树-初始化配置
	 * 
	 * 设置分类树的字段映射关系，可自定义父ID、主键和名称字段
	 * 
	 * @param array $params 配置分类的键名数组
	 * @param string $params['parent_id'] 父ID字段名（可选，默认为'parent_id'）
	 * @param string $params['id'] 主键字段名（可选，默认为'id'）
	 * @param string $params['name'] 名称字段名（可选，默认为'name'）
	 * @return self|false 成功返回Tree实例，失败返回false
	 *
	 * @example
	 * $params = ['parent_id' => 'pid', 'id' => 'cat_id', 'name' => 'cat_name'];
	 * $this->config($params);
	 */
	public function config(array $params): self|false
	{
		if (!$params || !is_array($params)) return false;
		$this->parentId = $params['parent_id'] ??= $this->parentId;
		$this->id       = $params['id']        ??= $this->id;
		$this->name     = $params['name']      ??= $this->name;
		return $this;
	}			
	
	/**
 * 无限级分类树-获取树
 * 
 * @param array $data 树的数组
 * @param int|string $parent_id 初始化树时候，代表ID下的所有子集
 * @param int|string|null $select_id 选中的ID值
 * @param string $pre_fix 前缀
 * @param bool $child 是否禁用父栏目
 * @param int|string|null $modelid 模型ID
 * @param int|string|null $groupid 分组ID
 * @param bool $guest 是否支持游客
 * @param int|string|null $roleid 后台角色组ID
 * @return string|array 生成的树状结构HTML选项或数组
 */
public function getTree($data, $parentId = 0, $selectId = null, $prefix = '|-', $child = false, $modelId = null, $groupId = null, $guest = false, $roleId = null): string|array
	{
		if (!$data || !is_array($data)) return '';
		$string = '';
		$srt = '';
		foreach ($data as $key => $value) {
		    if ($value['typeid'] == 3) continue;
		    if ($child && ($value['typeid'] != 1 && $value['child'] == 0)) continue;
			if ($value[$this->parentId] == $parentId) {
				$str = '<option value=\'' . $value[$this->id] . '\'';
				if (!is_null($selectId)) {
					$str .= ($value[$this->id] == $selectId) ? ' selected="selected"' : '';
				}
				if ($child && $value['child'] == 1) {
				    $str .= ' disabled';
				} elseif (($modelId || $groupId) && isset($value['setting']['memberpost']) && $value['setting']['memberpost']) {
				    //会员权限判断v1.6
					if ($value['setting']['modelpost'] && in_array($modelId, $value['setting']['modelpost'])) {
						if ($value['child'] == 0) continue;
					    $str .= ' disabled';
					} elseif ($value['setting']['grouppost'] && in_array($groupId, $value['setting']['grouppost'])) {
						if ($value['child'] == 0) continue;
						$srt .= ' disabled';
					}
				} elseif ($guest && (!isset($value['setting']['guestpost']) || empty($value['setting']['guestpost']))) {
					if ($value['child'] == 0) continue;
					$str .= ' disabled';
				} elseif ($roleId && isset($value['setting']['adminpost']) && $value['setting']['adminpost'] && @in_array($roleId, $value['setting']['rolepost'])) {
					if ($value['child'] == 0) continue;
					$str .= ' disabled';
				}
				$string .= $str . '>' . ($value['parentid'] == 0 ? '' : $prefix) . $value[$this->name] . '</option>';
				
				$string .= $this->getTree($data, $value[$this->id], $selectId, '&nbsp;&nbsp;' . $prefix, $child, $modelId, $groupId, $guest, $roleId);
			}
		}
		return $string ;
	}
	
	/**
	 * 无限级分类树-获取模型树
	 * 
	 * 专为模型分类设计的树结构生成方法，支持模型类型筛选和选中状态
	 * 
	 * @param  array          $data       树的数组数据
	 * @param  int|string     $parent_id  初始化树时候，代表ID下的所有子集
	 * @param  int|string|null $select_id  选中的ID值
	 * @param  string         $pre_fix    前缀
	 * @param  int|string     $modelid    模型的ID值
	 * @param  int|string|null $_modelid   辅助模型ID
	 * @param  int|string|null $_groupid   分组ID
	 * @param  int|string|null $_roleid    角色ID
	 * @return string|array               生成的模型树HTML选项或数组
	 */
	public function getModelTree(array $data, int|string $parentId = 0, int|string|null $selectId = null, string $prefix = '|-', int|string|null $modelId = null, int|string|null $auxModelId = null, int|string|null $auxGroupId = null, int|string|null $auxRoleId = null): string|array
	{
		if (!$data || !is_array($data)) return '';
		$string = '';
		foreach ($data as $key => $value) {
		    if ($value['typeid'] == 3 || ($value['arrmodelid'] && !in_array($modelId, $value['arrmodelid']))) continue;
			if ($value[$this->parentId] == $parentId) {
				$str = '<option value=\'' . $value[$this->id] . '\'';
				if (!is_null($selectId)) $str .= ($value[$this->id] == $selectId) ? ' selected="selected"' : '';
				if ($value['child'] == 1) {
					$str .= ' disabled';
				} elseif (($auxModelId || $auxGroupId) && isset($value['setting']['memberpost']) && $value['setting']['memberpost']) {
				    //会员权限判断v1.6
					if ($value['setting']['modelpost'] && in_array($auxModelId, $value['setting']['modelpost'])) {
						if ($value['child'] == 0) continue;
					    $str .= ' disabled';
					} elseif ($value['setting']['grouppost'] && in_array($auxGroupId, $value['setting']['grouppost'])) {
						if ($value['child'] == 0) continue;
						$str .= ' disabled';
					}
				} elseif ($auxRoleId && isset($value['setting']['adminpost']) && $value['setting']['adminpost'] && @in_array($auxRoleId, $value['setting']['rolepost'])) {
					$str .= ' disabled';
				}
				$string .= $str . '>' . ($value['parentid']==0 ? '' : $prefix) . $value[$this->name] . '</option>';
				$string .= $this->getModelTree($data, $value[$this->id], $selectId, '&nbsp;&nbsp;' . $prefix, $modelId, $auxModelId, $auxGroupId, $auxRoleId);
			}
		}
		return $string ;
	}
	
	/**
	 * 无限级分类树-获取树
	 * 
	 * 将一维数组转换为带有前缀的层级数据，方便在列表中展示层级关系
	 * 
	 * @param  array        $data      树的数组数据
	 * @param  int|string   $parent_id 初始化树时，代表ID下的所有子集，默认为0
	 * @param  string       $pre_fix   用于表示层级的前缀，默认为"|-"
	 * @return string|array            返回带有层级前缀的数据数组或空字符串
	 */
	public function getTreeData(array $data, $parentId = 0, string $prefix = '|-'): string|array
	{
		if (!$data || !is_array($data)) return '';
		foreach ($data as $key => $value) {
			if ($value[$this->parentId] == $parentId) {
				$this->data[$key] = $value;
				$this->data[$key]['prefix'] = $prefix . $value[$this->name];
				$this->getTreeData($data, $value[$this->id], '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $prefix);
			}
		}
		return $this->data ;
	}
	
	
	/**
	 * 无限级分类树-获取子类
	 * 
	 * 获取指定节点的直接子节点
	 * 
	 * @param  array        $data 树的数组数据
	 * @param  int|string   $id   父类ID
	 * @return string|array       子节点数据数组或空数组
	 */
	public function getChild(array $data, $id): string|array
	{
		if (!$data || !is_array($data)) return [];
		$tempArray = [];
		foreach ($data as $value) {
			if ($value[$this->parentId] == $id) {
				$tempArray[] = $value;
			}
		}
		return $tempArray;
	}
	
	/**
	 * 无限级分类树-获取父类
	 * 
	 * 获取指定节点的父节点信息
	 * 
	 * @param  array        $data 树的数组数据
	 * @param  int|string   $id   子类ID
	 * @return string|array       父节点数据数组或空数组
	 */
	public function getParent(array $data, $id): string|array
	{
		if (!$data || !is_array($data)) return [];
		$temp = [];
		foreach ($data as $vaule) {
			$temp[$vaule[$this->id]] = $vaule;
		}
		$parentid = $temp[$id][$this->parentId];
		return $temp[$parentid];
	}
}

