<?php
/**
 * Candy Zip处理类
 * 
 * 提供ZIP文件创建、文件压缩和流式打包下载功能的工具类
 * 支持多文件压缩、流式输出下载、中文文件名处理等特性
 * 
 * @package    Extend
 * @author     刘森 (fingerboy@qq.com)
 * @date      2022-08-02 14:28:25
 */
 
declare(strict_types=1);
namespace Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Zip{
    /**
     * 流式状态标识，用于判断是否直接输出到浏览器
     * 
     * @var bool
     */
    private $doWrite = false;
    
    /**
     * 存储压缩文件数据的数组
     * 
     * @var array
     */
    private $datasec = [];
    
    /**
     * 中央目录信息，存储文件元数据
     * 
     * @var array
     */
    private $ctrlDir = [];
    
    /**
     * 中央目录结束记录标志
     * 
     * @var string
     */
    private $eofCtrlDir = "\x50\x4b\x05\x06\x00\x00\x00\x00";
    
    /**
     * 记录文件指针位置，用于计算偏移量
     * 
     * @var int
     */
    private $oldOffset = 0;
    
    /**
     * ZIP文件的注释信息
     * 
     * @var string
     */
    private $comments = '';
	
    /**
	 * 构造函数
	 * 
	 * 初始化Zip类并根据选项配置ZIP文件处理
	 * 支持设置文件名、开启流式下载、添加文件列表和设置注释
	 * 
	 * @param  array  $options 配置选项数组
	 * @param  string $options['filename'] 下载文件名，默认为当前时间戳
	 * @param  bool   $options['doWrite'] 是否开启流式压缩，默认为false
	 * @param  array  $options['filelist'] 文件列表数组，每项包含type和pathname字段
	 * @param  string $options['comments'] ZIP文件注释信息
	 * @return void
	 */
	public function __construct(array $options = [])
    {
        if (!empty($options)) {
            header("Content-Disposition: attachment; filename=" . ($options['filename'] ?? date('YmdHis')) . ".zip");
            
            //开启流式压缩
            if (isset($options['doWrite']) && $options['doWrite'] === true) {
                $this->setDoWrite();
            }
            
            //添加压缩文件
            if (!empty($options['filelist'])) {
                foreach ($options['filelist'] as $file) {
                    if ($file['type'] == 'file' && file_exists($file['pathname'])) {
                        $pathname = iconv('utf-8', 'gbk', $file['pathname']);
                        $this->addFile(file_get_contents($pathname), $pathname);
                        ob_flush();
                        flush();
                    }
                }
            }
            
            //设置注释
            if (!empty($options['comments'])) {
                $this->setComments(iconv('utf-8', 'gbk', $options['comments']));
            }
            
            //输出文件尾部
            $this->file();
        }
    }
	
    /**
	 * 开启流式打包模式
	 * 
	 * 设置doWrite标志为true，启用直接输出到浏览器的流式下载模式
	 * 
	 * @return void
	 */
	private function setDoWrite()
    {
        $this->doWrite = true;
    }
	
    /**
	 * 设置ZIP文件注释信息
	 * 
	 * @param  string $comments ZIP文件的注释内容
	 * @return void
	 */
	private function setComments(string $comments)
    {
        $this->comments = $comments;
    }
 
    /**
	 * Unix时间戳转换为DOS时间格式
	 * 
	 * 将Unix时间戳转换为ZIP文件规范中使用的DOS时间格式
	 * 
	 * @param  int $unixtime Unix时间戳，默认为0（当前时间）
	 * @return int          DOS格式的时间戳
	 */
	private function unixToDosTime(int $unixtime = 0): int
    {
        $timearray = ($unixtime == 0) ? getdate() : getdate($unixtime);
 
        if ($timearray['year'] < 1980) {
            $timearray = [
                'year' => 1980,
                'mon' => 1,
                'mday' => 1,
                'hours' => 0,
                'minutes' => 0,
                'seconds' => 0
            ];
        }
 
        return (($timearray['year'] - 1980) << 25)
            | ($timearray['mon'] << 21)
            | ($timearray['mday'] << 16)
            | ($timearray['hours'] << 11)
            | ($timearray['minutes'] << 5)
            | ($timearray['seconds'] >> 1);
    }
	
    /**
	 * 向ZIP包中添加文件
	 * 
	 * 将文件数据添加到ZIP包中，支持自定义文件名和修改时间
	 * 
	 * @param  string $data 文件内容数据
	 * @param  string $name 文件在ZIP中的路径和名称
	 * @param  int    $time 文件修改时间（Unix时间戳），默认为0（当前时间）
	 * @return void
	 */
	private function addFile(string $data, string $name, int $time = 0): void
    {
        $name = str_replace('\\', '/', $name);
        if(str_starts_with($name, C('CANDYROOT'))){
            $name = substr($name, strlen(C('CANDYROOT')));
        }
        $hexdtime = pack('V', $this->unixToDosTime($time));
 
        $fr = "\x50\x4b\x03\x04";
        $fr .= "\x14\x00";
        $fr .= "\x00\x00";
        $fr .= "\x08\x00";
        $fr .= $hexdtime;
		
        $unc_len = strlen($data);
        $crc = crc32($data);
        $zdata = gzcompress($data);
        $zdata = substr($zdata, 2, -4);
        $c_len = strlen($zdata);
        $fr .= pack('V', $crc);
        $fr .= pack('V', $c_len);
        $fr .= pack('V', $unc_len);
        $fr .= pack('v', strlen($name));
        $fr .= pack('v', 0);
        $fr .= $name;
		
        $fr .= $zdata;
		
        if ($this->doWrite) {
            echo $fr;
        } else {
            $this->datasec[] = $fr;
        }
		
        $cdrec = "\x50\x4b\x01\x02";
        $cdrec .= "\x00\x00";
        $cdrec .= "\x14\x00";
        $cdrec .= "\x00\x00";
        $cdrec .= "\x08\x00";
        $cdrec .= $hexdtime; 
        $cdrec .= pack('V', $crc);
        $cdrec .= pack('V', $c_len);
        $cdrec .= pack('V', $unc_len);
        $cdrec .= pack('v', strlen($name));
        $cdrec .= pack('v', 0);
        $cdrec .= pack('v', 0);
        $cdrec .= pack('v', 0);
        $cdrec .= pack('v', 0);
        $cdrec .= pack('V', 32);
        $cdrec .= pack('V', $this->oldOffset);
        $this->oldOffset += strlen($fr);
        $cdrec .= $name;
        $this->ctrlDir[] = $cdrec;
    }
 
    /**
	 * 生成ZIP文件包或数据流
	 * 
	 * 生成完整的ZIP文件数据，根据模式决定是直接输出到浏览器还是返回数据
	 * 
	 * @return string 当doWrite为false时返回完整的ZIP文件数据，否则返回空字符串
	 */
	private function file(): string
    {
        $ctrldir = implode('', $this->ctrlDir);
        $header = $ctrldir .
            $this->eofCtrlDir .
            pack('v', sizeof($this->ctrlDir)) .
            pack('v', sizeof($this->ctrlDir)) .
            pack('V', strlen($ctrldir)) . 
            pack('V', $this->oldOffset) .
            pack('v', strlen($this->comments)) .
            $this->comments;
 
        if ($this->doWrite) {
            echo $header;
            return "";
        } else {
            $data = implode('', $this->datasec);
            return $data . $header;
        }
    }
}
