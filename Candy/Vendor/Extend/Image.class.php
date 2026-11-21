<?php
/**
 * Image 图像处理类
 * 
 * 用于处理各种图像操作的工具类，支持图像缩略图生成、水印添加和图像裁剪等功能。
 * 基于PHP的GD库实现，支持GIF、JPEG和PNG等常见图像格式。
 * 
 * 主要功能特性：
 * - 图像缩放：按比例缩放图像，生成缩略图
 * - 水印添加：在图像上添加图片水印，支持多种水印位置
 * - 图像裁剪：从大图中裁剪指定区域的图像
 * - 支持GIF、JPEG、PNG等常见图像格式
 * 
 * 使用示例：
 * ```php
 * // 创建Image实例
 * $image = new Image('/path/to/images');
 * 
 * // 生成缩略图
 * $thumbnail = $image->thumb('original.jpg', 200, 150, 'th_');
 * 
 * // 添加水印
 * $watermarked = $image->waterMark('original.jpg', 'watermark.png', 5, 'wa_');
 * 
 * // 裁剪图像
 * $cropped = $image->cut('original.jpg', 0, 0, 300, 200, 'cu_');
 * ```
 * 
 * @package Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2024-09-14 01:28:06
 */
 
// 声明GdImage类型，以便在命名空间内正确使用
declare(strict_types=1);
namespace Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Image {
	/**
	 * 安全相对路径：解析 . / .. 段并钳制在根内，防止路径穿越写文件
	 *
	 * @param string $path 原始相对路径
	 * @return string 规范化后的相对路径（无前导/后缀斜杠）
	 */
	private function safeRelPath(string $path): string
	{
		$path = str_replace('\\', '/', trim($path, '/'));
		if ($path === '') {
			return '';
		}
		$segments = [];
		foreach (explode('/', $path) as $seg) {
			if ($seg === '' || $seg === '.') {
				continue;
			}
			if ($seg === '..') {
				array_pop($segments); // 钳制到根，禁止越出
				continue;
			}
			$segments[] = $seg;
		}
		return implode('/', $segments);
	}

	/**
	 * 图片处理的基础路径
	 * 
	 * 存储图片操作的基础目录路径，所有图片处理操作都会基于此路径查找和保存文件
	 * 默认值为框架的文件上传目录，可在构造函数中自定义
	 * 
	 * @var string
	 */
	protected $path;
	
	/**
	 * 构造方法
	 * 
	 * 初始化Image类的实例，设置图片处理的基础路径。
	 * 
	 * @param string $path 图片处理的基础路径，如果不提供则使用默认的上传目录
	 */
	public function __construct(string $path = '')
	{
		if($path == '') {
			$path = (string)(C('C_UP_PATH') ?: \Candy\Core\Bootstrap::uploadRoot());
		}
		
		$this->path = $path;
	}
	
	/**
	 * 图像缩放（创建缩略图）
	 * 
	 * 将原图按指定尺寸缩放，生成新的缩略图文件。缩放过程保持图像比例，
	 * 并将缩放后的图像居中放置在指定尺寸的画布中，多余部分透明或填充白色。
	 * 
	 * @param string $name 原始图像文件名，相对于$path路径
	 * @param int $width 生成缩略图的宽度
	 * @param int $height 生成缩略图的高度
	 * @param string $prefix 缩略图文件名前缀，默认为'th_'
	 * @return string 生成的缩略图文件名，包含前缀
	 * @throws Exception 当图像文件不存在或格式不支持时抛出异常
	 */
	public function thumb($name, int $width, int $height, string $prefix = 'th_'): string
	{
		$path = '.';
		if(is_array($name))
			[$name, $path] = $name;
		
		//获取图片信息
		$imgInfo = $this->getInfo($name, $path);   
		//获取图片资源        
		$srcImg = $this->getImg($name, $imgInfo, $path);                
		//获取新图片尺寸
		$size = $this->getNewSize($name, $width, $height, $imgInfo);     
		//获取新的图片资源
		if ($srcImg !== false) {
			$newImg = $this->kidOfImage($srcImg, $size, $imgInfo);
		} else {
			return false;
		}  
		//新的储存位置（随当前租户缩略图根）
		$thumbRoot = (string)(C('C_UPCW_PATH') ?: \Candy\Core\Bootstrap::thumbRoot());
		$relPath = $this->safeRelPath($path);
		$newPath = rtrim($thumbRoot, '/\\') . ($relPath === '' ? '' : '/' . $relPath) . '/';
		//返回新生成的缩略图的名称，以'th_'为前缀	
		return $this->createNewImage($newImg, $prefix.$name, $imgInfo, $newPath);    
	}
	
