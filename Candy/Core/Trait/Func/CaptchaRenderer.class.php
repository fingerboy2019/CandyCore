<?php
declare(strict_types=1);

namespace Candy\Core\Trait\Func;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class CaptchaRenderer
{
	/**
	 * 生成并输出柔和彩色风字符验证码
	 *
	 * 保持 verificationCode() 的历史调用签名、存储方式和 JPEG 输出格式不变。
	 *
	 * @param string $code 验证码内容，留空时自动生成
	 * @param string $key Session/缓存键
	 * @param int $height 图片高度
	 * @param int $width 图片宽度
	 * @return void
	 */
	public function render(string $code = '', string $key = '', int $height = 27, int $width = 80): void
	{
		$code = strtoupper($code !== '' ? $code : getRandomStr(4, 2));
		$length = max(1, strlen($code));
		$width = $width > 0 ? max(40, $width) : max(80, $length * 20);
		$height = max(22, $height);
		$image = imagecreatetruecolor($width, $height);
		if ($image === false) {
			die('create image error!');
		}
		imageantialias($image, true);
		$this->drawGradient($image, $width, $height);
		$this->drawSoftPattern($image, $width, $height);
		$this->drawCharacters($image, $code, $width, $height);

		$borderColor = imagecolorallocate($image, 202, 216, 232);
		imagerectangle($image, 0, 0, $width - 1, $height - 1, $borderColor);

		$key = $key !== '' ? $key : 'code';
		// 先写入 Session/缓存，再输出图片（避免响应头发送后 Session 丢失）
		if (function_exists('captcha_store')) {
			captcha_store($code, $key);
		} else {
			session($key, strtoupper($code));
		}
		headers('Content-Type: image/jpeg');
		headers('Cache-Control: no-cache,must-revalidate');
		headers('Pragma: no-cache');
		headers('Expires: -1');
		headers('Last-Modified: ' . gmdate('D, d M Y 00:00:00', gmTime()) . ' GMT');
		// WebServer（尤其 Swoole）不能只依赖 echo/外层输出缓冲提交二进制响应。
		// 先在局部缓冲区生成 JPEG，再同步写入当前请求的响应适配器，避免
		// 控制器异常退出时外层缓冲被清理后出现空响应、错误 302 或 Location: //。
		ob_start();
		imagejpeg($image, null, 92);
		$jpeg = (string)ob_get_clean();
		imagedestroy($image);

		// Swoole 在捕获 exit() 时会清理 PHP 输出缓冲，必须先把内容交给适配器；
		// Workerman/FPM 仍保留 echo，兼容原有的脚本输出链路。
		$response = \Candy\Core\Container::getInstance()->get('Response');
		if (is_object($response) && method_exists($response, 'withBody')) {
			$response->withBody($jpeg);
		}
		echo $jpeg;
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}
		if (PHP_SAPI !== 'cli') {
			exit;
		}
	}

	/**
	 * 绘制浅蓝到浅绿色的柔和渐变背景
	 *
	 * @param \GdImage $image 图片资源
	 * @param int $width 图片宽度
	 * @param int $height 图片高度
	 * @return void
	 * @access private
	 */
	private function drawGradient(\GdImage $image, int $width, int $height): void
	{
		$start = [238, 245, 255];
		$end = [232, 248, 243];
		for ($y = 0; $y < $height; $y++) {
			$ratio = $y / max(1, $height - 1);
			$red = (int)round($start[0] + ($end[0] - $start[0]) * $ratio);
			$green = (int)round($start[1] + ($end[1] - $start[1]) * $ratio);
			$blue = (int)round($start[2] + ($end[2] - $start[2]) * $ratio);
			imageline($image, 0, $y, $width - 1, $y, imagecolorallocate($image, $red, $green, $blue));
		}
	}

	/**
	 * 绘制低对比几何纹理、曲线和微粒
	 *
	 * @param \GdImage $image 图片资源
	 * @param int $width 图片宽度
	 * @param int $height 图片高度
	 * @return void
	 * @access private
	 */
	private function drawSoftPattern(\GdImage $image, int $width, int $height): void
	{
		$blueBubble = imagecolorallocatealpha($image, 71, 123, 180, 105);
		$greenBubble = imagecolorallocatealpha($image, 66, 151, 123, 108);
		for ($i = 0; $i < 3; $i++) {
			$diameter = random_int(max(7, (int)floor($height * .24)), max(9, (int)floor($height * .52)));
			imagefilledellipse(
				$image,
				random_int(2, max(2, $width - 3)),
				random_int(2, max(2, $height - 3)),
				$diameter,
				$diameter,
				$i % 2 === 0 ? $blueBubble : $greenBubble
			);
		}

		$curveColors = [
			imagecolorallocatealpha($image, 52, 102, 164, 103),
			imagecolorallocatealpha($image, 48, 137, 109, 107),
		];
		foreach ($curveColors as $index => $curveColor) {
			$previousX = 0;
			$previousY = (int)round($height * ($index === 0 ? .38 : .66));
			for ($x = 3; $x < $width; $x += 3) {
				$wave = sin(($x / max(1, $width)) * M_PI * 2 + $index * 1.8);
				$y = (int)round($height * ($index === 0 ? .38 : .66) + $wave * max(2, $height * .1));
				imageline($image, $previousX, $previousY, $x, $y, $curveColor);
				$previousX = $x;
				$previousY = $y;
			}
		}

		$particleColor = imagecolorallocatealpha($image, 45, 91, 143, 91);
		$particleCount = max(5, min(14, (int)round($width * $height / 380)));
		for ($i = 0; $i < $particleCount; $i++) {
			imagefilledellipse(
				$image,
				random_int(2, max(2, $width - 3)),
				random_int(2, max(2, $height - 3)),
				random_int(1, 2),
				random_int(1, 2),
				$particleColor
			);
		}
	}

	/**
	 * 均匀绘制高对比验证码字符
	 *
	 * @param \GdImage $image 图片资源
	 * @param string $code 验证码内容
	 * @param int $width 图片宽度
	 * @param int $height 图片高度
	 * @return void
	 * @access private
	 */
	private function drawCharacters(\GdImage $image, string $code, int $width, int $height): void
	{
		$length = max(1, strlen($code));
		$font = 5;
		$fontWidth = imagefontwidth($font);
		$fontHeight = imagefontheight($font);
		$horizontalPadding = max(4, (int)round($width * .07));
		$availableWidth = max(1, $width - $horizontalPadding * 2);
		$slotWidth = $availableWidth / $length;
		$targetHeight = max($fontHeight, min(24, (int)round($height * .58)));
		$scale = $targetHeight / $fontHeight;
		$targetWidth = max($fontWidth, (int)round($fontWidth * $scale));
		$baseY = max(2, (int)floor(($height - $targetHeight) / 2));
		$colors = [
			imagecolorallocate($image, 35, 76, 128),
			imagecolorallocate($image, 28, 107, 91),
			imagecolorallocate($image, 48, 83, 142),
		];

		for ($i = 0; $i < $length; $i++) {
			$x = (int)round($horizontalPadding + $i * $slotWidth + max(0, ($slotWidth - $targetWidth) / 2));
			$y = max(1, min($height - $targetHeight - 1, $baseY + random_int(-2, 2)));
			$this->drawScaledCharacter(
				$image,
				$code[$i],
				$x,
				$y,
				$targetWidth,
				$targetHeight,
				$colors[$i % count($colors)]
			);
		}
	}

	/**
	 * 无外部字体依赖地放大 GD 内置粗体字符
	 *
	 * @param \GdImage $image 目标图片
	 * @param string $character 单个字符
	 * @param int $x 目标 X 坐标
	 * @param int $y 目标 Y 坐标
	 * @param int $width 目标字符宽度
	 * @param int $height 目标字符高度
	 * @param int $color 目标字符颜色
	 * @return void
	 * @access private
	 */
	private function drawScaledCharacter(
		\GdImage $image,
		string $character,
		int $x,
		int $y,
		int $width,
		int $height,
		int $color
	): void {
		$font = 5;
		$sourceWidth = imagefontwidth($font);
		$sourceHeight = imagefontheight($font);
		$mask = imagecreate($sourceWidth, $sourceHeight);
		if ($mask === false) {
			imagestring($image, $font, $x, $y, $character, $color);
			return;
		}
		$transparent = imagecolorallocate($mask, 255, 0, 255);
		imagefill($mask, 0, 0, $transparent);
		imagecolortransparent($mask, $transparent);
		$maskColor = imagecolorallocate($mask, 255, 255, 255);
		imagestring($mask, $font, 0, 0, $character, $maskColor);

		$colored = imagecreatetruecolor($width, $height);
		if ($colored === false) {
			imagedestroy($mask);
			imagestring($image, $font, $x, $y, $character, $color);
			return;
		}
		$coloredTransparent = imagecolorallocate($colored, 255, 0, 255);
		imagefill($colored, 0, 0, $coloredTransparent);
		imagecolortransparent($colored, $coloredTransparent);
		imagecopyresized($colored, $mask, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

		for ($pixelY = 0; $pixelY < $height; $pixelY++) {
			for ($pixelX = 0; $pixelX < $width; $pixelX++) {
				if (imagecolorat($colored, $pixelX, $pixelY) !== $coloredTransparent) {
					imagesetpixel($image, $x + $pixelX, $y + $pixelY, $color);
				}
			}
		}
		imagedestroy($colored);
		imagedestroy($mask);
	}
}
