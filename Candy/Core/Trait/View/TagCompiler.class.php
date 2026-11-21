<?php
declare(strict_types=1);
namespace Candy\Core\Trait\View;

use Candy\Core\Debug;
use Candy\Core\View as ViewHost;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 模板标签解析与编译
 */
final class TagCompiler
{
    /** @var list<int> 每个 if 块因 elseif→else/if 改写而需要额外补的 endif 数 */
    private array $ifExtraEndifStack = [];

    public function __construct(private ViewHost $view) {}


/**
 * Compile Content
 * @param mixed $content
 * @param mixed $nocache
 * @return string
 */
public function compileContent(string $content,bool $nocache = false): string
	{
		if($content == ''){
			return '';
		}
		// 每次编译重置 if 嵌套栈，避免跨模板污染
		$this->ifExtraEndifStack = [];
		
		// 缓存正则表达式模式，避免重复编译
		static $regexCache = [];
		$cacheKey = $this->view->getLeftDelimiter() . '|' . $this->view->getRightDelimiter();
		
		if (!isset($regexCache[$cacheKey])) {
			$left_tag = preg_quote($this->view->getLeftDelimiter(), '~');
			$right_tag = preg_quote($this->view->getRightDelimiter(), '~');
			
			$regexCache[$cacheKey] = [
				'left_tag' => $left_tag,
				'right_tag' => $right_tag,
				'block' => '~(' . $left_tag . '\s*block(.*?)=[\'"](.*?)[\'"](.*?)\s*' . $right_tag . ')(.*?)(' . $left_tag . '\s*/block\s*' . $right_tag . ')~s',
				'sysfunc' => '~(' . $left_tag . '\s*:(.*?)\s*' . $right_tag . ')~s',
				'literal' => '~(' . $left_tag . '\s*literal\s*' . $right_tag . ')(.*?)(' . $left_tag . '\s*/literal\s*' . $right_tag . ')~s',
				'nocache' => '~(' . $left_tag . '\s*nocache\s*' . $right_tag . ')(.*?)(' . $left_tag . '\s*/nocache\s*' . $right_tag . ')~s',
				'template_tag' => '~' . $left_tag . '\s*(.*?)\s*' . $right_tag . '~s',
				'template_split' => '~' . $left_tag . '.*?' . $right_tag . '~s'
			];
		} else {
			$left_tag = $regexCache[$cacheKey]['left_tag'];
			$right_tag = $regexCache[$cacheKey]['right_tag'];
		}
		
		$text_blocks = [];
		$compiled_tags = [];
		$block_tags = [];
		$block_names = [];
		
		/* 处理block */
		preg_match_all($regexCache[$cacheKey]['block'], $content, $__match);
		if(count($__match[2]) > 0){
			foreach($__match[2] as $key=>$match){
				if(trim($match) != 'name'){
					//置换为空 防止死循环
					$content = str_replace($__match[0][$key], '', $content);
					continue;
				}
				$block_names[] = 'viewblock'. trim($__match[3][$key]);
				if(empty($__match[5][$key])){
					$___m = 'viewblock'. trim($__match[3][$key]);
					
				}else{
					if(trim($__match[4][$key]) == 'default'){
						$___m = 'viewblock'. trim($__match[3][$key]);
					}else{
						$___m = '';
					}
					$block_tags[trim($__match[3][$key])] = $__match[5][$key];
				}
				$content = str_replace($__match[0][$key], $___m, $content);
			}
		}
		
		if(count($block_tags) > 0){
			foreach($block_tags as $key=>$block){
				$content = str_replace('viewblock'.$key, $block, $content);
			}
		}
		
		if(count($block_names) > 0){
			foreach($block_names as $name){
				$content = str_replace($name, '', $content);
			}
		}
		
        /* 处理系统函数 :  :: */
		preg_match_all($regexCache[$cacheKey]['sysfunc'], $content, $__match);
		if(count($__match[2]) > 0){
			foreach($__match[2] as $key=>$_func){
				$nameStr = '';
				if($_func[0] == ':'){
					$newStr = '<?php ';
					$_func = substr($_func, 1);
				}else{
					$newStr = '<?php echo ';
				}
				if(stripos($_func, '$') === false){
					$newStr .= $_func .'; ?>';
				}else{
					$strArr = explode(',', $_func);
					foreach($strArr as $str){
						if(stripos($str, '$') === false){
							$newStr .= $str . ',';
						}else{
							$str = trim($str);
							$left = substr_count($str,'[');
							$right = substr_count($str,']');
							$num = $right - $left;
							if($num > 0){
								$start = strripos($str, ']');
								$start -= $num - 1;
								$str = substr($str, 0, $start) . $this->view->getRightDelimiter() . substr($str, $start);
							}else{
								if($right > 0){
									$start = stripos($str, ']');
									$str = substr($str, 0, $start) . $this->view->getRightDelimiter() . substr($str, $start);
								}else{
									$str .= $this->view->getRightDelimiter();
								}
							}
							$newStr .= str_replace('$', $this->view->getLeftDelimiter() . '$_func_fix.', $str) . ',';
						}
					}
					$newStr = trim($newStr, ',') . '; ?>';
				}
				$content = str_replace($__match[0][$key], $newStr, $content);
			}
		}
		
        /* 处理literal */
		preg_match_all($regexCache[$cacheKey]['literal'], $content, $__match);
		$literal_replacements = [];
		if(count($__match[2]) > 0){
			foreach($__match[2] as $key => $value){
				$placeholder = 'viewliteral' . $key;
				$literal_replacements[$placeholder] = $value;
				$content = str_replace($value, $placeholder, $content);
			}
		}
		
        /* 处理php */
		//注销php代码解析
		//$search = "~({$left_tag}\s*php\s*{$right_tag})(.*?)({$left_tag}\s*/php\s*{$right_tag})~s";
		//preg_match_all($search, $content, $__match);
		//if(count($__match[2]) > 0)
		//	foreach($__match[2] as $key => $value){
		//		$_m = 'viewphp'.$key;
		//		$$_m = $value;
		//		$content = str_replace($value,$_m,$content);
		//	}
		//
        /* 处理nocache */
		$nocachetmp = $content;
		preg_match_all($regexCache[$cacheKey]['nocache'], $nocachetmp, $__match);
		$nocache_replacements = [];
		if(count($__match[2]) > 0){
			foreach($__match[2] as $key => $value){
				$placeholder = 'viewnocache' . $key;
				$nocache_replacements[$placeholder] = $value;
				$nocachetmp = str_replace($value, $placeholder, $nocachetmp);
			}
		}
		
		if($nocache){
			preg_match_all($regexCache[$cacheKey]['template_tag'], $nocachetmp, $_match);
			$templateTags = $_match[1];
			$text_blocks = preg_split($regexCache[$cacheKey]['template_split'], $nocachetmp);
		}else{
			preg_match_all($regexCache[$cacheKey]['template_tag'], $content, $_match);
			$templateTags = $_match[1];
			$text_blocks = preg_split($regexCache[$cacheKey]['template_split'], $content);
		}
		
		for($i = 0; $i < count($templateTags); $i++){
			$compiled_tags[] = $this->compileTag($templateTags[$i], $nocache);
		}
		
		$content = '';
        $text_blocks_count = count($text_blocks);
        $compiled_tags_count = count($compiled_tags);
        for($i = 0; $i < $text_blocks_count; $i++){
			if(strpos($text_blocks[$i],'viewliteral')!==false){
				$__m = $text_blocks[$i];
				$content .= $literal_replacements[$__m] ?? $__m;
			//注销php代码解析
			/*}elseif(strpos($text_blocks[$i],'viewphp')!==false){
				$__m = $text_blocks[$i];
				$content .= '<?php '. $$__m .' ?>';*/
			}else{
				$content .= $text_blocks[$i];
			}
			if($i < $compiled_tags_count){
				$content .= $compiled_tags[$i];
			}
        }
		
		// 使用str_replace批量替换nocache内容
		if(!empty($nocache_replacements)){
			$content = str_replace(array_keys($nocache_replacements), array_values($nocache_replacements), $content);
		}
		
		return $content;
	}

/**
 * Compile Tag
 * @param mixed $templateTag
 * @param mixed $nocache
 * @return string
 */
public function compileTag(string $templateTag,bool $nocache = false): string
	{
		preg_match_all('~((?>[^"\"\'=\s]+))+ | [=]~x', $templateTag, $match);
		$tagStr = $match[0];
        $tagCommand = $tagStr[0];
		$parseVar = $this->compileParseVar($tagStr);
		switch($tagCommand){
			case '_token_':
			case '_TOKEN_':
			case '__token__':
			case '__TOKEN__':
			case '_csrf_':
			case '_CSRF_':
			case '__csrf__':
			case '__CSRF__':
				return $this->compileTokenTag($tagCommand, $nocache);
			case 'if':
			case 'else':
			case 'else/': // 兼容历史写法 <{else/}>，与 <{else}> 等价
			case 'elseif':
			case '/if':
				return $this->compileIfTag($tagCommand === 'else/' ? 'else' : $tagCommand, $templateTag);
			case 'foreach':
			case 'foreachelse':
			case '/foreach':
				if ($tagCommand === 'foreach'
					&& !$this->hasForeachAttr($templateTag, 'from')
					&& !$this->hasForeachAttr($templateTag, 'item')) {
					// as 模式：<{foreach $list as $row}> 或 <{foreach $list as $key => $row}>
					// 必须用「空白 + as + 空白」切分，禁止 explode('as')（如 class_groups 内含 as 子串会误拆）
					unset($tagStr);
					$tag = trim(str_replace('foreach', '', $templateTag));
					$tagVar = preg_split('~\s+as\s+~', $tag, 2);
					$foreachSource = trim((string)($tagVar[0] ?? ''));
					//key
					if(isset($tagVar[1]) && stripos($tagVar[1], '=>'))
						$tagVar[1] = explode('=>', $tagVar[1], 2);
					
					if(is_array($tagVar[1])){
						$tagStr[7] = 'key';
						$tagStr[8] = '=';
						$tagStr[9] = substr(trim($tagVar[1][0]), 1);
						$tagStr[6] = substr(trim($tagVar[1][1]), 1);
					}elseif(isset($tagVar[1])){
						$tagStr[6] = substr(trim($tagVar[1]), 1);
					}
					
					$tagStr[3] = trim($tagVar[0]);
					$tagStr[1] = 'from';
					$tagStr[2] = $tagStr[5] = '=';
					$tagStr[4] = 'item';
					$parseVar = $this->compileParseVar($tagStr);
					// as 模式的来源表达式允许使用变量修饰器：
					// <{foreach $list|default=[] as $item}>。
					// compileParseVar 只会编译普通变量，须在此保留并编译修饰器链。
					$parseVar['from'] = $this->compileForeachSourceExpression($foreachSource);
				}
				return $this->compileForeachTag($tagCommand, $parseVar);
			case 'loop':
			case '/loop':
			case 'volist':
			case '/volist':
				return $this->compileLoopTag($tagCommand, $parseVar);
			case 'define':
			case 'defined':
			case '/defined':
			case 'notdefined':
			case '/notdefined':
				return $this->compileDefineTag($tagCommand, $parseVar);
			case 'empty':
			case '/empty':
			case 'notempty':
			case '/notempty':
				return $this->compileEmptyTag($tagCommand, $parseVar);
			case 'include':
				return $this->compileIncludeTag($tagCommand, $this->parseTagAttributes($templateTag));
			case 'import':
				return $this->compileImportTag($tagCommand, $parseVar);
			case 'eq':
			case '/eq':
				return $this->compileEqTag($tagCommand, $templateTag, $parseVar);
			default :
				if($tagCommand[0] == '$'){
					return $this->compileVariableTag($templateTag);
				}else{
					$func_list = array_merge(loadConfig('Tplfunc'), ['url', 'assign', 'spider', '_define']);
					if(!$nocache || in_array(strtolower($tagCommand), $func_list)){
						if(in_array(strtolower($tagCommand), $func_list)){
							if($output = $this->systemFrametion($tagCommand, $parseVar)){
								return $output;
							}
						}else{
							return '';
						}
					}else{
						return $this->view->getLeftDelimiter() . $templateTag . $this->view->getRightDelimiter();
					}
				}
		}

		return '';
	}

/**
 * Compile Var Tag
 * @param mixed $tagStr
 * @param mixed $pattern
 * @return string
 */
public function compileVarTag($tagStr,string $pattern = '/([%|\-|+|*|\/|\(|\)])/'): string
	{
		$viewVars = &$this->view->getViewVars();
		$foreachParameter = &$this->view->getForeachParameter();

		static $cache = [];
		// 同一变量在普通作用域与不同层级 foreach 中会编译成不同路径，缓存键必须包含当前循环上下文。
		$cacheKey = md5($tagStr . $pattern . '|' . serialize($foreachParameter));
      
		// 缓存编译结果，避免重复编译
		if (isset($cache[$cacheKey])) {
			return $cache[$cacheKey];
		}

		if(empty($tagStr)) return '';
		$result = '';
		$chars = preg_split($pattern,$tagStr, -1, PREG_SPLIT_DELIM_CAPTURE);//运算符
		$parameter = $foreachParameter;
		foreach($chars as $tagStr){
			if($tagStr!='' && $tagStr[0] == '$'){
				$_var = [];
				if(stripos($tagStr, '\'')){
					return $tagStr;
				}else{
					$tagStr = str_replace('$','',$tagStr);
					$_var = explode('.',$tagStr);
				}
				
				if(isset($parameter['rule']) && isset($parameter['finger'])){
					$var_block = $parameter['rule'][$parameter['finger']]['block'] ?? '';
					$var_name = $parameter['rule'][$parameter['finger']]['name'] ?? '';
					$var_key = $parameter['rule'][$parameter['finger']]['key'] ?? '';
					$names = $parameter['names'] ?? [];
					$group = $parameter['finger'] ?? '';
				}else{
					$var_block = $var_name = $var_key = $group = '';
					$names = [];
				}
				
				if(count($_var)==1){
					//兼容foreach变量
					if($var_block && ($var_name == $tagStr || $var_key == $tagStr || in_array($tagStr, $names))){
						if($var_name == $tagStr){
							if(isset($viewVars['foreach'][$group]['$var_name'])){
								$viewVars['foreach'][$group]['$var_name'] = '';
							} 
							$result .= "\$viewVars['foreach'][$group]['$var_name']";
						}elseif($var_key == $tagStr){
							if(isset($viewVars['foreach'][$group]['$var_key'])){
								$viewVars['foreach'][$group]['$var_key'] = '';
							} 
							$result .= "\$viewVars['foreach'][$group]['$var_key']";
						}else{
							$list = array_flip($names);
							$g = $list[$tagStr];
							if(isset($viewVars['foreach'][$g]['$tagStr'])){
								$viewVars['foreach'][$g]['$tagStr'] = '';
							} 
							$result .= "\$viewVars['foreach'][$g]['$tagStr']";
						}
					}else{
						if(isset($viewVars['$tagStr'])){
							$viewVars['$tagStr'] = '';
						} 
						$result .= "\$viewVars['$tagStr']";
					}
				}elseif($var_block && ($_var[0] == $var_name || in_array($_var[0], $names))){
					if($_var[0] == $var_name){
						$result .= "\$viewVars['foreach'][$group]['$var_name']";
					}else{
						$list = array_flip($names);
						$g = $list[$_var[0]];
						$result .= "\$viewVars['foreach'][$g]['".$_var[0]."']";
					}
					
					for($i=1;$i<count($_var);$i++){
						$result .= "['$_var[$i]']";
					}
				}elseif(in_array($_var[0], ['request','server','get','post','session'])){
					$__var = '';
					for($i=1;$i<count($_var);$i++){
						$__var .= "['$_var[$i]']";
					}
					$result .= '$_'. strtoupper($_var[0]) . $__var;
				}elseif(in_array($_var[0], ['define','const'])){
					$result .= $_var[1];
				}else{
					$result .= "\$viewVars";
					foreach($_var as $key ){
						$result .= "['$key']";
					}
				}
			}else{
				$result .= $tagStr;
			}
		}
		return $result;
	}

/**
 * Compile Variable Tag
 * @param mixed $templateTag
 * @return string
 */
public function compileVariableTag(string $templateTag): string
	{
		$return = false;
		$parts = $this->splitVarAndModifiers($templateTag);
		if(stripos($parts['var'], '_func_fix') != false){
			$parts['var'] = str_replace('_func_fix.', '', $parts['var']);
			$return = true;
		}
		$result = $this->compileVarTag($parts['var']);
		$_output = '';
		$raw = false;
		foreach ($parts['modifiers'] as $modifier) {
			if ($modifier['name'] === 'raw') {
				$raw = true;
				continue;
			}
			$args = $modifier['args'] !== '' ? ', ' . $modifier['args'] : '';
			if ($modifier['name'] === 'linkage') {
				$args = ($modifier['args'] !== '' ? ', ' . $modifier['args'] : '') . ', $viewVars';
			} elseif ($modifier['name'] === 'default' && $modifier['args'] === '') {
				$args = ', ""';
			}
			$_output .= "\$_tmp = \$this->viewModifier(\"{$modifier['name']}\", \$_tmp$args);";
		}
		$chars = preg_split('/([%|-|+|*|\/|\(|\)])/',$result, -1, PREG_SPLIT_DELIM_CAPTURE);//运算符
		if($_output == ''){
			if($return){
				return "$result";
			}else{
				$_result = '';
				foreach($chars as $tagStr){
					if($tagStr[0] == '$'){
						$_result .= "isset($tagStr) OR $tagStr = '';";
					}
				}
				return "<?php $_result echo $result; ?>";
			}
		}else{
			$output ='<?php';
			foreach($chars as $tagStr){
				if($tagStr[0] == '$'){
					$output .= " isset($tagStr) OR $tagStr = '';";
				}
			}
			$output .= "\$_tmp = $result;";
			$output .= $_output . ($raw ? "echo \$_tmp; \$_tmp = null; ?>" : "echo htmlentities(\$_tmp); \$_tmp = null; ?>");
			return $output;
		}
	}

/**
 * Compile Token Tag
 * @param mixed $tagCommand
 * @param mixed $nocache
 * @return string
 */
public function compileTokenTag(string $tagCommand,bool $nocache): string
	{
		$tagCommand = strtolower($tagCommand);
		if(!$nocache){
			switch($tagCommand){
				case '_token_':
				case '__token__':
					return $tagCommand == '__token__' ? '<?php takeToken(); ?>' : '<?php takeToken(false); ?>';
				case '_csrf_':
				case '__csrf__':
					return $tagCommand == '__csrf__' ? '<?php takeToken(true, "csrf"); ?>' : '<?php takeToken(false, "csrf"); ?>';
				default :
					return '';
			}
		}else{
			return $this->view->getLeftDelimiter() . $tagCommand . $this->view->getRightDelimiter();
		}
	}

/**
	 * 编译 if / elseif 条件标签
	 *
	 * 支持条件中的变量修饰器，例如：
	 * <{if $paramForm.http_method|default:'GET' == 'POST'}>
	 *
	 * @param string $tagCommand 标签名（if、elseif、else、/if）
	 * @param string $templateTag 标签原始内容（不含定界符）
	 * @return string 编译后的 PHP 片段
	 */
	public function compileIfTag(string $tagCommand, string $templateTag): string
	{
		if($tagCommand == 'if' || $tagCommand == 'elseif'){
			$condition = preg_replace('/^' . preg_quote($tagCommand, '/') . '\s*/', '', $templateTag, 1);
			$compiled = $this->compileConditionExpression($condition);
			// if：前缀写在关键字前即可
			if ($tagCommand === 'if') {
				$this->ifExtraEndifStack[] = 0;
				return '<?php ' . $compiled['prefix'] . 'if(' . $compiled['expression'] . '): ?>';
			}
			// elseif：不能把赋值前缀塞进 elseif 之前（会落入上一分支被跳过），
			// 也不能用逗号表达式（与 OR 组合会语法错误）。改为 else + 内层 if，并在 /if 时多补 endif。
			$depth = count($this->ifExtraEndifStack);
			if ($depth > 0) {
				$this->ifExtraEndifStack[$depth - 1]++;
			} else {
				// 容错：缺少配对 if 时仍产出可运行片段
				$this->ifExtraEndifStack[] = 1;
			}
			return '<?php else: ?><?php ' . $compiled['prefix'] . 'if(' . $compiled['expression'] . '): ?>';
		}elseif($tagCommand == 'else' ){
			return '<?php else: ?>';
		}elseif($tagCommand == '/if' ){
			$extra = $this->ifExtraEndifStack === [] ? 0 : (int)array_pop($this->ifExtraEndifStack);
			return '<?php ' . str_repeat('endif; ', $extra + 1) . '?>';
		}
		return '';
	}