	/**
	 * 添加图片水印
	 * 
	 * 在原图上添加指定的水印图片，可以设置水印位置。支持多种水印位置定位方式，
	 * 水印图片会被适当调整大小以确保在原图中显示协调。
	 * 
	 * 水印位置说明：
	 * 1 - 左上角，2 - 中上，3 - 右上角
	 * 4 - 左中，5 - 中央，6 - 右中
	 * 7 - 左下角，8 - 中下，9 - 右下角
	 * 
	 * @param string $groundName 原始图像文件名，相对于$path路径
	 * @param string $waterName 水印图像文件名，相对于$path路径
	 * @param int $waterPos 水印位置，1-9的数字，默认为0（随机位置）
	 * @param string $qz 生成的水印图片文件名前缀，默认为'wa_'
	 * @return string 生成的带水印图片的文件名，包含前缀
	 * @throws Exception 当图像文件不存在或格式不支持时抛出异常
	 */ 
	public function watermark(string $groundName, string $waterName, int $waterPos = 0, string $prefix = 'wa_'): mixed
	{
		$curpath = rtrim($this->path,'/').'/';
		$dir = dirname($waterName);
		if($dir == '.'){
			$wpath = $curpath;
		}else{
			$wpath = $dir.'/';
			$waterName = basename($waterName);
		
		}
		
		if(file_exists($curpath.$groundName) && file_exists($wpath.$waterName)){
			$groundInfo = $this->getInfo($groundName);               //获取背景信息
			$waterInfo = $this->getInfo($waterName, $dir);                 //获取水印图片信息

			if(!$pos = $this->position($groundInfo, $waterInfo, $waterPos)){
				\Candy\Core\Debug::addMsg('<font color=\'red\'>水印不应该比背景图片小！</font>');
				return false;
			}

			$groundImg = $this->getImg($groundName, $groundInfo);    //获取背景图像资源
		$waterImg = $this->getImg($waterName, $waterInfo, $dir);       //获取水印图片资源    

		if ($groundImg !== false && $waterImg !== false) {
			$groundImg = $this->copyImage($groundImg, $waterImg, $pos, $waterInfo);  //拷贝图像
		} else {
			return false;
		}
			
			return $this->createNewImage($groundImg, $prefix.$groundName, $groundInfo);
			
		}else{
			\Candy\Core\Debug::addMsg('<font color=\'red\'>图片或水印图片不存在！</font>');
			return false;
		}
	}
	
	/**
	 * 裁剪图片
	 * 
	 * 从原图中裁剪指定区域的图片，生成新的裁剪后图片文件。可以通过指定起始坐标
	 * 和裁剪尺寸来精确裁剪原图的特定区域。
	 * 
	 * @param string $name 原始图像文件名，相对于$path路径
	 * @param int $x 裁剪起始点的x坐标（左上角为原点）
	 * @param int $y 裁剪起始点的y坐标（左上角为原点）
	 * @param int $width 裁剪区域的宽度
	 * @param int $height 裁剪区域的高度
	 * @param string $prefix 裁剪后图片文件名前缀，默认为'cu_'
	 * @return string 生成的裁剪后图片文件名，包含前缀
	 * @throws Exception 当图像文件不存在、裁剪区域超出原图范围或格式不支持时抛出异常
	 */
	public function cut($name, int $x, int $y, int $width, int $height, string $prefix = 'cu_'): mixed
	{
		$path = '.';
		if(is_array($name))
			[$name, $path] = $name;
		
		$imgInfo = $this->getInfo($name, $path);//获取图片信息
		
		if( (($x+$width) > $imgInfo['width']) || (($y+$height) > $imgInfo['height'])){
			\Candy\Core\Debug::addMsg('<font color=\'red\'>裁剪的位置超出了图片范围！</font>');
			return false;
		}
		
		$back = $this->getImg($name, $imgInfo);                          //获取图片资源      

		if ($back !== false) {
			/* 创建一个可以保存裁剪后图片的资源 */
			$cutimg = imagecreatetruecolor($width, $height);

			/* 使用imagecopyresampled()函数对图片进行裁剪 */
			imagecopyresampled($cutimg, $back, 0, 0, $x, $y, $width, $height, $width, $height);
		} else {
			return false;
		}
		
		imagedestroy($back);
	
		return $this->createNewImage($cutimg, $prefix.$name, $imgInfo);
	}
	
