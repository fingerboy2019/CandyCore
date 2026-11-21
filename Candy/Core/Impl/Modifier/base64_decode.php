<?php
function view_modifier_base64_decode(string $string): string
{
	if($string ==''){
		return '';
	}
	return base64_decode($string);
}
