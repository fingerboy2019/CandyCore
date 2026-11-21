<?php
/**
 * Candy框架分页处理类
 * 
 * 用于生成各种形式的分页HTML代码，支持自定义样式、Ajax分页、自动URL生成等功能
 * 提供灵活的分页配置选项，可自定义每页显示数量、分页样式、URL格式等
 * 
 * @package Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-12-08 13:49:18
 */
 
declare(strict_types=1);
namespace Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Page {
    /**
     * 分页样式配置数组
     * 
     * 包含各种HTML元素的模板，如div、total、nowpage、page、pre、next等
     * @var array
     */
	public $config;
	
    /**
     * CSS文件路径
     * @var string
     */
	private $style;
	
    /**
     * 分页连接网址
     * 
     * 支持{page}占位符，用于生成不同页码的URL
     * @var string
     */
	public $url;
	
    /**
     * 当前页码
     * @var int|string
     */
	public $page;
	
    /**
     * 总记录数
     * @var int
     */
	public int $total;
	
    /**
     * 分页总数
     * @var int
     */
	public $totalPages;
	
    /**
     * 每页显示的记录数
     * @var int
     */
	public $num;
	
    /**
     * 页码列表显示的最大数量
     * 
     * 如：1.2.3.4就这4个数字，则$perCircle为4
     * @var int
     */
	public $perCircle;
	
    /**
     * 分页程序的扩展功能开关
     * @var bool
     */
	public $ext;
	
    /**
     * 当前页在页码列表中的位置索引
     * 
     * 如：7,8,9,10,11中，9为当前页，在list中排第三位，则$center为3
     * @var int
     */
	public $center;
	
    /**
     * 首页按钮文本
     * @var string
     */
	public $firstPage;
	
    /**
     * 上一页按钮文本
     * @var string
     */
	public $prePage;
	
    /**
     * 下一页按钮文本
     * @var string
     */
	public $nextPage;
	
    /**
     * 末页按钮文本
     * @var string
     */
	public $lastPage;
	
    /**
     * 分页附属说明文本模板
     * 
     * 支持{total_num}、{total_page}、{num}等占位符
     * @var string
     */
	public $note;
	
    /**
     * 是否为ajax分页模式
     * @var bool
     */
	public $isAjax;
	
    /**
     * ajax分页的动作名称
     * @var string
     */
	public $ajaxActionName;
	
    /**
     * 分页css文件名
     * @var string
     */
	public $styleFile;
	
    /**
     * 分页隐藏开关
     * @var bool
     */
	public $hiddenStatus;
	
	
    /**
     * 构造函数
     * 
     * 初始化分页组件的默认配置
     * 
     * @return bool
     */
	public function __construct()
	{
		// 初始化默认配置
		$this->ext 			 = true;      // 默认开启扩展功能
		$this->center 		 = 4;         // 默认居中位置
		$this->num 			 = 7;         // 默认每页显示7条记录
		$this->perCircle	 = 7;         // 默认显示7个页码
		$this->isAjax		 = false;     // 默认非Ajax模式
		$this->hiddenStatus = false;     // 默认不隐藏
		return true;
	}
	
    /**
     * 设置分页样式配置
     *
     * @param array $config 分页样式配置数组
     *        - div: HTML容器结构，默认 '<div id="pages">{content}</div>'
     *        - total: 总数显示格式，默认 '<a>{content}</a>'
     *        - nowpage: 当前页样式，默认 '<span>{content}</span>'
     *        - page: 普通页码样式，默认 '<a href="{url}">{content}</a>'
     *        - pre: 上一页样式，默认 '<a href="{url}">{content}</a>'
     *        - next: 下一页样式，默认 '<a href="{url}">{content}</a>'
     *        - note: 分页说明样式，默认 '<a>{content}</a>'
     * @return void
     */
	public function loadConfig(array $config = []): void
	{
	    if (empty($config)) {
	        $config = [
				'div'     => '<div id="pages">{content}</div>',
				'total'   => '<a>{content}</a>',
				'nowpage' => '<span>{content}</span> ',
				'page'    => '<a href="{url}">{content}</a>',
				'pre'     => '<a href="{url}">{content}</a>',
				'next'    => '<a href="{url}">{content}</a>',
				'note'    => '<a>{content}</a>'
			];
	    }
	    $this->firstPage	= $this->firstPage ? $this->firstPage : '首页';
		$this->prePage 		= $this->prePage ? $this->prePage : '上一页';
		$this->nextPage		= $this->nextPage ? $this->nextPage : '下一页';
		$this->lastPage		= $this->lastPage ? $this->lastPage : '末页';
		$this->note			= empty($config['note']) ? '共{total_num}条. {total_page}页 {num}条/页' : $config['note'];
	    $this->config		= $config;
	}
	
