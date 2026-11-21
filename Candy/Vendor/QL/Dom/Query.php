<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 2017/9/21
 */

namespace QL\Dom;

use \QL\QueryList;
use \QL\phpQuery;
use \QL\phpQuery\phpQueryObject;
use \Extend\Collection;

/**
 * Query类
 * QueryList的核心查询类，负责HTML处理、规则解析和数据提取
 */
class Query
{
    /**
     * 原始HTML内容
     * @var string
     */
    protected $html;
    
    /**
     * phpQuery文档对象
     * @var \phpQueryObject
     */
    protected $document;
    
    /**
     * 爬取规则
     * @var array
     */
    protected $rules;
    
    /**
     * 列表切片选择器
     * @var null|string
     */
    protected $range = null;
    
    /**
     * QueryList实例
     * @var QueryList
     */
    protected $ql;
    
    /**
     * 爬取结果数据
     * @var Collection
     */
    protected $data;

    /**
     * Query构造函数
     *
     * @param QueryList $ql QueryList实例
     */
    public function __construct(QueryList $ql)
    {
        $this->ql = $ql;
    }

    /**
     * 获取HTML内容
     *
     * @param bool $rel 是否返回处理后的HTML，默认为true
     * @return string
     */
    public function getHtml($rel = true)
    {
        return $rel ? $this->document->htmlOuter() : $this->html;
    }

    /**
     * 设置HTML内容
     *
     * @param string $html HTML内容
     * @param null $charset 字符集
     * @return QueryList
     */
    public function setHtml($html, $charset = null)
    {
        $this->html = $html;
        $this->destroyDocument();
        $this->document = phpQuery::newDocumentHTML($this->html, $charset);
        return $this->ql;
    }

    /**
     * 获取爬取结果
     *
     * @param \Closure|null $callback 回调函数，用于处理爬取结果
     * @return Collection|static
     */
    public function getData(?\Closure $callback = null)
    {
        return $this->handleData($this->data, $callback);
    }

    /**
     * 设置爬取结果数据
     *
     * @param \Candy\Extend\Collection $data 爬取结果数据
     */
    public function setData(\Candy\Extend\Collection $data)
    {
        $this->data = $data;
    }

    /**
     * 查找匹配指定选择器的所有元素
     *
     * @param string $selector CSS选择器
     * @return Elements 元素集合对象
     */
    public function find($selector)
    {
        return (new Dom($this->document))->find($selector);
    }

    /**
     * 设置爬取规则
     *
     * $rules = [
     *    'rule_name1' => ['selector','HTML attribute | text | html','Tag filter list','callback'],
     *    'rule_name2' => ['selector','HTML attribute | text | html','Tag filter list','callback'],
     *    // ...
     *  ]
     *
     * @param array $rules 爬取规则数组
     * @return QueryList
     */
    public function rules(array $rules)
    {
        $this->rules = $rules;
        return $this->ql;
    }

    /**
     * 设置列表切片区域
     *
     * @param string $selector CSS选择器，用于指定列表区域
     * @return QueryList
     */
    public function range($selector)
    {
        $this->range = $selector;
        return $this->ql;
    }

    /**
     * 移除HTML头部，尝试解决乱码问题
     *
     * @return QueryList
     */
    public function removeHead()
    {
        $html = preg_replace('/(<head>|<head\s+.+?>).+?<\/head>/is', '<head></head>', $this->html);
        $html && $this->setHtml($html);
        return $this->ql;
    }

    /**
     * 执行查询规则
     *
     * @param \Closure|null $callback 回调函数，用于处理爬取结果
     * @return QueryList
     */
    public function query(\Closure $callback = null)
    {
        $this->data = $this->getList();
        $this->data = $this->handleData($this->data, $callback);
        return $this->ql;
    }

    /**
     * 处理爬取数据
     *
     * @param \Candy\Extend\Collection $data 爬取数据
     * @param callable|null $callback 回调函数
     * @return \Candy\Extend\Collection
     */
    public function handleData(\Candy\Extend\Collection $data, $callback)
    {
        if (is_callable($callback)) {
            if (empty($this->range)) {
                $data = new \Candy\Extend\Collection($callback($data->all(), null));
            } else {
                $data = $data->map($callback);
            }
        }

        return $data;
    }

    /**
     * 获取爬取列表数据
     *
     * @return \Candy\Extend\Collection
     */
    protected function getList()
    {
        $data = [];
        if (empty($this->range)) {
            // 无列表区域，直接提取数据
            foreach ($this->rules as $key => $reg_value) {
                $rule = $this->parseRule($reg_value);
                $contentElements = $this->document->find($rule['selector']);
                $data[$key] = $this->extractContent($contentElements, $key, $rule);
            }
        } else {
            // 有列表区域，先获取列表项，再逐个提取数据
            $rangeElements = $this->document->find($this->range);
            $i = 0;
            foreach ($rangeElements as $element) {
                foreach ($this->rules as $key => $reg_value) {
                    $rule = $this->parseRule($reg_value);
                    $contentElements = (call_user_func_array(['QL\phpQuery', 'pq'], [$element]))->find($rule['selector']);
                    $data[$i][$key] = $this->extractContent($contentElements, $key, $rule);
                }
                $i++;
            }
        }

        return new \Candy\Extend\Collection($data);
    }