	/**
	 * 编译条件表达式中的变量与修饰器
	 *
	 * 将 $var.path|default:'x' 等形式转为可参与比较的 PHP 临时变量
	 * 同一表达式在条件内多次出现只编译一次
	 *
	 * @param string $condition 去掉 if/elseif 前缀后的条件字符串
	 * @return array{prefix: string, expression: string} prefix 为赋值语句，expression 为 if 括号内表达式
	 */
	private function compileConditionExpression(string $condition): array
	{
		$prefix = '';
		$expression = $condition;
		$varIndex = 0;
		$compiledExprs = [];

		preg_match_all($this->getConditionVarPattern(), $condition, $matches);
		$expressions = $matches[0] ?? [];
		usort($expressions, static function (string $a, string $b): int {
			return strlen($b) - strlen($a);
		});

		foreach ($expressions as $expr) {
			if (isset($compiledExprs[$expr])) {
				continue;
			}

			$placeholder = '__tplIfVar' . $varIndex;
			$compiledExprs[$expr] = '$' . $placeholder;
			$parts = $this->splitVarAndModifiers($expr);
			$compiledVar = $this->compileVarTag($parts['var']);
			$prefix .= "isset($compiledVar) OR $compiledVar=''; \$$placeholder = $compiledVar;";
			$prefix .= $this->buildModifierPhp('$' . $placeholder, $parts['modifiers'], false);
			$expression = str_replace($expr, '$' . $placeholder, $expression);
			$varIndex++;
		}

		return ['prefix' => $prefix, 'expression' => $expression];
	}

