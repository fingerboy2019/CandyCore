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

final class Html extends Respond
{
    /**
     * 输出type
     * @var string
     */
    protected $contentType = 'text/html';

    /**
     *   construct
     * @param mixed $content
     * @param mixed $code
     * @return mixed
     */
    public function __construct($content, $code)
    {
        $this->init($content, $code);
    }
}