    /**
     * 格式化内容，替换占位符
     * 
     * 替换HTML模板中的{content}和{url}占位符
     * 
     * @param string $content 要替换{content}占位符的内容
     * @param string $url 要替换{url}占位符的内容，默认为空字符串
     * @param string $config 包含占位符的HTML模板
     * @return string 替换后的HTML内容
     */
	private function pregContent($content, $url='', $config = ''): string
	{
	    return str_replace('{content}', $content, str_replace('{url}', $url, $config));
	}
	
    /**
     * 计算总页数
     * 
     * 根据总记录数和每页显示数计算总页数
     * 
     * @return int|bool 计算得到的总页数，参数无效时返回false
     */
	private function getTotalPage(): int|bool
	{
		if (!$this->total || !$this->num) return false;
		return (int)ceil($this->total / $this->num);
	}
	
    /**
     * 计算当前有效页码
     * 
     * 确保当前页码在有效范围内（不超过总页数）
     * 
     * @return int 有效页码，默认为1
     */
	private function getPageNum(): int
	{
		$page = (!$this->page) ? 1 : (int)$this->page;
		//当URL中?page=5的page参数大于总页数时
		return ($page > $this->totalPages) ? (int)$this->totalPages : $page;
	}
	
	/**
	 * 设置每页显示的记录数
	 * 
	 * @param int|null $num 每页显示的记录数，默认为10
	 * @return Page 返回当前对象实例，支持链式调用
	 */
	public function num($num = null): Page
	{ 
		//参数分析
		if (is_null($num)) $num = 10;
		$this->num = (int)$num;
		return $this;
	}

	/**
	 * 设置总记录数
	 * 
	 * @param int|null $total_post 总记录数
	 * @return Page 返回当前对象实例，支持链式调用
	 */
	public function total(?int $totalCount = null): Page
	{
		$this->total = (!is_null($totalCount)) ? (int)$totalCount : 0;
		return $this;
	}
	
	/**
	 * 设置分页隐藏状态
	 * 
	 * @param bool $item 隐藏开关，默认为true
	 * @return Page 返回当前对象实例，支持链式调用
	 */
	public function hide(bool $item = true): Page
	{
		if ($item === true) $this->hiddenStatus = true;
		return $this;
	}
	
	/**
     * 将URL模板中的{page}占位符替换为实际页码
     * 
     * @param string $url URL模板，包含{page}占位符
     * @param int $page 实际页码
     * @return string 生成的完整URL
     */
	protected function setUrl(string $url,int $page): string
	{
	    return str_replace('{page}', (string)$page, $url);
	}

	/**
     * 设置分页URL模板
     * 
     * 若未提供URL，则自动从当前页面URL生成
     * 支持{page}占位符，用于动态生成不同页码的链接
     * 
     * @param string|null $url URL模板，包含{page}占位符
     * @return Page|bool 设置成功返回当前对象，失败返回false
     */
	public function url($url = null): Page|bool
	{
		//当url为空时,自动获取url参数. 注:默认当前页的参数为page
		if (is_null($url)) {
			//当网址没有参数时
			$url = (!$_SERVER['QUERY_STRING']) ? $_SERVER['REQUEST_URI'] . ((substr($_SERVER['REQUEST_URI'], -1) == '?') ? 'page={page}' : '?page={page}') : '';
			//当网址有参数时,且有分页参数(page)时
			if (!$url && (stristr($_SERVER['QUERY_STRING'], 'page='))) {	
				$url = str_ireplace('page=' . $this->page, '', $_SERVER['REQUEST_URI']);	
				$end_str = substr($url, -1);			
				if ($end_str == '?' || $end_str == '&') {
					$url .= 'page={page}';
				} else {
					$url .= '&page={page}';
				}
			}
			//当网址中未发现含有分页参数(page)时
			if (!$url) $url = $_SERVER['REQUEST_URI'] . '&page={page}';
		}
		//自动获取都没获取到url...额..没有办法啦, 趁早返回false
		if (!$url) return false;
		$this->url = empty($this->config['before']) ? trim($url) : $this->config['before'] . trim($url);
		$this->url = empty($this->config['after']) ? trim($this->url) : trim($this->url) . $this->config['after'];
		return $this;
	}

	/**
     * 设置当前页码
     * 
     * 若未提供页码，则尝试从配置或GET参数获取
     * 
     * @param int|null $page 当前页码
     * @return Page|bool 设置成功返回当前对象，失败返回false
     */
	public function page($page = null): Page|bool
	{
		//当参数为空时.自动获取GET['page']
		if (is_null($page)) {
			$page = C('page') ?: (I('get.page') ?: 1);
		}
		if(!$page) return false;
		$this->page = $page;
		return $this;
	}

