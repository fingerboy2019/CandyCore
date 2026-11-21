<?php
function view_modifier_number_format(mixed $number = 0, int $decimals = 2, string $decimal_point = '.', string $thousands_sep = ','): string
{
	if (!is_numeric($number)) {
		return (string) $number;
	}

	return number_format((float) $number, $decimals, $decimal_point, $thousands_sep);
}
