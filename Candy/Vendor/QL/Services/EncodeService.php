<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 2017/9/20
 * 编码转换服务
 */

namespace QL\Services;

use \QL\QueryList;

/**
 * 编码转换服务类
 * 提供字符编码检测和转换功能
 */
class EncodeService
{
    /**
     * 转换HTML编码
     *
     * @param QueryList $ql QueryList实例
     * @param string $outputEncoding 输出编码
     * @param string|null $inputEncoding 输入编码，默认为自动检测
     * @return QueryList
     */
    public static function convert(QueryList $ql,string $outputEncoding,string $inputEncoding = null)
    {
        $html = $ql->getHtml();
        $inputEncoding || $inputEncoding = self::detect($html);
        $html = iconv($inputEncoding,$outputEncoding.'//IGNORE',$html);
        $ql->setHtml($html);
        return $ql;
    }

    /**
     * 检测字符串编码
     *
     * @param string $string 要检测的字符串
     * @return bool|false|mixed|string 检测到的编码
     */
    public static function detect($string)
    {
        $charset=mb_detect_encoding($string, ['ASCII', 'GB2312', 'GBK', 'UTF-8'],true);
        if(strtolower($charset)=='cp936')
            $charset='GBK';
        return $charset;
    }

}