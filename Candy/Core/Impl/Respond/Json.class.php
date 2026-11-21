<?php
/***
 * Candy框架 Html Response
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2019-08-01 23:42:51 $   
 */
 
declare(strict_types=1);
namespace Candy\Core\Impl\Respond;

use \Candy\Core\Respond;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Json extends Respond
{
    /**
     * 输出type
     * @var string
     */
    protected $contentType = 'application/json';

    /**
     *   construct
     * @param mixed $content
     * @param mixed $code
     * @return mixed
     */
    public function __construct($content, $code)
    {
        // JSON 接口禁止追加页面底部调试栏，否则响应体无法被 JSON.parse 解析
        $this->skipDebugBar = true;
        $this->init($content, $code);
		$data = $this->output('');
		if(isWebServer()){
			headers('Content-Type:application/json; charset=utf-8');
		}else{
			header('Content-Type:application/json; charset=utf-8');
		}
		$this->content = $content;
    }
}