	/**
	 * 匹配条件中的模板变量表达式（含可选修饰器链）
	 *
	 * @return string PCRE 正则
	 */
	private function getConditionVarPattern(): string
	{
		return '/\$[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*(?:\|[a-zA-Z_][a-zA-Z0-9_]*(?::(?:\'[^\']*\'|"[^"]*"|\$[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*|\[[^\]]*\]|true|false|null|-?[0-9]+(?:\.[0-9]+)?))?)*/';
	}

	/**
	 * 解析标签属性（name=value）
	 *
	 * 支持：
	 * - 引号字面量：file='Public/foo'
	 * - 含修饰器的未加引号变量：dictSelectValue=$get.status|default:''
	 *
	 * @param string $templateTag 标签内容（含命令名，如 include file='...'）
	 * @return array<string, string> 属性名 => 原始值字符串
	 */
	private function parseTagAttributes(string $templateTag): array
	{
		$body = preg_replace('/^\s*\w+\s+/', '', trim($templateTag), 1) ?? '';
		if ($body === '') {
			return [];
		}

		$attrs = [];
		$offset = 0;
		$len = strlen($body);

		while ($offset < $len) {
			if (preg_match('/\G\s+/A', $body, $m, 0, $offset)) {
				$offset += strlen($m[0]);
				continue;
			}
			if (!preg_match('/\G([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*/A', $body, $m, 0, $offset)) {
				break;
			}
			$name = $m[1];
			$offset += strlen($m[0]);
			if ($offset >= $len) {
				break;
			}

			$quote = $body[$offset];
			if ($quote === '\'' || $quote === '"') {
				$end = $offset + 1;
				while ($end < $len) {
					if ($body[$end] === '\\') {
						$end += 2;
						continue;
					}
					if ($body[$end] === $quote) {
						break;
					}
					$end++;
				}
				$attrs[$name] = $quote . substr($body, $offset + 1, max(0, $end - $offset - 1)) . $quote;
				$offset = min($len, $end + 1);
				continue;
			}

			if (!preg_match('/\G(.+?)(?=\s+[a-zA-Z_][a-zA-Z0-9_]*\s*=|$)/As', $body, $m, 0, $offset)) {
				break;
			}
			$attrs[$name] = trim($m[1]);
			$offset += strlen($m[0]);
		}

		return $attrs;
	}