    /**
     * 提取元素内容
     *
     * @param phpQueryObject $pqObj phpQuery对象
     * @param string $ruleName 规则名称
     * @param array $rule 解析后的规则
     * @return mixed
     */
    protected function extractContent(phpQueryObject $pqObj, $ruleName, $rule)
    {
        switch ($rule['attr']) {
            case 'text':
                // 提取文本内容，保留指定标签
                $content = $this->allowTags($pqObj->html(), $rule['filter_tags']);
                break;
            case 'texts':
                // 提取多个元素的文本内容，保留指定标签
                $content = (new Elements($pqObj))->map(function (Elements $element) use ($rule) {
                    return $this->allowTags($element->html(), $rule['filter_tags']);
                })->all();
                break;
            case 'html':
                // 提取HTML内容，去除指定标签
                $content = $this->stripTags($pqObj->html(), $rule['filter_tags']);
                break;
            case 'htmls':
                // 提取多个元素的HTML内容，去除指定标签
                $content = (new Elements($pqObj))->map(function (Elements $element) use ($rule) {
                    return $this->stripTags($element->html(), $rule['filter_tags']);
                })->all();
                break;
            case 'htmlOuter':
                // 提取外部HTML内容，去除指定标签
                $content = $this->stripTags($pqObj->htmlOuter(), $rule['filter_tags']);
                break;
            case 'htmlOuters':
                // 提取多个元素的外部HTML内容，去除指定标签
                $content = (new Elements($pqObj))->map(function (Elements $element) use ($rule) {
                    return $this->stripTags($element->htmlOuter(), $rule['filter_tags']);
                })->all();
                break;
            default:
                // 提取属性值
                if(preg_match('/attr\((.+)\)/', $rule['attr'], $arr)) {
                    $content = $pqObj->attr($arr[1]);
                } elseif (preg_match('/attrs\((.+)\)/', $rule['attr'], $arr)) {
                    $content = (new Elements($pqObj))->attrs($arr[1])->all();
                } else {
                    $content = $pqObj->attr($rule['attr']);
                }
                break;
        }

        // 执行回调函数处理内容
        if (is_callable($rule['handle_callback'])) {
            $content = call_user_func($rule['handle_callback'], $content, $ruleName);
        }

        return $content;
    }

    /**
     * 解析爬取规则
     *
     * @param array $rule 原始规则
     * @return array 解析后的规则
     */
    protected function parseRule($rule)
    {
        $result = [];
        $result['selector'] = $rule[0];
        $result['attr'] = $rule[1];
        $result['filter_tags'] = $rule[2] ?? '';
        $result['handle_callback'] = $rule[3] ?? null;

        return $result;
    }

    /**
     * 去除特定的HTML标签
     *
     * @param string $html HTML内容
     * @param string $tags_str 多个标签名之间用空格隔开，带-前缀表示移除标签内容
     * @return string
     */
    protected function stripTags($html, $tags_str)
    {
        $tagsArr = $this->tag($tags_str);
        $html = $this->removeTags($html, $tagsArr[1]);
        $p = [];
        foreach ($tagsArr[0] as $tag) {
            $p[] = "/(<(?:\/" . $tag . "|" . $tag . ")[^>]*>)/i";
        }
        $html = preg_replace($p, "", trim($html));
        return $html;
    }

    /**
     * 保留特定的HTML标签
     *
     * @param string $html HTML内容
     * @param string $tags_str 多个标签名之间用空格隔开，带-前缀表示移除标签内容
     * @return string
     */
    protected function allowTags($html, $tags_str)
    {
        $tagsArr = $this->tag($tags_str);
        $html = $this->removeTags($html, $tagsArr[1]);
        $allow = '';
        foreach ($tagsArr[0] as $tag) {
            $allow .= "<$tag> ";
        }
        return strip_tags(trim($html), $allow);
    }

    /**
     * 解析标签字符串
     *
     * @param string $tags_str 标签字符串
     * @return array [允许的标签数组, 需要移除的标签数组]
     */
    protected function tag($tags_str)
    {
        $tagArr = preg_split("/\s+/", $tags_str, -1, PREG_SPLIT_NO_EMPTY);
        $tags = [[], []];
        foreach ($tagArr as $tag) {
            if (preg_match('/-(.+)/', $tag, $arr)) {
                array_push($tags[1], $arr[1]);
            } else {
                array_push($tags[0], $tag);
            }
        }
        return $tags;
    }

    /**
     * 移除特定的HTML标签
     *
     * @param string $html HTML内容
     * @param array $tags 标签数组
     * @return string
     */
    protected function removeTags($html, $tags)
    {
        $tag_str = '';
        if (count($tags)) {
            foreach ($tags as $tag) {
                $tag_str .= $tag_str ? ',' . $tag : $tag;
            }
            $doc = phpQuery::newDocumentHTML($html);
            pq($doc)->find($tag_str)->remove();
            $html = pq($doc)->htmlOuter();
            $doc->unloadDocument();
        }
        return $html;
    }

    /**
     * 销毁文档对象
     *
     * @return void
     */
    protected function destroyDocument()
    {
        if ($this->document instanceof phpQueryObject) {
            $this->document->unloadDocument();
        }
    }

    /**
     * 析构函数，销毁文档对象
     */
    public function __destruct()
    {
        $this->destroyDocument();
    }
}
