<?php
declare(strict_types = 1);

namespace Apidoc\parses;

use \Candy\Extend\Attribute\utils\ApidocAttribute;
use \Candy\Extend\Attribute\utils\Docblock\DocblockReader;
use \Apidoc\utils\Helper;
use \Apidoc\exception\ErrorException;

class ParseAnnotation
{

    protected $parser;

    public function __construct($config)
    {
        $this->parser = new DocblockReader();
        if (!empty($config['ignored_annitation'])){
            foreach ($config['ignored_annitation'] as $item) {
                DocblockReader::addGlobalIgnoredName($item);
            }
        }
    }
    /**
     * 解析非@注解的文本注释
     * @param $refMethod
     * @param $isAll bool 是否获取全部，true则将带@开头的注释也包含
     * @return array|false
     */
    public static function parseTextAnnotation($refMethod,$isAll=false): array
    {
        $annotation = $refMethod->getDocComment();
        if (empty($annotation)) {
            return [];
        }
        if (preg_match('#^/\*\*(.*)\*/#s', $annotation, $comment) === false)
            return [];
        $comment = trim($comment [1]);
        if (preg_match_all('#^\s*\*(.*)#m', $comment, $lines) === false)
            return [];
        $data = [];
        foreach ($lines[1] as $line) {
            $line = trim($line);
            if (!empty ($line) && ($isAll===true || ($isAll===false && strpos($line, '@') !== 0))) {
                $data[] = $line;
            }
        }
        return $data;
    }

    /**
     * 根据路径获取类名
     * @param $path
     * @return string
     */
    protected function getClassName($path){
        $NameArr = explode("\\", $path);
        $name    = lcfirst($NameArr[count($NameArr) - 1]);
        return $name;
    }

    /**
     * 收集 PHP 8 Attribute（仅 Apidoc 相关，排除 Node 的 Annotation 等）
     *
     * @param \Reflector $reflector 类 / 方法 / 属性反射
     * @return list<\ReflectionAttribute>
     */
    protected function collectPhpAttributes($reflector): array
    {
        if (!method_exists($reflector, 'getAttributes')) {
            return [];
        }

        return array_values(array_filter(
            $reflector->getAttributes(),
            static fn (\ReflectionAttribute $attr): bool => ApidocAttribute::isApidocClass($attr->getName())
        ));
    }

    /**
     * 将注解实例或反射 Attribute 转为 Apidoc 参数值
     *
     * @param object|\ReflectionAttribute $item
     * @return array{name: string, value: mixed}
     */
    protected function resolveAnnotationItem($item): array
    {
        if ($item instanceof \ReflectionAttribute) {
            $instance = $item->newInstance();
            $name = $this->getClassName($item->getName());
        } else {
            $instance = $item;
            $name = $this->getClassName(get_class($item));
        }

        $valueObj = Helper::objectToArray($instance);
        if (array_key_exists('name', $valueObj) && count($valueObj) === 1) {
            $value = $valueObj['name'] === null ? true : $valueObj['name'];
        } else {
            $value = $valueObj;
        }

        return ['name' => $name, 'value' => $value];
    }

    /**
     * 合并同名注解：可重复键追加，单值键以后者为准（PHP 8 优先于 PHPDoc）
     *
     * @param array<string, mixed> $attrs
     * @param string $name
     * @param mixed $value
     */
    protected function mergeAnnotationValue(array &$attrs, string $name, $value): void
    {
        if (!isset($attrs[$name]) || $attrs[$name] === '' || $attrs[$name] === []) {
            $attrs[$name] = $value;
            return;
        }

        if (ApidocAttribute::isRepeatableKey($name)) {
            if (is_array($attrs[$name]) && is_array($value) && Helper::arrayKeyFirst($attrs[$name]) === 0) {
                $attrs[$name][] = $value;
            } elseif (is_array($attrs[$name]) && Helper::arrayKeyFirst($attrs[$name]) === 0) {
                $attrs[$name][] = $value;
            } else {
                $attrs[$name] = [$attrs[$name], $value];
            }
            return;
        }

        $attrs[$name] = $value;
    }