	/**
	 * 将模板表达式编译为 PHP 值表达式（供 include 等标签属性使用）
	 *
	 * @param string $expr 原始表达式（字面量、$var 或 $var|modifier:arg）
	 * @param string $prefix 前置 PHP 语句（输出参数，用于修饰器临时变量）
	 * @param int $tmpIndex 临时变量序号（引用传递自增）
	 * @return string 可嵌入数组字面量的 PHP 表达式
	 */
	private function compileTemplateValueExpr(string $expr, string &$prefix, int &$tmpIndex): string
	{
		$expr = trim($expr);
		if ($expr === '') {
			return "''";
		}

		if (preg_match('/^([\'"])(.*)\1$/s', $expr, $quoted)) {
			return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $quoted[2]) . '"';
		}

		if ($expr[0] !== '$') {
			return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $expr) . '"';
		}

		$parts = $this->splitVarAndModifiers($expr);
		$compiledVar = $this->compileVarTag($parts['var']);
		if ($parts['modifiers'] === []) {
			$prefix .= "isset($compiledVar) OR $compiledVar=''; ";
			return $compiledVar;
		}

		$tmp = '__tplAttr' . $tmpIndex++;
		$prefix .= "isset($compiledVar) OR $compiledVar=''; \$$tmp = $compiledVar;";
		$prefix .= $this->buildModifierPhp('$' . $tmp, $parts['modifiers'], false);

		return '$' . $tmp;
	}

	/**
	 * 拆分模板变量表达式为基础变量与修饰器链
	 *
	 * @param string $expr 如 $a.b|default:'x'|escape
	 * @return array{var: string, modifiers: list<array{name: string, args: string}>}
	 */
	private function splitVarAndModifiers(string $expr): array
	{
		$cline = '&*&%$##';
		$aline = '&@@#$&&';
		$expr = trim($expr);
		$varPart = explode('|', $expr)[0];

		$_res = str_replace('\|', $cline, $expr);
		preg_match_all('/(\|)(([a-zA-Z0-9_])+)(([:])?)/', $_res, $res);
		$resTags = $res[0] ?? [];
		if ($resTags === []) {
			return ['var' => $varPart, 'modifiers' => []];
		}

		foreach ($resTags as $key) {
			$_res = str_replace($key, $aline, $_res);
		}

		$argSegments = [];
		foreach (explode($aline, $_res) as $segment) {
			$argSegments[] = explode(':', str_replace($cline, '|', $segment));
		}

		$modifiers = [];
		for ($i = 0; $i < count($resTags); $i++) {
			$name = str_replace(['|', ':'], '', $resTags[$i]);
			$args = '';
			if (isset($argSegments[$i + 1])) {
				$compiledArgs = [];
				foreach ($argSegments[$i + 1] as $arg) {
					$compiledArgs[] = $this->compileModifierArg(trim($arg));
				}
				$args = implode(', ', $compiledArgs);
			}
			$modifiers[] = ['name' => $name, 'args' => $args];
		}

		return ['var' => $varPart, 'modifiers' => $modifiers];
	}

	/**
	 * 生成修饰器链对应的 PHP 赋值语句
	 *
	 * @param string $targetExpr 目标变量表达式（如 $_tmp 或 $__tplIfVar0）
	 * @param array<int, array{name: string, args: string}> $modifiers 修饰器列表
	 * @param bool $skipOutputOnly 为 true 时跳过仅影响输出的 raw 修饰器
	 * @return string
	 */
	private function buildModifierPhp(string $targetExpr, array $modifiers, bool $skipOutputOnly = true): string
	{
		$output = '';
		foreach ($modifiers as $modifier) {
			if ($skipOutputOnly && $modifier['name'] === 'raw') {
				continue;
			}

			$args = $modifier['args'] !== '' ? ', ' . $modifier['args'] : '';
			if ($modifier['name'] === 'linkage') {
				$args = ($modifier['args'] !== '' ? ', ' . $modifier['args'] : '') . ', $viewVars';
			} elseif ($modifier['name'] === 'default' && $modifier['args'] === '') {
				$args = ', ""';
			}
			$output .= "$targetExpr = \$this->viewModifier(\"{$modifier['name']}\", $targetExpr$args);";
		}

		return $output;
	}

	/**
	 * 编译修饰器单个参数为 PHP 表达式
	 *
	 * 支持引号字面量（含空字符串 ''）、变量与其它字面量
	 *
	 * @param string $arg 修饰器参数字符串
	 * @return string 可嵌入 viewModifier 调用的 PHP 片段
	 */
	private function compileModifierArg(string $arg): string
	{
		if ($arg === '') {
			return '""';
		}

		if ($arg[0] === '$') {
			return $this->compileVarTag($arg);
		}

		// 支持 default:[] 等简单数组字面量，避免被当作字符串传入修饰器。
		if ($arg[0] === '[' && substr($arg, -1) === ']' && preg_match('/^\[[^\]]*\]$/s', $arg)) {
			return $arg;
		}

		if (preg_match('/^([\'"])(.*)\1$/s', $arg, $quoted)) {
			return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $quoted[2]) . '"';
		}

		if (is_numeric($arg)) {
			return '"' . $arg . '"';
		}

		$lower = strtolower($arg);
		if (in_array($lower, ['true', 'false', 'null'], true)) {
			return $lower;
		}

		$compiledArg = $this->compileVarTag($arg);
		if ($compiledArg !== '' && $compiledArg[0] === '$') {
			return $compiledArg;
		}

		return '"' . $compiledArg . '"';
	}

