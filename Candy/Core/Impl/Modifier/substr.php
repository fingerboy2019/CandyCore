<?php
function view_modifier_substr(string $string = '', int $start = 0, ?int $length = null): string
{
	if ($string === '') {
		return '';
	}
	$result = $length === null ? substr($string, $start) : substr($string, $start, $length);

	return $result === false ? '' : $result;
}
