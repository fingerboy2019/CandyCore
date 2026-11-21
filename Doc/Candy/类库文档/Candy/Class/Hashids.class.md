# Hashids 类

## 概述

*
 * Candy框架 哈希数字ID类库
 * 
 * 该类提供了安全、可逆的数字ID哈希转换功能，用于将普通数字ID转换为更短、更美观、不可预测的字符串ID。
 * 主要功能：
 * - 数字ID编码为短字符串（支持单个数字或数字数组）
 * - 短字符串解码回原始数字ID
 * - 十六进制值的编码与解码
 * - 支持自定义盐值、前缀、最小长度和字母表
 * 
 * 使用场景：
 * - 用户ID、商品ID等敏感数字信息的混淆显示
 * - URL友好的短链接生成
 * - 防止通过ID猜测数据量或顺序
 * - 美化URL中的参数显示
 * 

 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2024-08-27 15:08:03
 * @final 此类不可以继承
 * @example 
 * ```
 * // 创建Hashids实例
 * $hashids = new \Candy\Extend\Hashids('your-salt', '');
 * 
 * // 编码单个数字
 

## 主要方法


### /***
 * Candy框架 哈希数字ID类库
 * 
 * 该类提供了安全、可逆的数字ID哈希转换功能，用于将普通数字ID转换为更短、更美观、不可预测的字符串ID。
 * 主要功能：
 * - 数字ID编码为短字符串（支持单个数字或数字数组）
 * - 短字符串解码回原始数字ID
 * - 十六进制值的编码与解码
 * - 支持自定义盐值、前缀、最小长度和字母表
 * 
 * 使用场景：
 * - 用户ID、商品ID等敏感数字信息的混淆显示
 * - URL友好的短链接生成
 * - 防止通过ID猜测数据量或顺序
 * - 美化URL中的参数显示
 * 

 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2024-08-27 15:08:03
 * @final 此类不可以继承
 * @example 
 * ```
 * // 创建Hashids实例
 * $hashids = new \Candy\Extend\Hashids('your-salt', '');
 * 
 * // 编码单个数字
 * $hash = $hashids->encode(12345); // 可能返回如 "NkK9"
 * 
 * // 解码哈希值
 * $id = $hashids->decode('NkK9'); // 返回12345
 * 
 * // 编码多个数字
 * $hash = $hashids->encode(1, 2, 3); // 可能返回如 "laHquq"
 * 
 * // 解码多个数字
 * $ids = $hashids->decode('laHquq'); // 返回 [1, 2, 3]
 * 
 * // 使用前缀
 * $hashids = new \Candy\Extend\Hashids('your-salt', 'user_');
 * $hash = $hashids->encode(123); // 可能返回如 "usNkK9"
 * ```
 * @requires bcmath PHP扩展
 */
 
declare(strict_types=1);
namespace Candy\Extend;

use \Candy\Core\Debug;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Hashids
{
    /**
     * The seps divider.
     *
     * @var float
     */
    const SEP_DIV = 3.5;

    /**
     * The guard divider.
     *
     * @var float
     */
    const GUARD_DIV = 12;

    /**
     * The alphabet string.
     *
     * @var string
     */
    protected $alphabet;

    /**
     * Shuffled alphabets, referenced by alphabet and salt.
     *
     * @var array
     */
    protected $shuffledAlphabets;

    /**
     * The seps string.
     *
     * @var string
     */
    protected $seps = 'cfhistuCFHISTU';

    /**
     * The guards string.
     *
     * @var string
     */
    protected $guards;

    /**
     * The minimum hash length.
     *
     * @var int
     */
    protected $minHashLength;

    /**
     * The prefix string.
     *
     * @var string
     */
    protected $prefix;

    /**
     * The salt string.
     *
     * @var string
     */
    protected $salt;

    /**
     * The math class.
     *
     * @var \Hashids\Math\MathInterface
     */
    protected $math;

    /**
     * 构造函数，初始化Hashids实例
     * 
     * 配置哈希ID的参数，包括盐值、前缀、最小长度和字母表。
     * 
     * @param string $salt 盐值，用于增加哈希的唯一性和安全性，不同的盐值会产生不同的哈希结果
     * @param string $prefix 哈希值的前缀，可用于区分不同类型的哈希
     * @param integer $minHashLength 生成的哈希字符串的最小长度，默认为0（不限制）
     * @param string $alphabet 用于生成哈希的字符集，默认为完整的大小写字母和数字组合
     * 
     * @note 
     * - 盐值是必填参数，应设置为保密且具有足够复杂性的值
     * - 前缀会直接附加到生成的哈希值前面，不会参与编码过程
     * - 如果提供的字母表包含重复字符或长度不足16个字符，将报错
     * - 建议字母表长度在16-64个字符之间，过长可能降低性能，过短可能增加哈希冲突风险
     * 
     * @throws Debug 当字母表不满足要求时抛出
     * 
     * @example
     * ```php
     * // 基本用法
     * $hashids = new \Candy\Extend\Hashids('your-secret-salt', '');
     * 
     * // 带前缀和最小长度
     * $hashids = new \Candy\Extend\Hashids('your-salt', 'user_', 8);
     * 
     * // 使用自定义字母表（注意：不要包含重复字符）
     * $hashids = new \Candy\Extend\Hashids('your-salt', '', 0, 'abcdefghijklmnopqrstuvwxyz');
     * ```
     */
    public function __construct(string $salt = '', string $prefix = '', int $minHashLength = 0, string $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890')
    {
		//开头只允许最长两位
		if(empty($prefix) === false){
			$this->prefix = substr($prefix, 0, 2);
		}
		
        $this->salt = \mb_convert_encoding($salt, 'UTF-8', \mb_detect_encoding($salt));
        $this->minHashLength = $minHashLength;
        $alphabet = \mb_convert_encoding($alphabet, 'UTF-8', \mb_detect_encoding($alphabet));
        $this->alphabet = \implode('', \array_unique($this->multiByteSplit($alphabet)));
        $this->math = $this->getMathExtension();

        if (\mb_strlen($this->alphabet) < 16) {
			Debug:()


### /**
     * 编码一个或多个数字ID为哈希字符串
     * 
     * 将一个或多个数字ID转换为短字符串，支持单个数字或数字数组的编码。
     * 编码后的字符串可通过decode方法还原为原始数字。
     * 
     * @param integer ...$numbers 要编码的一个或多个数字ID，支持传入多个独立参数或数字数组
     * @return string 编码后的哈希字符串，如果输入无效则返回空字符串
     * 
     * @note
     * - 支持编码多个数字，多个数字编码后的哈希可以通过decode方法还原为原始数组
     * - 输入的数字必须为正整数或零
     * - 生成的哈希字符串长度不会小于构造函数中设置的最小长度
     * - 如果使用了前缀，前缀会直接附加到哈希字符串前面
     * 
     * @example
     * ```php
     * // 编码单个数字
     * $hash = $hashids->encode(12345); // 例如返回 "NkK9"
     * 
     * // 编码多个数字
     * $hash = $hashids->encode(1, 2, 3); // 例如返回 "laHquq"
     * 
     * // 使用数组方式传入多个数字
     * $hash = $hashids->encode(...[1, 2, 3]); // 同上
     * 
     * // 数字必须为正数，否则返回空字符串
     * $hash = $hashids->encode(-1); // 返回 ""
     * ```
     */
    public function encode(...$numbers):()


### /**
     * 解码哈希字符串为原始数字ID数组
     * 
     * 将之前通过encode方法生成的哈希字符串还原为原始的数字ID数组。
     * 如果哈希字符串包含前缀，会自动识别并移除前缀部分。
     * 
     * @param string $hash 要解码的哈希字符串
     * @return array|string 解码后的数字ID数组或单个字符串ID
     * 
     * @note
     * - 如果解码结果只包含单个数字，将返回该数字的字符串形式
     * - 如果解码结果包含多个数字，将返回数字数组
     * - 解码过程会自动处理前缀（如果构造函数中设置了前缀）
     * - 如果哈希字符串无效或无法解码，将返回空数组
     * - 解码结果与原始编码的数字顺序一致
     * 
     * @example
     * ```php
     * // 解码单个数字的哈希
     * $result = $hashids->decode('NkK9'); // 返回 "12345"
     * 
     * // 解码多个数字的哈希
     * $result = $hashids->decode('laHquq'); // 返回 [1, 2, 3]
     * 
     * // 解码带前缀的哈希
     * $hashids = new \Candy\Extend\Hashids('your-salt', 'user_');
     * $result = $hashids->decode('usNkK9'); // 返回 "12345"，自动识别并移除前缀
     * 
     * // 无效哈希解码
     * $result = $hashids->decode('invalid-hash'); // 返回 []
     * ```
     */
    public function decode(string $hash):()


### /**
     * 编码十六进制字符串值为哈希字符串
     * 
     * 将十六进制字符串转换为哈希字符串，方便在URL中安全传递十六进制值。
     * 编码后的哈希可通过decodeHex方法还原为原始十六进制字符串。
     * 
     * @param string $hex 要编码的十六进制字符串，支持大小写字母
     * @return string 编码后的哈希字符串，如果输入无效则返回空字符串
     * 
     * @note
     * - 输入必须是有效的十六进制格式（只包含0-9, a-f, A-F字符）
     * - 十六进制字符串可以包含0x前缀，会被自动忽略
     * - 编码过程会先将十六进制值转换为十进制数字，再进行哈希编码
     * - 生成的哈希长度不会小于构造函数中设置的最小长度
     * 
     * @example
     * ```php
     * // 编码简单的十六进制值
     * $hash = $hashids->encodeHex('DEADBEEF'); // 例如返回 "kRNrpKlJ"
     * 
     * // 编码带前缀的十六进制值
     * $hash = $hashids->encodeHex('0x1A2B3C'); // 例如返回 "j1KcG"
     * 
     * // 无效的十六进制值返回空字符串
     * $hash = $hashids->encodeHex('XYZ'); // 返回 ""
     * 
     * // 解码示例
     * $originalHex = $hashids->decodeHex('kRNrpKlJ'); // 返回 "DEADBEEF"
     * ```
     */
    public function encodeHex(string $str):()


### /**
     * 解码哈希字符串为原始十六进制值
     * 
     * 将之前通过encodeHex方法生成的哈希字符串还原为原始的十六进制字符串。
     * 如果哈希字符串包含前缀，会自动识别并移除前缀部分。
     * 
     * @param string $hash 要解码的哈希字符串
     * @return string 解码后的十六进制字符串，大写形式，不包含0x前缀；如果解码失败则返回空字符串
     * 
     * @note
     * - 返回的十六进制字符串为大写形式
     * - 返回结果不包含0x前缀
     * - 解码过程会自动处理前缀（如果构造函数中设置了前缀）
     * - 如果哈希字符串无效或无法解码，将返回空字符串
     * - 解码仅适用于之前通过encodeHex方法编码的哈希值
     * 
     * @example
     * ```php
     * // 解码十六进制哈希
     * $hex = $hashids->decodeHex('kRNrpKlJ'); // 返回 "DEADBEEF"
     * 
     * // 解码带前缀的十六进制哈希
     * $hashids = new \Candy\Extend\Hashids('your-salt', 'hex_');
     * $hex = $hashids->decodeHex('hex_kRNrpKlJ'); // 返回 "DEADBEEF"
     * 
     * // 无效哈希解码
     * $hex = $hashids->decodeHex('invalid-hash'); // 返回 ""
     * 
     * // 完整的编码解码流程
     * $originalHex = '1A2B3C';
     * $hash = $hashids->encodeHex($originalHex);
     * $restoredHex = $hashids->decodeHex($hash); // $restoredHex === '1A2B3C'
     * ```
     */
    public function decodeHex(string $hash):()


### /**
     * 根据盐值打乱字母表顺序
     * 
     * 内部方法，用于根据提供的盐值打乱字母表，增强哈希的安全性和唯一性。
     * 该方法使用Fisher-Yates洗牌算法的变体，确保字母表被彻底打乱。
     * 
     * @param string $alphabet 要打乱的原始字母表
     * @param string $salt 用于打乱的盐值
     * @return string 打乱后的字母表
     * 
     * @note
     * - 打乱过程是确定性的，相同的字母表和盐值会产生相同的打乱结果
     * - 该方法在构造函数中被调用，用于初始化内部打乱后的字母表
     * - 打乱算法确保每个字符在最终字母表中只出现一次
     * - 盐值越长，打乱效果越好，但处理时间也会增加
     * 
     * @internal 内部使用方法，不应被外部直接调用
     */
    protected function shuffle($alphabet, $salt):()


### /**
     * 将数字哈希为字符
     * 
     * 内部方法，用于将单个数字转换为字母表中的对应字符。
     * 该方法是编码过程的核心，负责将数值映射到字母表中的特定位置。
     * 
     * @param integer $num 要哈希的数字
     * @param string $alphabet 使用的字母表
     * @return string 哈希后的字符
     * 
     * @note
     * - 该方法被encode方法内部调用，用于构建最终的哈希字符串
     * - 如果数字大于字母表长度，会使用模运算来映射到字母表范围内
     * - 哈希过程是确定性的，相同的数字和字母表会产生相同的字符
     * 
     * @internal 内部使用方法，不应被外部直接调用
     */
    protected function hash($input, $alphabet):()


### /**
     * 将哈希字符反转换为对应的数字
     * 
     * 内部方法，用于将字母表中的字符反向映射到对应的数字值。
     * 该方法是解码过程的核心，负责将哈希字符串中的每个字符转换回原始数值。
     * 
     * @param string $input 要反哈希的字符
     * @param string $alphabet 使用的字母表
     * @return integer 反哈希后的数字
     * 
     * @note
     * - 该方法被decode方法内部调用，用于解析哈希字符串
     * - 如果字符不在字母表中，则返回-1表示无效
     * - 反哈希过程是哈希过程的逆操作
     * 
     * @internal 内部使用方法，不应被外部直接调用
     */
    protected function unhash($input, $alphabet):()


### /**
     * Get BC Math extension.
     *
     * @codeCoverageIgnore
     *
     * @throws debug
     *
     * @return bool
     */
    protected function getMathExtension():()


### /**
     * Replace simple use of $this->multiByteSplit with multi byte string.
     *
     * @param $string
     *
     * @return array<int, string>
     */
    protected function multiByteSplit(string $string):()