/**
 * Compile Define Tag
 * @param mixed $tagCommand
 * @param mixed $templateTag
 * @return string
 */
public function compileDefineTag(string $tagCommand,array $templateTag): string
	{
		if($tagCommand == 'define'){
			$name = $templateTag['name'];
			$value = $templateTag['value'];
			if($value[0] != '$'){
				return "<?php define('$name', '$value'); ?>";
			}else{
				$value = $this->compileVarTag($value);
				return "<?php define('$name', $value); ?>";
			}
		}if($tagCommand == 'defined' || $tagCommand == 'notdefine'){
			$name = $templateTag['name'];
			return $tagCommand == 'defined' ? "<?php if(defined($name)): ?>" : "<?php if(!defined($name)): ?>";
		}elseif($tagCommand == '/defined' || $tagCommand == '/notdefine'){
			return '<?php endif; ?>';
		}

		return '';
	}

	/**
	 * loop / volist 标签编译
	 *
	 * 语义等同 foreach，复用 compileForeachTag 生成循环体
	 * 支持两种属性写法：
	 * 1) Candy：<{volist from="$list" item="row" [key="k"]}>…<{/volist}>
	 * 2) ThinkPHP 兼容：<{volist name="list" id="row"}>…<{/volist}>
	 *    name 可为点路径，如 name="vo.attachments"
	 *
	 * @param string $tagCommand loop|volist|/loop|/volist
	 * @param array $parseVar 解析后的标签参数（from/item 或 name/id 等）
	 * @return string 编译后的 PHP 片段
	 */
	public function compileLoopTag(string $tagCommand, array $parseVar): string
	{
		if ($tagCommand === '/loop' || $tagCommand === '/volist') {
			return $this->compileForeachTag('/foreach', $parseVar);
		}

		if ($tagCommand !== 'loop' && $tagCommand !== 'volist') {
			return '';
		}

		$foreachVar = $parseVar;
		$usedNameAsSource = false;

		// ThinkPHP：name=数据源 → from；未写 from 时生效
		if (empty($foreachVar['from']) && isset($foreachVar['name']) && $foreachVar['name'] !== '' && $foreachVar['name'] !== null) {
			$usedNameAsSource = true;
			$name = (string) $foreachVar['name'];
			if ($name[0] === '$') {
				// 已是 PHP 变量表达式（含 $viewVars[...]）
				$foreachVar['from'] = $name;
			} else {
				// 普通标识或点路径：providers / vo.attachments
				$from = '$viewVars';
				foreach (explode('.', $name) as $part) {
					$part = trim($part, " \t\"'");
					if ($part === '') {
						continue;
					}
					if (!preg_match('~^\w+$~', $part)) {
						Debug::addMsg('<font color="red">volist/loop：name 路径段非法（' . htmlspecialchars($part) . '）。</font>', 4);
						return '';
					}
					$from .= "['" . $part . "']";
				}
				$foreachVar['from'] = $from;
			}
		}

		// ThinkPHP：id=循环项 → item
		if (empty($foreachVar['item']) && isset($foreachVar['id']) && $foreachVar['id'] !== '' && $foreachVar['id'] !== null) {
			$item = (string) $foreachVar['id'];
			if ($item !== '' && $item[0] === '$') {
				$item = ltrim($item, '$');
			}
			$foreachVar['item'] = $item;
		}

		unset($foreachVar['id']);
		// name 作数据源时必须去掉，否则会被 foreach 当成迭代属性名（$this->_foreach[name]）
		if ($usedNameAsSource) {
			unset($foreachVar['name']);
		}

		if (empty($foreachVar['from'])) {
			showExceptionMsg('shutdown', '模版' . $tagCommand . '标签from/name变量没有声明。');
		}
		if (empty($foreachVar['item'])) {
			showExceptionMsg('shutdown', '模版' . $tagCommand . '标签item/id变量没有声明。');
		}

		return $this->compileForeachTag('foreach', $foreachVar);
	}

