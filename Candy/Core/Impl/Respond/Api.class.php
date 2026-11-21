<?php
/***
 * Candy框架 Api Response
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2019-08-01 23:42:51 $   
 */
 
declare(strict_types=1);
namespace Candy\Core\Impl\Respond;

use \Candy\Core\Respond;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Api extends Respond
{
    /**
     *   construct
     * @param mixed $content
     * @param mixed $code
     * @return mixed
     */
    public function __construct($content, $code)
    {
        $format = strtolower((string)C('API'));
        if ($format !== '') {
            $safeFormat = preg_replace('/[^a-z0-9.+_-]/i', '', $format);
            $this->contentType = 'application/' . $safeFormat;
        }
        $this->init($content, $code);
		$apiFunc = 'apiFunc'. C('API');
        $apiData = C('APIDATA');
        $hasFormatHook = is_array($apiData)
            && isset($apiData['formatFunction'])
            && $apiData['formatFunction'] instanceof \Closure;
		$this->content = !$hasFormatHook && function_exists($apiFunc)
			? (string)$apiFunc($content)
			: (string)$content;
    }
}
