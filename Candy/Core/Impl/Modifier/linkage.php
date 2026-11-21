<?php
function view_modifier_linkage(string $string, string $parse_var = '', array $viewVars = []): string
{
	if($string=='') return '';
	$linkage_result = $viewVars['_G']['linkage'];
	$_parse_var = explode('/',$parse_var);
	$parse_var = $_parse_var[0];
	
	$var = explode(',',$string);
	$result = [];
	foreach ($var as $key => $val){
		if(isset($_parse_var[1]) && $_parse_var[1] =='value'){
			foreach ($linkage_result[$parse_var] as $key => $value){
				if($linkage_result[$val]==$value){
					$result[] = $key;
				}
			}
		}elseif($parse_var != ''){
			$result[] = $linkage_result[$parse_var][$val];
		}elseif(isset($linkage_result[$val])){
			$result[] = $linkage_result[$val];
		}
	}
	
	return join(',',$result);
}