/**
 * Compile Empty Tag
 * @param mixed $tagCommand
 * @param mixed $templateTag
 * @return string
 */
public function compileEmptyTag(string $tagCommand,array $templateTag): string
	{
		if($tagCommand == 'empty' || $tagCommand == 'notempty'){
			$_result = "";
			$result = "";
			if($templateTag['name'][0] != '$'){
				$templateTag['name'] = '$'.$templateTag['name'];
			}
			$_result = $this->compileVarTag($templateTag['name']);
			return $tagCommand == 'empty' ? "<?php if(empty($_result)): ?>" : "<?php if(isset($_result) && !empty($_result)): ?>";
		}elseif($tagCommand == '/empty' || $tagCommand == '/notempty'){
			return '<?php endif; ?>';
		}

		return '';
	}

/**
 * Compile Eq Tag
 * @param mixed $tagCommand
 * @param mixed $templateTag
 * @param mixed $parseVar
 * @return string
 */
public function compileEqTag(string $tagCommand,string $templateTag,array $parseVar): string
	{
		if($tagCommand == 'eq'){
			// 处理name和value参数形式
			if(isset($parseVar['name']) && isset($parseVar['value'])){
				$name = $parseVar['name'];
				$value = $parseVar['value'];
				
				// 编译变量
				if($name[0] != '$'){
					$name = '$'.$name;
				}
				$compiledName = $this->compileVarTag($name);
				
				// 处理值
				if($value[0] == '$'){
					$compiledValue = $this->compileVarTag($value);
					return "<?php if($compiledName == $compiledValue): ?>";
				}else{
					return "<?php if($compiledName == '$value'): ?>";
				}
			}else{
				// 处理简化形式 <{eq $var value}>
				$content = trim(str_replace('eq', '', $templateTag));
				$parts = explode(' ', $content);
				if(count($parts) >= 2){
					$name = $parts[0];
					$value = $parts[1];
					
					// 编译变量
					if($name[0] != '$'){
						$name = '$'.$name;
					}
					$compiledName = $this->compileVarTag($name);
					
					// 处理值
					if($value[0] == '$'){
						$compiledValue = $this->compileVarTag($value);
						return "<?php if($compiledName == $compiledValue): ?>";
					}else{
						return "<?php if($compiledName == '$value'): ?>";
					}
				}
			}
		}elseif($tagCommand == '/eq'){
			return '<?php endif; ?>';
		}

		return '';
	}

	/**
	 * 检测 foreach 标签是否包含显式属性赋值
	 *
	 * 仅匹配独立属性名加等号（如 from=、item=）
	 * 避免变量名中含 from/item 子串（如 $classGroupItem）被误判为属性模式
	 *
	 * @param string $templateTag 原始标签内容（不含定界符）
	 * @param string $attr 属性名（from 或 item）
	 * @return bool
	 */
	private function hasForeachAttr(string $templateTag, string $attr): bool
	{
		return (bool)preg_match(
			'~(?:^|\s)' . preg_quote($attr, '~') . '\s*=~i',
			$templateTag
		);
	}

	/**
	 * 编译 foreach as 模式的来源表达式。
	 *
	 * 标签属性语法历史上使用等号分隔修饰器参数，因此同时兼容
	 * |default=[] 与变量输出标签中的 |default:[] 两种写法。
	 *
	 * @param string $source 如 $rows|default=[]
	 * @return string 可直接嵌入 PHP foreach 初始化逻辑的表达式
	 */
	private function compileForeachSourceExpression(string $source): string
	{
		$source = trim($source);
		if ($source === '') {
			return '[]';
		}

		// foreach 标签的来源表达式使用 = 传递修饰器参数；统一为修饰器解析器的 : 形式。
		$normalized = preg_replace('/\|([a-zA-Z_][a-zA-Z0-9_]*)\s*=/', '|$1:', $source) ?? $source;
		$parts = $this->splitVarAndModifiers($normalized);
		$value = $this->compileVarTag($parts['var']);
		if ($parts['modifiers'] === []) {
			return $value;
		}

		// 修饰器调用前必须避免未分配模板变量的数组下标警告。
		$value = '(isset(' . $value . ') ? ' . $value . ' : null)';
		foreach ($parts['modifiers'] as $modifier) {
			$args = $modifier['args'] !== '' ? ', ' . $modifier['args'] : '';
			if ($modifier['name'] === 'linkage') {
				$args = ($modifier['args'] !== '' ? ', ' . $modifier['args'] : '') . ', $viewVars';
			} elseif ($modifier['name'] === 'default' && $modifier['args'] === '') {
				$args = ', ""';
			}
			$value = '$this->viewModifier("' . $modifier['name'] . '", ' . $value . $args . ')';
		}

		return $value;
	}

