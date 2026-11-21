<?php
function view_modifier_br2nl(string $string): string
{
	return preg_replace('/<br\\s*?\/??>/i', '', $string);     
}
