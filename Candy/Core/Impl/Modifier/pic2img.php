<?php
function view_modifier_pic2img(string $string,string $title = ''): string
{
	if($string =='') return '';
	$string = explode(',',$string);
	$display = '';
	foreach ($string as  $key => $value){
		if($value!=''){
			if($value[0]!='/'){
				$value = '/'.$value;
			}
			$display .= "<a href='{$value}' target='_blank'><img src='{$value}' border=0 title='$title'></a> ";  
		}
	}
	return $display;
}