	/**
	 * 计算水印位置坐标
	 * 
	 * 根据指定的水印位置类型，计算水印在原图上的精确坐标。
	 * 支持9种固定位置和随机位置模式（0为随机位置）。
	 * 
	 * @param array $groundInfo 背景图片信息数组，包含width和height键值
	 * @param array $waterInfo 水印图片信息数组，包含width和height键值
	 * @param int $waterPos 水印位置类型（0-9）
	 * @return array|bool 返回水印的位置坐标数组（包含posX和posY键值），如果水印尺寸大于背景图则返回false
	 */
	private function position(array $groundInfo,array $waterInfo,int $waterPos): array|bool
	{
		//需要加水印的图片的长度或宽度比水印还小，无法生成水印！
		if( ($groundInfo['width']<$waterInfo['width']) || ($groundInfo['height']<$waterInfo['height']) ){ 
			return false; 
		} 
		return match($waterPos){ 
			1 => ['posX'=>0, 'posY'=>0], //1为顶端居左 
			2 => ['posX'=>($groundInfo['width'] - $waterInfo['width']) / 2, 'posY'=>0], //2为顶端居中 
			3 => ['posX'=>$groundInfo['width'] - $waterInfo['width'], 'posY'=>0], //3为顶端居右 
			4 => ['posX'=>0, 'posY'=>($groundInfo['height'] - $waterInfo['height']) / 2], //4为中部居左 
			5 => ['posX'=>($groundInfo['width'] - $waterInfo['width']) / 2, 'posY'=>($groundInfo['height'] - $waterInfo['height']) / 2], //5为中部居中 
			6 => ['posX'=>$groundInfo['width'] - $waterInfo['width'], 'posY'=>($groundInfo['height'] - $waterInfo['height']) / 2], //6为中部居右 
			7 => ['posX'=>0, 'posY'=>$groundInfo['height'] - $waterInfo['height']], //7为底端居左 
			8 => ['posX'=>($groundInfo['width'] - $waterInfo['width']) / 2, 'posY'=>$groundInfo['height'] - $waterInfo['height']], //8为底端居中 
			9 => ['posX'=>$groundInfo['width'] - $waterInfo['width'], 'posY'=>$groundInfo['height'] - $waterInfo['height']], //9为底端居右 
			0 => ['posX'=>rand(0,($groundInfo['width'] - $waterInfo['width'])), 'posY'=>rand(0,($groundInfo['height'] - $waterInfo['height']))], //随机 
			default => ['posX'=>rand(0,($groundInfo['width'] - $waterInfo['width'])), 'posY'=>rand(0,($groundInfo['height'] - $waterInfo['height']))], //随机 
		};
	}
	
	/**
	 * 获取图像信息
	 * 
	 * 获取指定图像文件的基本信息，包括宽度、高度、类型等。
	 * 此方法内部使用getimagesize()函数获取图像的基本属性，
	 * 并将信息组织成数组形式返回。
	 * 
	 * @param string $image 图像文件名，相对于$path路径
	 * @return array 包含图像信息的数组，键值包括：
	 *               - width: 图像宽度
	 *               - height: 图像高度
	 *               - type: 图像类型（1=GIF, 2=JPG, 3=PNG）
	 *               - mime: 图像MIME类型
	 * @throws Exception 当图像文件不存在或无法获取信息时抛出异常
	 */
	private function getInfo(string $name,string $path = '.'): array
	{
		$spath = $this->path . ($path == '.' ? '' : '/' . $this->safeRelPath($path)) . '/';

		$data = getimagesize($spath . $name);
		$imgInfo['width'] = $data[0];
		$imgInfo['height'] = $data[1];
		$imgInfo['type'] = $data[2];
		$imgInfo['mime'] = $data['mime'] ?? '';

		return $imgInfo;
	}
	
	/**
	 * 打开图像资源
	 * 
	 * 根据图像类型创建相应的GD图像资源。支持GIF、JPEG和PNG三种常见格式，
	 * 内部根据不同的图像类型调用相应的GD库函数。
	 * 
	 * @param string $name 图像文件名，相对于$path路径
	 * @param array $imgInfo 图像信息数组，包含width、height、type等键值
	 * @param string $path 可选，图像所在子路径，默认为当前路径
	 * @return \GdImage|false 返回创建的GD图像资源或false
	 * @throws Exception 当图像格式不支持或无法打开图像时抛出异常
	 */
	private function getImg(string $name,array $imgInfo,string $path = '.'): \GdImage|false
	{
		
		$spath = $this->path . ($path == '.' ? '' : '/' . $this->safeRelPath($path)) . '/';
		$srcPic = $spath.$name;
		
		$img = match ($imgInfo['type']){
			1 => imagecreatefromgif($srcPic), //gif
			2 => imagecreatefromjpeg($srcPic), //jpg
			3 => imagecreatefrompng($srcPic), //png
			default => false,
		};
		return $img !== false ? $img : false;
	}
	
