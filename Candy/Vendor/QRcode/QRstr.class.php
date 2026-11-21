<?php
/**
 * PHP QR Code encoder (LGPL 3)
 *
 * 拆分自合并版单文件：每个类独立成文件，框架自动加载（Qrcode\类名）。
 * 版权与协议见同目录 LICENSE。
 *
 * @package Qrcode
 */

namespace QRcode;

require_once __DIR__ . '/Constants.class.php';

	class QRstr {
		public static function set(&$srctab, $x, $y, $repl, $replLen = false) {
			$srctab[$y] = substr_replace($srctab[$y], ($replLen !== false)?substr($repl,0,$replLen):$repl, $x, ($replLen !== false)?$replLen:strlen($repl));
		}
	}	

