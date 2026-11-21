<?php
function view_modifier_nl2br(string $string = '', bool $xhtml = true): string
{
	if ($string === '') {
		return '';
	}

	return nl2br($string, $xhtml);
}
