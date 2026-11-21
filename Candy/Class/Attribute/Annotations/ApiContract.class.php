<?php
/***
 * Apidoc 契约绑定注解
 *
 * 业务说明：显式指定 Validate scene / Schema；与约定推断混合使用
 * 不参与 Node 权限扫描
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 20:04:18 $
 *
 * @package Candy\Extend\Attribute\Annotations
 */

declare(strict_types=1);

namespace Candy\Extend\Attribute\Annotations;

use Candy\Extend\Attribute\utils\AbstractAnnotation;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 绑定 Validate / Schema 供 Apidoc 补全字段
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class ApiContract extends AbstractAnnotation
{
	/** @var string Validate 短名或 FQCN */
	public string $validate = '';

	/** @var string 场景名 add|mod|… */
	public string $scene = '';

	/** @var string Schema 短名或 FQCN */
	public string $schema = '';

	/** @var string item|list|page|none，空则用 Schema::dataType() */
	public string $dataType = '';

	/**
	 * @param string $validate Validate 短名或类名
	 * @param string $scene 场景
	 * @param string $schema Schema 短名或类名
	 * @param string $dataType data 形态
	 */
	public function __construct(
		string $validate = '',
		string $scene = '',
		string $schema = '',
		string $dataType = ''
	) {
		// 不走 AbstractAnnotation::formatParams（多命名参数会被压成首参）
		$this->validate = $validate;
		$this->scene = $scene;
		$this->schema = $schema;
		$this->dataType = $dataType;
	}
}
