<?php
function view_modifier_html_format(string $string): string
{
	$string = preg_replace("/<.+?>/i",'',$string); //替换所有HTML的标识
	return $string;
}
