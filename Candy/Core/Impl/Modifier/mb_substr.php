<?php
function view_modifier_mb_substr(string $string = '', int $start = 0, ?int $length = null, string $encoding = 'UTF-8'): string
{
	if ($string === '') {
		return '';
	}
	$result = mb_substr($string, $start, $length, $encoding);

	return $result === false ? '' : $result;
}
