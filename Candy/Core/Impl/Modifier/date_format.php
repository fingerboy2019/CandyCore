<?php
function view_modifier_date_format(string $string,string $format = '',$ext = '',$ext2 = ''): string
{
	if($string<=0){
		return '';
	}
	$format .= (empty($ext) ? '' : ':'.$ext) . (empty($ext2) ? '' : ':'.$ext2);
    if($string != '' && is_numeric($string)){
		if($format==''){
			$format= 'Y-m-d H:i:s';
		}
		return date($format,$string);
	}else{
		return date($format,strtotime($string));
	}
}