    /**
     * 获取并处理注解参数
     *
     * 支持 PHPDoc @Title 与 PHP 8 #[Title] 混写；同名单值注解时 #[...] 覆盖 @...
     *
     * @param array<int, object|\ReflectionAttribute> $attrList
     * @return array<string, mixed>
     */
    protected function getParameters($attrList){
        $attrs = [];
        foreach ($attrList as $item) {
            if ($item instanceof \ReflectionAttribute && !ApidocAttribute::isApidocClass($item->getName())) {
                continue;
            }

            $resolved = $this->resolveAnnotationItem($item);
            $this->mergeAnnotationValue($attrs, $resolved['name'], $resolved['value']);
        }
        return $attrs;
    }

    /**
     * 获取类的注解参数
     * @param ReflectionMethod $refMethod
     * @return array
     */
    public function getClassAnnotation($refClass){
        $readerAttributes = $this->parser->getClassAnnotations($refClass);
        $attributes = $this->collectPhpAttributes($refClass);
        return $this->getParameters(array_merge($readerAttributes, $attributes));
    }

    /**
     * 获取方法的注解参数
     * @param ReflectionMethod $refMethod
     * @return array
     */
    public function getMethodAnnotation(\ReflectionMethod $refMethod){
        $readerAttributes = $this->parser->getMethodAnnotations($refMethod);
        $attributes = $this->collectPhpAttributes($refMethod);
        return $this->getParameters(array_merge($readerAttributes, $attributes));
    }

    /**
     * 获取属性的注解参数
     * @param $property
     * @return array
     */
    public function getPropertyAnnotation($property){
        $readerAttributes = $this->parser->getPropertyAnnotations($property);
        $attributes = $this->collectPhpAttributes($property);
        return $this->getParameters(array_merge($readerAttributes, $attributes));
    }

    /**
     * 解析类的属性文本注释的var
     * @param $propertyTextAnnotations
     * @return array
     */
    protected static function parsesPropertyTextAnnotation($propertyTextAnnotations){
        $varLine = "";
        foreach ($propertyTextAnnotations as $item) {
            if (strpos($item, '@var') !== false){
                $varLine = $item;
                break;
            }
        }
        $type = "";
        $desc = "";
        if ($varLine){
            $varLineArr = preg_split('/\\s+/', $varLine);
            $type = !empty($varLineArr[1])?$varLineArr[1]:"";
            $desc = !empty($varLineArr[2])?$varLineArr[2]:"";
        }
        if (empty($desc) && strpos($propertyTextAnnotations[0], '@var') === false){
            $desc = $propertyTextAnnotations[0];
        }
        return [
            'type'=>$type,
            'desc'=>$desc,
        ];
    }

    /**
     * 获取类的属性参数
     * @param $classReflect
     * @return array
     */
    public function getClassPropertiesy($classReflect){
        $publicProperties = $classReflect->getProperties(\ReflectionProperty::IS_PUBLIC);
        $arr=[];
        foreach ($publicProperties as $property) {
              $propertyAnnotations = $this->getPropertyAnnotation($property);
              $item = [];
              if (!empty($propertyAnnotations['property'])){
                  // 有apidoc注解
                  $arr[] = $propertyAnnotations['property'];
                  continue;
              }
              $propertyTextAnnotations = self::parseTextAnnotation($property,true);
              if (empty($propertyTextAnnotations)){
                  // 无注释
                  continue;
              }
              $textAnnotationsParams=static::parsesPropertyTextAnnotation($propertyTextAnnotations);
              $textAnnotationsParams['name'] =$property->getName();
              $arr[]=$textAnnotationsParams;
        }
        return $arr;
    }
}
