<?php
/**
 * FFmpeg 视频处理类库（安全稳定版）
 * 
 * 功能：获取视频信息、提取帧图、视频切分、视频合并
 * 要求：服务器需安装 FFmpeg，PHP 需启用 proc_open 等函数
 */
declare(strict_types=1);
namespace Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class FFmpeg {
    
    // FFmpeg 可执行文件路径
    private $ffmpegPath = 'ffmpeg';
    private $ffprobePath = 'ffprobe';
    
    // 临时文件目录
    private $tempDir = './temp/';
    
    // 输出目录
    private $outputDir = './output/';
    
    // 环境检查结果缓存
    private $envCheckResult = null;
    
    // 命令超时秒数（0为不限制）
    private $commandTimeout = 300;
    
    // 必需的 PHP 函数列表
    private $requiredFunctions = [
        'proc_open',
        'proc_close',
        'proc_get_status',
        'proc_terminate',
        'stream_select',
        'escapeshellcmd',
        'escapeshellarg',
    ];
    
    // 必需的 PHP 扩展
    private $requiredExtensions = [
        'json',
    ];
    
    // 推荐的 PHP 配置
    private $recommendedConfig = [
        'upload_max_filesize' => '100M',
        'post_max_size' => '100M',
        'max_execution_time' => '300',
        'memory_limit' => '256M',
    ];
    
    /**
     * 构造函数
     * @param string $ffmpegPath
     * @param string $ffprobePath
     * @param string $tempDir
     * @param string $outputDir
     * @param bool $autoCheck 是否自动检查环境
     * @throws \Exception
     */
    public function __construct(string $ffmpegPath = 'ffmpeg', string $ffprobePath = 'ffprobe', string $tempDir = './temp/', string $outputDir = './output/', bool $autoCheck = true) 
    {
        $this->ffmpegPath = $ffmpegPath;
        $this->ffprobePath = $ffprobePath;
        $this->tempDir = rtrim($tempDir, '/') . '/';
        $this->outputDir = rtrim($outputDir, '/') . '/';
        
        if ($autoCheck) {
            $this->performEnvironmentCheck();
        }
    }
    
    /**
     * 使用 proc_open 安全执行命令，分离 stdout/stderr，支持超时
     * 
     * @param string $command 完整的 shell 命令（参数已转义）
     * @param array $stdout 标准输出（按行）
     * @param array $stderr 标准错误（按行）
     * @param int|null $timeout 超时秒数（null 使用默认值）
     * @return int 退出码
     * @throws \Exception
     */
    private function safeExecute(string $command, &$stdout = [], &$stderr = [], int|null $timeout = null): int
    {
        $descriptor = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];
        
        $process = proc_open($command, $descriptor, $pipes);
        if (!is_resource($process)) {
            throw new \Exception('无法启动进程: ' . $command);
        }
        
        // 关闭 stdin
        fclose($pipes[0]);
        