	/**
     * 设置扩展功能开关
     * 
     * @param bool $ext 是否开启扩展功能，默认为true
     * @return Page 返回当前对象实例，支持链式调用
     */
	public function ext(bool $ext = true): Page
	{
		//将$ext转化为小写字母.
		$this->ext = ($ext) ? true : false;
		return $this;
	}

	/**
     * 设置当前页在页码列表中的位置索引
     * 
     * @param int $num 中心位置索引
     * @return Page|bool 设置成功返回当前对象，失败返回false
     */
	public function center($num): Page|bool
	{
		if (!$num) return false;
		$this->center = (int)$num;
		return $this;
	}

    /**
     * 设置页码列表显示的最大数量
     * 
     * @param int $num 页码数量
     * @return Page|bool 设置成功返回当前对象，失败返回false
     */
	public function circle($num): Page|bool
	{
		if (!$num) return false;
		$this->perCircle = (int)$num;
		return $this;
	}
	
    /**
     * 生成首页和上一页的HTML代码
     * 
     * @return string|bool 首页和上一页的HTML代码，无需显示时返回false
     */
	private function getFirstPage(): string|bool
	{
		if ($this->page == 1 || $this->totalPages <= 1) return false;
		$string = $this->pregContent($this->firstPage, $this->setUrl($this->url, 1), $this->config['first']) . $this->pregContent($this->prePage, $this->setUrl($this->url, $this->page - 1), $this->config['pre']);
		return $string;
	}
	
    /**
     * 生成下一页和末页的HTML代码
     * 
     * @return string|bool 下一页和末页的HTML代码，无需显示时返回false
     */
	private function getLastPage(): string|bool
	{
		if ($this->page == $this->totalPages || $this->totalPages <= 1) return false;
		$string = $this->pregContent($this->nextPage, $this->setUrl($this->url, $this->page + 1), $this->config['next']) . $this->pregContent($this->lastPage, $this->setUrl($this->url, $this->totalPages), $this->config['last']);
		return $string;
	}
	
    /**
     * 生成分页说明文本的HTML代码
     * 
     * 替换说明文本模板中的{total_num}、{total_page}、{num}等占位符
     * 
     * @return string|bool 分页说明的HTML代码，未开启扩展功能或无说明时返回false
     */
	private function getNote(): string|bool
	{
		if (!$this->ext || !$this->note) return false;
		return str_replace(['{total_num}', '{total_page}', '{num}','{page}','{url}'], [$this->total, $this->totalPages, $this->num, $this->page, $this->setUrl($this->url, $this->page)], $this->pregContent($this->note, $this->setUrl($this->url, $this->page), $this->note));
	}
	
    /**
     * 生成分页页码列表的HTML代码
     * 
     * 根据当前页码、总页数和配置的中心位置计算需要显示的页码范围
     * 
     * @return string|bool 页码列表的HTML代码，参数无效时返回false
     */
	private function getList(): string|bool
	{
		if (empty($this->totalPages) || empty($this->page)) return false;
		if ($this->totalPages > $this->perCircle) {				
			if ($this->page + $this->perCircle >= $this->totalPages + $this->center) {					
				$list_start  = $this->totalPages - $this->perCircle + 1;
				$list_end  	= $this->totalPages;
			} else {		 			
				$list_start  = ($this->page>$this->center) ? $this->page - $this->center + 1 : 1;
				$list_end 	 = ($this->page>$this->center) ? $this->page + $this->perCircle-$this->center : $this->perCircle;
			}				
		} else {				
			$list_start 	 = 1;
			$list_end 	     = $this->totalPages;
		}
		$pagelist_queue      = '';
		for ($i=$list_start; $i<=$list_end; $i++) {
			$pagelist_queue .= ($this->page == $i) ? $this->pregContent($i, null, $this->config['nowpage']) : $this->pregContent($i, $this->setUrl($this->url, $i), $this->config['page']);
		}
		return $pagelist_queue;
	}
	
    /**
     * 生成并返回分页HTML代码
     * 
     * 根据当前配置自动计算分页参数，生成包含页码列表、首页、上一页、下一页、末页等元素的完整HTML代码
     * 当总记录数不足一页时，返回空字符串
     * 
     * @return string 完整的分页HTML代码或空字符串
     */
	public function output(): string
	{
		//支持长的url.
		empty($this->url) && $this->url();
		$this->url = trim(str_replace(["\n","\r"], '', $this->url));
		//获取总页数.
		$this->totalPages = $this->getTotalPage();
		//获取当前页.
		$this->page = $this->getPageNum();
		return ($this->total <= $this->num) ? '' : $this->pregContent($this->getNote() . $this->getFirstPage() . $this->getList() . $this->getLastPage() , '', $this->config['div']);
	}
	
    /**
     * 析构函数
     * 
     * 用于处理类实例销毁时的清理工作
     */
	public function __destruct() {}
}