/**
 * Compile Foreach Tag
 * @param mixed $tagCommand
 * @param mixed $parseVar
 * @return string
 */
public function compileForeachTag(string $tagCommand,array $parseVar): string
	{
		$viewVars = &$this->view->getViewVars();
		$foreachParameter = &$this->view->getForeachParameter();

		$parameter = $foreachParameter;
		if($tagCommand == 'foreach'){
			if($parameter['finger'] > 0){
				$parameter['finger']++;
			}else{
				$parameter['finger']=1;
			}
			$parameter['rule'][$parameter['finger']]['block'] = true;
		}
		$group = $parameter['finger'];
		if($tagCommand == 'foreachelse' || $tagCommand == '/foreach'){
			if($tagCommand == 'foreachelse'){
				$parameter['rule'][$parameter['finger']]['else'] = true;
			}
			$parameter['rule'][$parameter['finger']]['block'] = false;
			$parameter['rule'][$parameter['finger']]['name'] = '';
			$parameter['names'][$parameter['finger']] = '';
		}
		if($tagCommand == 'foreach' ){
			if(empty($parseVar['from'])){
				showExceptionMsg('shutdown', '模版foreach标签from变量没有声明。');
			}
			$from = $parseVar['from'];
			
			if(empty($parseVar['item'])){
				showExceptionMsg('shutdown', '模版foreach标签item变量没有声明。');
			}
			$item = $parseVar['item'];
			$parameter['rule'][$parameter['finger']]['name'] = $item;
			$parameter['names'][$parameter['finger']] = $item;
			if(isset($parseVar['key'])){
				$key  = $parseVar['key'];
				if(!preg_match('~^\w+$~', $key)){
					Debug::addMsg('<font color="red">foreach：key必须是变量名（文字字符串）。</font>', 4);
					return '';
				}
				$parameter['rule'][$parameter['finger']]['key'] = $key;
				$key_part = "\$viewVars['foreach'][$group]['$key'] => ";
			} else {
				$key = null;
				$key_part ="\$viewVars['foreach'][$group]['key'] => ";
			}
			
			$result = '<?php ';

			// 来源可能是变量、数组字面量或带修饰器的表达式。统一初始化循环临时数组，
			// 绝不回写来源表达式（例如 $rows|default=[] 编译后是函数调用，不能赋值）。
			$from = trim($from);
			if ($from[0] === '[' && $from[strlen($from) - 1] === ']') {
				$from = $this->compileVarTag($from, '/(\[|\]|,|=>|=)/');
			}
			$result .= " \$_from$group = ($from) ?? []; \n is_array(\$_from$group) OR settype(\$_from$group, 'array'); \n";
			
			$name = $parseVar['name'];
			if(isset($name)){
				$foreach_props = "\$this->_foreach[$name]";
				$result .= "{$foreach_props} = ['total' => count(\$_from$group), 'iteration' => 0];\n";
				$result .= "if({$foreach_props}['total'] > 0):\n ";
				$result .= "    foreach(\$_from$group as $key_part\$viewVars['foreach'][$group]['$item']):\n";
				$result .= "        {$foreach_props}['iteration']++;\n";
			} else {
				$result .= "if(count(\$_from$group)>0):\n";
				$result .= "    foreach(\$_from$group as $key_part \$viewVars['foreach'][$group]['$item']):\n";
				
			}
			$result .= '?>';
			$foreachParameter = $parameter;
			return $result;
		}else if($tagCommand == 'foreachelse'){
			$foreachParameter = $parameter;
			return "<?php endforeach; unset(\$_from$group); unset(\$viewVars['foreach'][$group]); else: ?>";
		}else if($tagCommand == '/foreach'){
			$closingGroup = $parameter['finger'];
			$else = (bool)($parameter['rule'][$closingGroup]['else'] ?? false);
			unset($parameter['rule'][$closingGroup], $parameter['names'][$closingGroup]);
			$parameter['finger'] = max(0, $closingGroup - 1);
			$foreachParameter = $parameter;
			if($else){
				return '<?php endif; ?>';
			}else{
				return "<?php endforeach; endif; unset(\$_from$group); unset(\$viewVars['foreach'][$group]); ?>";
			}
		}
		return '';
	}