        // 非阻塞读取
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        $stdoutContent = '';
        $stderrContent = '';
        $timeout = ($timeout === null) ? $this->commandTimeout : $timeout;
        $startTime = time();
        
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                // 读取剩余数据
                $stdoutContent .= stream_get_contents($pipes[1]);
                $stderrContent .= stream_get_contents($pipes[2]);
                break;
            }
            
            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new \Exception('命令执行超时（' . $timeout . '秒）');
            }
            
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 1) > 0) {
                foreach ($read as $pipe) {
                    if ($pipe === $pipes[1]) {
                        $stdoutContent .= stream_get_contents($pipes[1]);
                    }
                    if ($pipe === $pipes[2]) {
                        $stderrContent .= stream_get_contents($pipes[2]);
                    }
                }
            }
        }
        
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        
        $stdout = $stdoutContent !== '' ? explode("\n", rtrim($stdoutContent, "\n")) : [];
        $stderr = $stderrContent !== '' ? explode("\n", rtrim($stderrContent, "\n")) : [];
        
        return $exitCode;
    }
    
    /**
     * 执行命令并返回 stdout 数组（退出码非0则抛异常）
     * @param string $command
     * @return array
     * @throws \Exception
     */
    private function executeCommand(string $command): array
    {
        $stdout = [];
        $stderr = [];
        $exitCode = $this->safeExecute($command, $stdout, $stderr);
        
        if ($exitCode !== 0) {
            $errorMsg = "命令执行失败 (退出码: {$exitCode}): " . implode("\n", $stderr);
            throw new \Exception($errorMsg);
        }
        
        return $stdout;
    }
    
    /**
     * 执行命令并获取完整字符串输出（例如 ffprobe 的 JSON）
     * @param string $command
     * @return string
     * @throws \Exception
     */
    private function executeAndGetString(string $command): string
    {
        $lines = $this->executeCommand($command);
        return implode("\n", $lines);
    }
    
    /**
     * 检查命令是否可用（静默模式）
     * @param string $command
     * @return bool
     */
    private function checkCommand(string $command): bool
    {
        try {
            // 确保命令被适当转义（此处为内部调用，但仍做转义）
            $cmd = escapeshellcmd($command);
            $stdout = [];
            $stderr = [];
            $exitCode = $this->safeExecute($cmd, $stdout, $stderr, 10);
            return $exitCode === 0;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 设置命令超时时间（秒）
     * @param int $seconds
     */
    public function setTimeout(int $seconds): void
    {
        $this->commandTimeout = max(0, (int)$seconds);
    }
    
    /**
     * 执行环境检查
     * @return array 环境检查结果
     */
    public function performEnvironmentCheck(): array
    {
        if ($this->envCheckResult !== null) {
            return $this->envCheckResult;
        }
        
        $results = [
            'success' => true,
            'errors' => [],
            'warnings' => [],
            'info' => [],
        ];
        
        $this->checkPHPVersion($results);
        $this->checkRequiredFunctions($results);
        $this->checkRequiredExtensions($results);
        $this->checkDisabledFunctions($results);
        $this->checkPHPConfig($results);
        $this->checkFFmpegInstallation($results);
        $this->checkDirectoryPermissions($results);
        $this->checkFFmpegFeatures($results);
        
        $this->envCheckResult = $results;
        
        if (!$results['success']) {
            throw new \Exception("环境检查失败:\n" . implode("\n", $results['errors']));
        }
        
        return $results;
    }
    
    /**
     * 检查 PHP 版本是否符合要求
     * @param array $results 环境检查结果数组
     */
    private function checkPHPVersion(array &$results): void
    {
        $requiredVersion = '7.0.0';
        $currentVersion = PHP_VERSION;
        if (version_compare($currentVersion, $requiredVersion, '<')) {
            $results['errors'][] = "PHP 版本过低: 需要 >= {$requiredVersion}，当前版本: {$currentVersion}";
            $results['success'] = false;
        } else {
            $results['info'][] = "PHP 版本: {$currentVersion} (符合要求)";
        }
    }
    
    /**
     * 检查必需的 PHP 函数是否可用
     * @param array $results 环境检查结果数组
     */
    private function checkRequiredFunctions(array &$results): void
    {
        $disabledFunctions = $this->getDisabledFunctions();
        foreach ($this->requiredFunctions as $function) {
            if (!function_exists($function)) {
                $results['errors'][] = "必需的函数不可用: {$function}()";
                $results['success'] = false;
            } elseif (in_array($function, $disabledFunctions)) {
                $results['errors'][] = "必需的函数被禁用: {$function}()";
                $results['success'] = false;
            }
        }
        if ($results['success']) {
            $results['info'][] = "所有必需的函数检查通过";
        }
    }
    
    /**
     * 检查必需的 PHP 扩展是否加载
     * @param array $results 环境检查结果数组
     */
    private function checkRequiredExtensions(array &$results): void
    {
        foreach ($this->requiredExtensions as $extension) {
            if (!extension_loaded($extension)) {
                $results['errors'][] = "必需的 PHP 扩展未加载: {$extension}";
                $results['success'] = false;
            }
        }
        if ($results['success']) {
            $results['info'][] = "所有必需的扩展检查通过";
        }
    }
    
    /**
     * 检查禁用的 PHP 函数是否包含关键函数
     * @param array $results 环境检查结果数组
     */
    private function checkDisabledFunctions(array &$results): void
    {
        $disabledFunctions = $this->getDisabledFunctions();
        if (!empty($disabledFunctions)) {
            // 检查最关键的几个函数
            $critical = ['proc_open', 'proc_close', 'proc_terminate'];
            $disabledCritical = array_intersect($critical, $disabledFunctions);
            if (!empty($disabledCritical)) {
                $results['errors'][] = "关键函数被禁用: " . implode(', ', $disabledCritical);
                $results['success'] = false;
            }
        }
    }
    
    /**
     * 获取禁用的 PHP 函数列表
     * @return array
     */
    private function getDisabledFunctions(): array
    {
        $disabled = ini_get('disable_functions');
        if (empty($disabled)) {
            return [];
        }
        return array_map('trim', explode(',', $disabled));
    }
    
    /**
     * 检查 PHP 配置是否符合要求
     * @param array $results 环境检查结果数组
     */
    private function checkPHPConfig(array &$results): void
    {
        $openBasedir = ini_get('open_basedir');
        if ($openBasedir) {
            $results['warnings'][] = "open_basedir 已设置，可能限制文件访问: {$openBasedir}";
        }
        foreach ($this->recommendedConfig as $key => $recommended) {
            $current = ini_get($key);
            $currentValue = $this->parseIniValue($current);
            $recommendedValue = $this->parseIniValue($recommended);
            if ($currentValue < $recommendedValue) {
                $results['warnings'][] = "PHP 配置 {$key} 值偏低: 当前 {$current}，建议 {$recommended}";
            }
        }
    }
    
    /**
     * 解析 PHP 配置值（支持 M、G、K 单位）
     * @param string $value
     * @return int
     */
    private function parseIniValue(string $value): int
    {
        $value = trim($value);
        if ($value === '') return 0;
        $last = strtoupper($value[strlen($value) - 1]);
        $numeric = (int)$value;
        switch ($last) {
            case 'G': $numeric *= 1024;
            case 'M': $numeric *= 1024;
            case 'K': $numeric *= 1024;
        }
        return $numeric;
    }
    
    /**
     * 检查 FFmpeg 安装是否正确
     * @param array $results 环境检查结果数组
     */
    private function checkFFmpegInstallation(array &$results): void
    {
        // 检查 ffmpeg
        $ffmpegAvailable = $this->checkCommand($this->ffmpegPath . ' -version');
        if (!$ffmpegAvailable) {
            $results['errors'][] = "FFmpeg 不可用或路径不正确: {$this->ffmpegPath}";
            $results['success'] = false;
        } else {
            $version = $this->getFFmpegVersion($this->ffmpegPath);
            $results['info'][] = "FFmpeg 可用: {$version}";
        }
        
        // 检查 ffprobe
        $ffprobeAvailable = $this->checkCommand($this->ffprobePath . ' -version');
        if (!$ffprobeAvailable) {
            $results['warnings'][] = "FFprobe 不可用或路径不正确: {$this->ffprobePath}";
            $results['warnings'][] = "视频信息获取功能将不可用";
        } else {
            $version = $this->getFFmpegVersion($this->ffprobePath);
            $results['info'][] = "FFprobe 可用: {$version}";
        }
    }
    
    /**
     * 获取 FFmpeg 版本信息
     * @param string $ffPath
     * @return string
     */
    private function getFFmpegVersion(string $ffPath): string
    {
        try {
            $cmd = escapeshellcmd($ffPath) . ' -version';
            $stdout = $this->executeCommand($cmd);
            return isset($stdout[0]) ? $stdout[0] : 'unknown';
        } catch (\Exception $e) {
            return 'unknown';
        }
    }
    
    /**
     * 检查 FFmpeg 功能是否可用
     * @param array $results 环境检查结果数组
     */
    private function checkFFmpegFeatures(array &$results): void
    {
        $encoders = $this->getFFmpegEncoders();
        $requiredEncoders = ['libx264' => 'H.264 视频编码', 'aac' => 'AAC 音频编码'];
        foreach ($requiredEncoders as $encoder => $desc) {
            if (!in_array($encoder, $encoders)) {
                $results['warnings'][] = "编码器 {$encoder} ({$desc}) 不可用";
            }
        }
        
        $muxers = $this->getFFmpegMuxers();
        $requiredMuxers = ['mp4' => 'MP4 容器格式', 'segment' => '视频分段功能'];
        foreach ($requiredMuxers as $muxer => $desc) {
            if (!in_array($muxer, $muxers)) {
                $results['warnings'][] = "复用器 {$muxer} ({$desc}) 不可用";
            }
        }
        
        $filters = $this->getFFmpegFilters();
        $requiredFilters = ['scale' => '视频缩放', 'fps' => '帧率调整', 'concat' => '视频合并（滤镜方式）'];
        foreach ($requiredFilters as $filter => $desc) {
            if (!in_array($filter, $filters)) {
                $results['warnings'][] = "滤镜 {$filter} ({$desc}) 不可用";
            }
        }
    }
    
    /**
     * 获取 FFmpeg 编码器列表
     * @return array
     */
    private function getFFmpegEncoders(): array
    {
        $cmd = escapeshellcmd($this->ffmpegPath) . ' -encoders';
        try {
            $stdout = $this->executeCommand($cmd);
        } catch (\Exception $e) {
            return [];
        }
        $encoders = [];
        foreach ($stdout as $line) {
            if (preg_match('/^\s*[VEDAS].{5}\s+(\S+)/', $line, $matches)) {
                $encoders[] = $matches[1];
            }
        }
        return $encoders;
    }
    
    /**
     * 获取 FFmpeg 复用器列表
     * @return array
     */
    private function getFFmpegMuxers(): array
    {
        $cmd = escapeshellcmd($this->ffmpegPath) . ' -muxers';
        try {
            $stdout = $this->executeCommand($cmd);
        } catch (\Exception $e) {
            return [];
        }
        $muxers = [];
        foreach ($stdout as $line) {
            if (preg_match('/^\s*[ED]\s+(\S+)/', $line, $matches)) {
                $muxers[] = $matches[1];
            }
        }
        return $muxers;
    }
    
    /**
     * 获取 FFmpeg 滤镜列表
     * @return array
     */
    private function getFFmpegFilters(): array
    {
        $cmd = escapeshellcmd($this->ffmpegPath) . ' -filters';
        try {
            $stdout = $this->executeCommand($cmd);
        } catch (\Exception $e) {
            return [];
        }
        $filters = [];
        foreach ($stdout as $line) {
            if (preg_match('/^\s*[TSC\.]{3}\s+[VANF]{3}\s+\|\->\s+[VANF]{3}\s+\|\s+(\S+)/', $line, $matches)) {
                $filters[] = $matches[1];
            }
        }
        return $filters;
    }
    
    /**
     * 检查目录权限是否正确
     * @param array $results 环境检查结果数组
     */
    private function checkDirectoryPermissions(array &$results): void
    {
        $directories = ['tempDir' => $this->tempDir, 'outputDir' => $this->outputDir];
        foreach ($directories as $name => $dir) {
            if (!file_exists($dir)) {
                if (!@mkdir($dir, 0755, true)) {
                    $results['errors'][] = "无法创建目录: {$dir}";
                    $results['success'] = false;
                } else {
                    $results['info'][] = "目录已创建: {$dir}";
                }
            }
            if (!is_writable($dir)) {
                $results['errors'][] = "目录不可写: {$dir}";
                $results['success'] = false;
            }
        }
    }
    
    /**
     * 获取环境检查报告 HTML 字符串
     * @return string
     */
    public function getEnvironmentReport(): string
    {
        $checkResult = $this->envCheckResult ?? $this->performEnvironmentCheck();
        $html = '<div class="environment-report">';
        $html .= '<h2>FFmpeg 环境检查报告</h2>';
        $statusClass = $checkResult['success'] ? 'success' : 'error';
        $statusIcon = $checkResult['success'] ? '✅' : '❌';
        $statusText = $checkResult['success'] ? '环境检查通过' : '环境检查失败';
        $html .= '<div class="status ' . $statusClass . '">' . $statusIcon . ' ' . $statusText . '</div>';
        
        foreach (['errors' => '错误', 'warnings' => '警告', 'info' => '信息'] as $key => $title) {
            if (!empty($checkResult[$key])) {
                $html .= '<div class="section ' . $key . '">';
                $html .= '<h3>' . $title . ' (' . count($checkResult[$key]) . ')</h3><ul>';
                foreach ($checkResult[$key] as $msg) {
                    $html .= '<li>' . htmlspecialchars($msg) . '</li>';
                }
                $html .= '</ul></div>';
            }
        }
        $html .= '</div>';
        $html .= '<style>
            .environment-report { font-family: Arial, sans-serif; max-width: 800px; margin: 20px auto; }
            .environment-report h2 { color: #333; border-bottom: 2px solid #eee; padding-bottom: 10px; }
            .status { padding: 10px; margin: 10px 0; border-radius: 5px; font-weight: bold; }
            .status.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .status.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
            .section { margin: 15px 0; padding: 10px; border-radius: 5px; }
            .section.errors { background: #fdf0f0; }
            .section.warnings { background: #fff8e1; }
            .section.info { background: #e8f4f8; }
            .section h3 { margin-top: 0; }
            .section ul { margin: 5px 0; padding-left: 20px; }
            .section li { margin: 3px 0; }
        </style>';
        return $html;
    }
    
    /**
     * 快速环境环境检查
     * @return bool
     */
    public function quickCheck(): bool
    {
        try {
            $this->performEnvironmentCheck();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 检查文件是否存在且可读
     * @param string $filePath
     */
    private function checkFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new \Exception("文件不存在: " . $filePath);
        }
        if (!is_readable($filePath)) {
            throw new \Exception("文件不可读: " . $filePath);
        }
    }
    
    /**
     * 创建目录（如果不存在）
     * @param string $directory
     */
    private function createDirectory(string $directory): void
    {
        if (!file_exists($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw new \Exception("无法创建目录: " . $directory);
            }
        }
        if (!is_writable($directory)) {
            throw new \Exception("目录不可写: " . $directory);
        }
    }
    
    /**
     * 获取视频信息
     * @param string $videoPath
     * @return array
     */
    public function getVideoInfo(string $videoPath): array
    {
        $this->checkFile($videoPath);
        
        $command = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams %s',
            escapeshellcmd($this->ffprobePath),
            escapeshellarg($videoPath)
        );
        
        $jsonString = $this->executeAndGetString($command);
        $videoInfo = json_decode($jsonString, true);
        
        if (!$videoInfo) {
            throw new \Exception("无法解析视频信息");
        }
        
        $videoStream = null;
        $audioStream = null;
        foreach ($videoInfo['streams'] as $stream) {
            if ($stream['codec_type'] === 'video' && !$videoStream) {
                $videoStream = $stream;
            }
            if ($stream['codec_type'] === 'audio' && !$audioStream) {
                $audioStream = $stream;
            }
        }
        
        return [
            'filename' => basename($videoPath),
            'filepath' => realpath($videoPath),
            'filesize' => $this->formatFileSize($videoInfo['format']['size'] ?? 0),
            'duration' => (float)($videoInfo['format']['duration'] ?? 0),
            'duration_formatted' => $this->formatDuration($videoInfo['format']['duration'] ?? 0),
            'bitrate' => (int)($videoInfo['format']['bit_rate'] ?? 0),
            'bitrate_formatted' => $this->formatBitrate($videoInfo['format']['bit_rate'] ?? 0),
            'video' => $videoStream ? [
                'codec' => $videoStream['codec_name'] ?? 'unknown',
                'codec_long' => $videoStream['codec_long_name'] ?? 'unknown',
                'width' => (int)($videoStream['width'] ?? 0),
                'height' => (int)($videoStream['height'] ?? 0),
                'fps' => $this->calculateFPS($videoStream),
                'aspect_ratio' => $videoStream['display_aspect_ratio'] ?? 'unknown',
                'pix_fmt' => $videoStream['pix_fmt'] ?? 'unknown',
            ] : null,
            'audio' => $audioStream ? [
                'codec' => $audioStream['codec_name'] ?? 'unknown',
                'codec_long' => $audioStream['codec_long_name'] ?? 'unknown',
                'sample_rate' => (int)($audioStream['sample_rate'] ?? 0),
                'channels' => (int)($audioStream['channels'] ?? 0),
                'channel_layout' => $audioStream['channel_layout'] ?? 'unknown',
            ] : null,
        ];
    }
    
    /**
     * 计算视频帧率
     * @param array $videoStream
     * @return float
     */
    private function calculateFPS(array $videoStream): float
    {
        if (!empty($videoStream['avg_frame_rate'])) {
            $parts = explode('/', $videoStream['avg_frame_rate']);
            if (count($parts) === 2 && $parts[1] > 0) {
                return round($parts[0] / $parts[1], 2);
            }
        }
        if (!empty($videoStream['r_frame_rate'])) {
            $parts = explode('/', $videoStream['r_frame_rate']);
            if (count($parts) === 2 && $parts[1] > 0) {
                return round($parts[0] / $parts[1], 2);
            }
        }
        return 0;
    }
    
    /**
     * 提取视频帧图
     * @param string $videoPath
     * @param array $options
     * @return string
     */
    public function extractFrame(string $videoPath, array $options = []): string
    {
        $this->checkFile($videoPath);
        
        $defaults = [
            'time' => 1,
            'width' => null,
            'height' => null,
            'quality' => 90,
            'output' => null
        ];
        $options = array_merge($defaults, $options);
        
        if (!$options['output']) {
            $filename = pathinfo($videoPath, PATHINFO_FILENAME);
            $options['output'] = $this->outputDir . $filename . '_frame_' . date('YmdHis') . '.jpg';
        }
        $this->createDirectory(dirname($options['output']));
        
        $scaleFilter = '';
        if ($options['width'] || $options['height']) {
            $width = $options['width'] ?? -1;
            $height = $options['height'] ?? -1;
            $scaleFilter = "-vf scale={$width}:{$height}";
        }
        
        // 质量转换 (q:v 2-31，越小越好)
        $q = intval((100 - $options['quality']) / 100 * 31);
        $q = max(2, min(31, $q));
        
        $command = sprintf(
            '%s -ss %s -i %s -vframes 1 -q:v %d %s -y %s',
            escapeshellcmd($this->ffmpegPath),
            escapeshellarg($options['time']),
            escapeshellarg($videoPath),
            $q,
            $scaleFilter,
            escapeshellarg($options['output'])
        );
        
        $this->executeCommand($command);
        return $options['output'];
    }
    
    /**
     * 提取视频帧图
     * @param string $videoPath
     * @param int $count
     * @param array $options
     * @return array
     */
    public function extractFrames(string $videoPath, int $count = 10, array $options = []): array
    {
        $this->checkFile($videoPath);
        $videoInfo = $this->getVideoInfo($videoPath);
        $duration = $videoInfo['duration'];
        
        $defaults = [
            'start_time' => 0,
            'interval' => null,
            'width' => null,
            'height' => null,
            'quality' => 90,
            'output_pattern' => null
        ];
        $options = array_merge($defaults, $options);
        
        if (!$options['interval']) {
            $options['interval'] = ($duration - $options['start_time']) / $count;
        }
        if (!$options['output_pattern']) {
            $filename = pathinfo($videoPath, PATHINFO_FILENAME);
            $options['output_pattern'] = $this->outputDir . $filename . '_frame_%03d.jpg';
        }
        $this->createDirectory(dirname($options['output_pattern']));
        
        $vfFilters = [];
        if ($options['width'] || $options['height']) {
            $width = $options['width'] ?? -1;
            $height = $options['height'] ?? -1;
            $vfFilters[] = "scale={$width}:{$height}";
        }
        $vfString = !empty($vfFilters) ? '-vf ' . escapeshellarg(implode(',', $vfFilters)) : '';
        $q = intval((100 - $options['quality']) / 100 * 31);
        $q = max(2, min(31, $q));
        
        $command = sprintf(
            '%s -ss %s -i %s -t %s -vf "fps=1/%s" %s -q:v %d -y %s',
            escapeshellcmd($this->ffmpegPath),
            escapeshellarg($options['start_time']),
            escapeshellarg($videoPath),
            escapeshellarg((string)($duration - $options['start_time'])),
            escapeshellarg($options['interval']),
            $vfString,
            $q,
            escapeshellarg($options['output_pattern'])
        );
        
        $this->executeCommand($command);
        
        $files = [];
        $dir = dirname($options['output_pattern']);
        $pattern = str_replace(['%03d', '%d'], '*', basename($options['output_pattern']));
        foreach (glob($dir . '/' . $pattern) as $file) {
            $files[] = $file;
        }
        sort($files);
        return $files;
    }
    
    /**
     * 切分视频
     * @param string $videoPath
     * @param float $startTime
     * @param float $duration
     * @param array $options
     * @return string
     */
    public function cutVideo(string $videoPath, float $startTime, float $duration, array $options = []): string
    {
        $this->checkFile($videoPath);
        
        $defaults = [
            'output' => null,
            're_encode' => false,
            'video_codec' => 'copy',
            'audio_codec' => 'copy'
        ];
        $options = array_merge($defaults, $options);
        
        if (!$options['output']) {
            $filename = pathinfo($videoPath, PATHINFO_FILENAME);
            $extension = pathinfo($videoPath, PATHINFO_EXTENSION);
            $options['output'] = $this->outputDir . $filename . '_cut_' . date('YmdHis') . '.' . $extension;
        }
        $this->createDirectory(dirname($options['output']));
        
        if ($options['re_encode']) {
            $command = sprintf(
                '%s -ss %s -i %s -t %s -c:v libx264 -c:a aac -strict experimental -y %s',
                escapeshellcmd($this->ffmpegPath),
                escapeshellarg((string)($startTime)),
                escapeshellarg($videoPath),
                escapeshellarg((string)($duration)),
                escapeshellarg($options['output'])
            );
        } else {
            $command = sprintf(
                '%s -ss %s -i %s -t %s -c:v %s -c:a %s -avoid_negative_ts make_zero -y %s',
                escapeshellcmd($this->ffmpegPath),
                escapeshellarg((string)($startTime)),
                escapeshellarg($videoPath),
                escapeshellarg((string)($duration)),
                escapeshellarg($options['video_codec']),
                escapeshellarg($options['audio_codec']),
                escapeshellarg($options['output'])
            );
        }
        
        $this->executeCommand($command);
        return $options['output'];
    }
    
    /**
     * 按大小切分视频
     * @param string $videoPath
     * @param int $chunkSize
     * @param array $options
     * @return array
     */
    public function splitBySize(string $videoPath, int $chunkSize = 100, array $options = []): array
    {
        $this->checkFile($videoPath);
        $videoInfo = $this->getVideoInfo($videoPath);
        $totalSize = filesize($videoPath);
        $totalDuration = $videoInfo['duration'];
        $chunkDuration = ($chunkSize * 1024 * 1024 / $totalSize) * $totalDuration;
        
        $defaults = ['output_pattern' => null];
        $options = array_merge($defaults, $options);
        
        if (!$options['output_pattern']) {
            $filename = pathinfo($videoPath, PATHINFO_FILENAME);
            $extension = pathinfo($videoPath, PATHINFO_EXTENSION);
            $options['output_pattern'] = $this->outputDir . $filename . '_part_%03d.' . $extension;
        }
        $this->createDirectory(dirname($options['output_pattern']));
        
        $command = sprintf(
            '%s -i %s -c copy -map 0 -segment_time %s -f segment -reset_timestamps 1 %s',
            escapeshellcmd($this->ffmpegPath),
            escapeshellarg($videoPath),
            escapeshellarg((string)($chunkDuration)),
            escapeshellarg($options['output_pattern'])
        );
        
        $this->executeCommand($command);
        
        $files = [];
        $dir = dirname($options['output_pattern']);
        $pattern = str_replace('%03d', '*', basename($options['output_pattern']));
        foreach (glob($dir . '/' . $pattern) as $file) {
            $files[] = $file;
        }
        sort($files);
        return $files;
    }
    
    /**
     * 合并视频
     * @param array $videoFiles
     * @param array $options
     * @return string
     */
    public function mergeVideos(array $videoFiles, array $options = []): string
    {
        if (count($videoFiles) < 2) {
            throw new \Exception("至少需要两个视频文件进行合并");
        }
        foreach ($videoFiles as $file) {
            $this->checkFile($file);
        }
        
        $defaults = ['output' => null, 'use_concat_demuxer' => true];
        $options = array_merge($defaults, $options);
        
        if (!$options['output']) {
            $options['output'] = $this->outputDir . 'merged_' . date('YmdHis') . '.mp4';
        }
        $this->createDirectory(dirname($options['output']));
        
        if ($options['use_concat_demuxer']) {
            return $this->mergeWithConcatDemuxer($videoFiles, $options['output']);
        } else {
            return $this->mergeWithConcatFilter($videoFiles, $options['output']);
        }
    }
    
    /**
     * 合并视频（使用 concat 拼接）
     * @param array $videoFiles
     * @param string $outputPath
     * @return string
     */
    private function mergeWithConcatDemuxer(array $videoFiles, string $outputPath): string
    {
        $listFile = $this->tempDir . 'concat_list_' . uniqid() . '.txt';
        $this->createDirectory(dirname($listFile));
        
        $content = '';
        foreach ($videoFiles as $file) {
            $content .= "file '" . realpath($file) . "'\n";
        }
        file_put_contents($listFile, $content);
        
        $command = sprintf(
            '%s -f concat -safe 0 -i %s -c copy -y %s',
            escapeshellcmd($this->ffmpegPath),
            escapeshellarg($listFile),
            escapeshellarg($outputPath)
        );
        
        try {
            $this->executeCommand($command);
        } finally {
            if (file_exists($listFile)) {
                unlink($listFile);
            }
        }
        return $outputPath;
    }
    
    /**
     * 合并视频（使用 concat 拼接）
     * @param array $videoFiles
     * @param string $outputPath
     * @return string
     */
    private function mergeWithConcatFilter(array $videoFiles, string $outputPath): string
    {
        $inputs = [];
        $filters = [];
        foreach ($videoFiles as $index => $file) {
            $inputs[] = '-i ' . escapeshellarg($file);
            $filters[] = "[{$index}:v:0][{$index}:a:0]";
        }
        $filterComplex = implode('', $filters) . "concat=n=" . count($videoFiles) . ":v=1:a=1[outv][outa]";
        
        $command = sprintf(
            '%s %s -filter_complex "%s" -map "[outv]" -map "[outa]" -y %s',
            escapeshellcmd($this->ffmpegPath),
            implode(' ', $inputs),
            $filterComplex,
            escapeshellarg($outputPath)
        );
        
        $this->executeCommand($command);
        return $outputPath;
    }
    
    /**
     * 视频转码
     * @param string $videoPath
     * @param array $options
     * @return string
     */
    public function transcode(string $videoPath, array $options = []): string
    {
        $this->checkFile($videoPath);
        
        $defaults = [
            'output' => null,
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'video_bitrate' => null,
            'audio_bitrate' => null,
            'resolution' => null,
            'fps' => null
        ];
        $options = array_merge($defaults, $options);
        
        if (!$options['output']) {
            $filename = pathinfo($videoPath, PATHINFO_FILENAME);
            $options['output'] = $this->outputDir . $filename . '_transcoded.mp4';
        }
        $this->createDirectory(dirname($options['output']));
        
        $params = [];
        if ($options['video_codec']) $params[] = '-c:v ' . escapeshellarg($options['video_codec']);
        if ($options['audio_codec']) $params[] = '-c:a ' . escapeshellarg($options['audio_codec']);
        if ($options['video_bitrate']) $params[] = '-b:v ' . escapeshellarg($options['video_bitrate']);
        if ($options['audio_bitrate']) $params[] = '-b:a ' . escapeshellarg($options['audio_bitrate']);
        
        $vfFilters = [];
        if ($options['resolution']) $vfFilters[] = 'scale=' . $options['resolution'];
        if ($options['fps']) $vfFilters[] = 'fps=' . $options['fps'];
        if (!empty($vfFilters)) $params[] = '-vf ' . escapeshellarg(implode(',', $vfFilters));
        
        $command = sprintf(
            '%s -i %s %s -y %s',
            escapeshellcmd($this->ffmpegPath),
            escapeshellarg($videoPath),
            implode(' ', $params),
            escapeshellarg($options['output'])
        );
        
        $this->executeCommand($command);
        return $options['output'];
    }
    
    /**
     * 格式化文件大小
     * @param int $bytes
     * @return string
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * 格式化视频时长
     * @param float $seconds
     * @return string
     */
    private function formatDuration(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = floor($seconds % 60);
        return $hours > 0
            ? sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds)
            : sprintf('%02d:%02d', $minutes, $seconds);
    }
    
    /**
     * 格式化视频码率
     * @param int $bitsPerSecond
     * @return string
     */
    private function formatBitrate(int $bitsPerSecond): string
    {
        if ($bitsPerSecond >= 1000000) {
            return round($bitsPerSecond / 1000000, 1) . ' Mbps';
        } elseif ($bitsPerSecond >= 1000) {
            return round($bitsPerSecond / 1000, 1) . ' Kbps';
        }
        return $bitsPerSecond . ' bps';
    }
}
