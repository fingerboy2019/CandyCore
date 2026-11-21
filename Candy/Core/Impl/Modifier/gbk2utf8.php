<?php
function view_modifier_gbk2utf8(string $string): string
{
	if($string=='') return '';
	$str = iconv('GBK', 'UTF-8', $string);
	return $str;
}