/**
 * Compile Include Tag
 * @param mixed $tagCommand
 * @param mixed $parseVar
 * @return string
 */
public function compileIncludeTag(string $tagCommand, array $parseVar): string
	{
		if (empty($parseVar['file'])) {
			Debug::addMsg('<font color="red">include：file变量没有声明。</font>', 4);
			return '';
		}

		// 捕获当前 foreach 上下文，将 item/key 变量值注入 vars 参数，
		// 使子模板可直接通过 {$vo} 访问，不依赖父模板的 foreach 组号。
		$foreachParameter = $this->view->getForeachParameter();
		$finger = $foreachParameter['finger'] ?? 0;
		if ($finger > 0) {
			$rule = $foreachParameter['rule'][$finger] ?? [];
			$item = $rule['name'] ?? '';
			$keyName = $rule['key'] ?? '';
			if ($item !== '' && !isset($parseVar[$item])) {
				$parseVar[$item] = '$' . $item;
			}
			if ($keyName !== '' && !isset($parseVar[$keyName])) {
				$parseVar[$keyName] = '$' . $keyName;
			}
		}

		$prefix = '';
		$tmpIndex = 0;
		$fileExpr = $this->compileTemplateValueExpr((string) $parseVar['file'], $prefix, $tmpIndex);

		$arg_list = [];
		foreach ($parseVar as $key => $value) {
			if ($key === 'file') {
				continue;
			}
			$compiled = $this->compileTemplateValueExpr((string) $value, $prefix, $tmpIndex);
			$arg_list[] = "'" . $key . "' => " . $compiled;
		}

		$vars = implode(',', $arg_list);
		$includePhp = "\$this->viewInclude(['file' => $fileExpr, 'vars' => [" . $vars . "]]);";

		if ($prefix !== '') {
			return '<?php ' . $prefix . ' ' . $includePhp . ' ?>';
		}

		return '<?php ' . $includePhp . ' ?>';
	}

/**
 * Compile Import Tag
 * @param mixed $tagCommand
 * @param mixed $parseVar
 * @return string
 */
public function compileImportTag(string $tagCommand,array $parseVar): string
	{
		if(empty($parseVar['type'])){
			Debug::addMsg('<font color="red">import：type变量没有声明。</font>', 4);
			return '';
		}
		
		$basepath = $parseVar['basepath'] ?? '/Public/';
		$file = $parseVar['file'] ?? $parseVar['href'];
		$file = str_replace('.', '/', $file);
		switch($parseVar['type']){
			case 'css':
				return '<link rel="stylesheet" type="text/css" href="'. $basepath . $file .'.css" />';
			case 'js';
				return '<script type="text/javascript" src="'. $basepath . $file .'.js"></script>';
			default :
				$other = '';
				isset($parseVar['width']) && $other .= ' width="'. $parseVar['width'] .'"';
				isset($parseVar['height']) && $other .= ' height="'. $parseVar['height'] .'"';
				isset($parseVar['alt']) && $other .= ' alt="'. $parseVar['alt'] .'"';
				isset($parseVar['title']) && $other .= ' title="'. $parseVar['title'] .'"';
				return '<img src="'. $basepath . $file .'.'. $parseVar['type'] .'"'. $other .' />';
		}
	}

/**
 * Compile Parse Var
 * @param mixed $tagStr
 * @return mixed
 */
public function compileParseVar($tagStr): mixed
	{
		$parseVar = [];
		if(is_array($tagStr)){
			foreach($tagStr as $key => $value){
				if($value == '='){
					$parseVar[$tagStr[$key-1]] = $this->compileVarTag($tagStr[$key+1]);
				}
			}
			return $parseVar;		
		}else{
			return $tagStr;
		}
	}

/**
 * System Frametion
 * @param mixed $_func
 * @param mixed $parseVar
 * @return string
 */
public function systemFrametion(string $_func,array $parseVar): string
	{
		if(function_exists($_func)){
			$vars = [];
			if(count($parseVar) > 0){
				foreach($parseVar as $k=>$v){
					$var = $v!='' ? ($v[0] == '$' ? $v : '\''.$v.'\'') : '\'\'';
					$vars[] .= '\''. $k .'\'=>'. $var;
				}
			}
			
			if(in_array(strtolower($_func), ['assign'])){
				//需要授权模板变量的
				return "<?php \$_var = [".implode(',', (array)$vars)."]; $_func(\$_var, \$viewVars); unset(\$_var); ?>";
			}else{
				return "<?php \$_var = [".implode(',', (array)$vars)."]; $_func(\$_var); unset(\$_var); ?>";
			}
		}else{
			return '';
		}
	}

}
