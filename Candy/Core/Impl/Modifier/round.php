<?php
function view_modifier_round(string $string,string $format = ''): string
{
	if($string<=0) return '';
    if($string != '' && is_numeric($string) ){		
		return round($string,$format);
	}else{
		return $string;
	}
}
