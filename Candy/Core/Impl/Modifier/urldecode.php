<?php
function view_modifier_urldecode(string $string,string $title = ''): string
{
	if($string ==''){
		return '';
	}
	return urldecode($string);
}
