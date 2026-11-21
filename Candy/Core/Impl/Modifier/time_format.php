<?php
function view_modifier_time_format(int $time,string $format = ''): string
{
	$stime = time()-$time;
	if($stime<60){
		$display = round($stime).'秒前';
	}elseif($stime<60*60){
		$display = round($stime/60).'分钟前';
	}elseif($stime<60*60*24){
		$display = round($stime/3600).'小时前';
	}else{
		$display = round($stime/(3600*24)).'天前';
	}	
	return $display;
}
?>