	/**
	 * 计算缩略图尺寸
	 * 
	 * 根据原图尺寸和目标尺寸，计算保持比例的缩略图尺寸。
	 * 此方法确保生成的缩略图不会变形，同时能够适应指定的最大宽度和高度。
	 * 
	 * @param string $name 文件名
	 * @param int $width 目标宽度
	 * @param int $height 目标高度
	 * @param array $imgInfo 原始图像信息数组
	 * @return array 返回计算后的缩略图尺寸数组，包含width和height键值
	 */
	private function getNewSize(string $name,int $width,int $height,array $imgInfo): array
	{	
		$size['width'] = $imgInfo['width'];          //将原图片的宽度给数组中的$size['width']
		$size['height'] = $imgInfo['height'];        //将原图片的高度给数组中的$size['height']
		if($width < $imgInfo['width']){
			$size['width'] = $width;             //缩放的宽度如果比原图小才重新设置宽度
		}
		if($height < $imgInfo['height']){
			$size['height'] = $height;            //缩放的高度如果比原图小才重新设置高度
		}
		if($imgInfo['width']*$size['width'] > $imgInfo['height'] * $size['height']){
			$size['height'] = round($imgInfo['height']*$size['width']/$imgInfo['width']);
		}else{
			$size['width'] = round($imgInfo['width']*$size['height']/$imgInfo['height']);
		}
		return $size;
	}

	/**
	 * 创建新图像资源
	 * 
	 * 根据指定的图像资源、文件名和图像信息保存图像到文件系统。
	 * 支持根据原始图像类型（GIF、JPEG、PNG）选择适当的输出格式，
	 * 并在保存完成后销毁临时图像资源。
	 * 
	 * @param \GdImage $newImg 要保存的GD图像资源
	 * @param string $newName 新图像的文件名
	 * @param array $imgInfo 图像信息数组，包含type等键值
	 * @param string $path 可选，指定保存路径，默认为类的基础路径
	 * @return string 返回保存的新图像文件名
	 */
	private function createNewImage(\GdImage $newImg, string $newName, array $imgInfo, string $path = ''): string
	{
		$this->path = empty($path) ? $this->path : $path;
		match ($imgInfo['type']){
	   		1 => imageGIF($newImg, $this->path.$newName), //gif
			2 => imageJPEG($newImg,$this->path.$newName), //jpg
			3 => imagePng($newImg, $this->path.$newName), //png
			default => null,
		};
		imagedestroy($newImg);
		return $newName;
	}
	
	/**
	 * 复制图像内容
	 * 
	 * 将一个图像资源的内容复制到另一个图像资源中，支持缩放操作。
	 * 此方法在生成缩略图和水印操作中被调用，用于将源图像内容写入到目标图像。
	 * 
	 * @param \GdImage $groundImg 目标图像资源
	 * @param \GdImage $waterImg 源图像资源
	 * @param array $pos 位置信息数组，包含posX和posY键值
	 * @param array $waterInfo 源图像信息数组，包含width和height键值
	 * @return \GdImage 返回合并后的目标图像资源
	 */
	private function copyImage(\GdImage $groundImg, \GdImage $waterImg, array $pos, array $waterInfo): \GdImage
	{
		imagecopy($groundImg, $waterImg, $pos['posX'], $pos['posY'], 0, 0, $waterInfo['width'],$waterInfo['height']);
		imagedestroy($waterImg);
		return $groundImg;
	}
	
	/**
	 * 生成调整大小后的图像资源
	 * 
	 * 根据指定的尺寸创建新的图像资源，并将源图像内容复制到新图像中。
	 * 该方法还处理透明色的保留，确保在处理GIF和PNG等支持透明色的图像时
	 * 能够正确保留原始图像的透明效果。
	 * 
	 * @param \GdImage $srcImg 源图像资源
	 * @param array $size 新图像的尺寸数组，包含width和height键值
	 * @param array $imgInfo 图像信息数组，包含width、height、type等键值
	 * @return \GdImage 返回调整大小后的新图像资源
	 */
	private function kidOfImage(\GdImage $srcImg, array $size, array $imgInfo): \GdImage
	{
		$newImg = imagecreatetruecolor($size['width'], $size['height']);		
		$otsc = imagecolortransparent($srcImg); //将某个颜色定义为透明色
		if( $otsc >= 0 && $otsc < imagecolorstotal($srcImg)){  //取得一幅图像的调色板中颜色的数目
	  		$transparentcolor = imagecolorsforindex( $srcImg, $otsc ); //取得某索引的颜色
	 		$newtransparentcolor = imagecolorallocate(
		   		$newImg,
		  		$transparentcolor['red'],
		   	    $transparentcolor['green'],
		   		$transparentcolor['blue']
	  		);

	  		imagefill( $newImg, 0, 0, $newtransparentcolor );
	  		imagecolortransparent( $newImg, $newtransparentcolor );
		}
		imagecopyresized($newImg, $srcImg, 0, 0, 0, 0, $size['width'], $size['height'], $imgInfo['width'], $imgInfo['height'] );
		imagedestroy($srcImg);
		return $newImg;
	}
}
