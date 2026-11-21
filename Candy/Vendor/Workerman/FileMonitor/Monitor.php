<?php
namespace FileMonitor;

use \Workerman\{Worker,Timer};

/**
 * 文件更新自动reload类
 */
class Monitor extends Worker
{
    //待监听的项目目录
    private $_monitor_dir;

    //热更新间隔时间,默认3s
    private $_interval;

    //最后一次同步时间
    private $_last_time = 0;
	
    //inotify文件描述符
    private $inotifyFd;

    //监控的文件
    private $_monitor_files = [];
    
	//最近reload的文件，用于去重
    private $_recent_reload_files = [];

	//对象
	private static $instance = null;

	//启动方式
	private $runType;

	/**
     * 设置文件驱动名称
	 *
     * @param string $name 驱动名称
     * @return static
     */
    public static function instance(int $interval = 3): self
    {
		if(self::$instance === null){
			self::$instance = new self(['interval' => $interval]);
		}

		return self::$instance;
    }

	/**
	 * 添加目录监控目录
	 */
	public function addMonitorDir ( array $dirs): void
	{
		foreach($dirs as $dir){
			if(!is_dir($dir) || isset($this->_monitor_dir[$dir]))	continue;
			//监控权柄更新
			if(isset($this->_monitor_dir[$dir]) === false){
				if($this->runType == 'inotify'){
					//获取文件夹及子文件夹内的所有PHP文件
					$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
					if(empty($iterator)) continue;
					foreach($iterator as $file){
						if($file->getExtension() != 'php') continue;
						// 把文件加入inotify监控：修改 + 删除/重命名都触发（否则文件被删/改名后不生效）
							$wd = inotify_add_watch($this->inotifyFd, $file->getPathname(), \IN_MODIFY | \IN_DELETE | \IN_MOVE);
							if($wd === false) continue;
							$this->_monitor_files[$wd] = $file;
					}
					//还要监控文件夹的文件的操作
					$wd = inotify_add_watch($this->inotifyFd, $dir, \IN_MODIFY | \IN_CREATE | \IN_DELETE | \IN_MOVE);
					if($wd === false) continue;
					$this->_monitor_files[$wd] = $dir;
				}
				
				//记录监控目录
				$this->_monitor_dir[$dir] = 1;
			}
		}
	}
	
    function __construct (array $config = [])
    {
		parent::__construct();
		//服务名称
		$this->name = 'FileMonitor';
		//自身reloade;
		$this->reloadable = false;
		//配置参数
		$this->_interval = $config['interval'] ?? 3;
		$this->_monitor_dir = $config['monitor_dir'] ?? [];
		$this->_last_time = time();
		$this->onWorkerStart = [$this, 'onWorkerStart'];

		//启动方式
		if(extension_loaded('inotify')){
			$this->runType = 'inotify';
			// 初始化inotify句柄
			$this->inotifyFd = inotify_init();
		} else {
			$this->runType = 'componentless';
		}
    }

	//监听器，worker启动时调用
    public function onWorkerStart (): void
    {
		match($this->runType){
			'componentless' => Timer::add($this->_interval, [$this, 'monitor']),
			'inotify' => $this->inotifyMonitor(),
			default => null
		};
    }

    //监听器，kill进程
    public function monitor (): void
    {
		$changedFiles = [];
		foreach($this->_monitor_dir as $path=>$status){
			$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
			if(empty($iterator)) continue;
			foreach ($iterator as $file)
			{
				if (pathinfo($file->getPathname(), PATHINFO_EXTENSION) == 'php' && $this->_last_time < $file->getMTime())
				{
					$changedFiles[] = $file->getPathname();
				}
			}
		}

		if ($changedFiles) {
			// 2 秒内同一文件去重，避免一批文件同时修改时连续触发多次 reload
			$now = time();
			$shouldReload = false;
			foreach ($changedFiles as $filePath) {
				if (isset($this->_recent_reload_files[$filePath]) && $now - $this->_recent_reload_files[$filePath] < 2) {
					continue;
				}
				$this->_recent_reload_files[$filePath] = $now;
				$shouldReload = true;
			}
			if ($shouldReload) {
				echo implode(', ', $changedFiles) ." update and reload. (Componentless Mode)\n";
				// 给父进程也就是主进程发送reload信号
				posix_kill(posix_getppid(), SIGUSR1);
				$this->_last_time = time();
			}
		}
    }

	private function inotifyMonitor (): void
	{
		// 设置为非阻塞
		stream_set_blocking($this->inotifyFd, false);
		Worker::$globalEvent->onReadable($this->inotifyFd, function($inotify_fd){
			// 监听事件
			$events = inotify_read($inotify_fd);
			if($events){
				// 处理事件
				foreach($events as $event){
					// 更新的文件
					$file = $this->_monitor_files[$event['wd']] ?? null;
					if ($file) {
						$filePath = is_string($file) ? $file : $file->getPathname();
						
						// 去重：避免短时间内同一文件多次触发
						if (isset($this->_recent_reload_files[$filePath]) && time() - $this->_recent_reload_files[$filePath] < 2) continue;
						
						echo $filePath ." update and reload\n";
						// 记录最近reload的文件
						$this->_recent_reload_files[$filePath] = time();

						// 给父进程也就是主进程发送reload信号
						posix_kill(posix_getppid(), SIGUSR1);

						// 移除旧的监控
						inotify_rm_watch($this->inotifyFd, $event['wd']);
						unset($this->_monitor_files[$event['wd']]);

						// 目录级事件：新建的 PHP 文件补加入监控（否则新文件后续修改不会被跟踪）
						if (($event['mask'] & \IN_CREATE) && is_string($file) && !empty($event['name'])) {
							$newPath = rtrim($filePath, '/') . '/' . $event['name'];
							if (pathinfo($newPath, PATHINFO_EXTENSION) === 'php' && is_file($newPath)) {
								$wdNew = inotify_add_watch($this->inotifyFd, $newPath, \IN_MODIFY | \IN_DELETE | \IN_MOVE);
								if ($wdNew !== false) {
									$this->_monitor_files[$wdNew] = $newPath;
								}
							}
						}

						// 需要把文件重新加入监控（文件已被删除/重命名时 add_watch 会失败，跳过即可）
						$wd = inotify_add_watch($this->inotifyFd, $filePath, \IN_MODIFY | \IN_DELETE | \IN_MOVE);
						if($wd === false) continue;
						$this->_monitor_files[$wd] = $file;
					}
				}
			}
		});
	}
}
