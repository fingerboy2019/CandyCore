<?php
/**
 * Pay 支付类
 * 
 * 提供统一的支付接口封装，支持支付宝和微信支付
 * 
 * 主要功能：
 * - 支付宝支付生成
 * - 微信支付生成（支持NATIVE、JSAPI、MWEB等模式）
 * - 支付结果验证
 * - 错误处理和日志记录
 * 
 * @package Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-05-24 00:28:25
 * @final 本类为最终类不允许继承
 */

declare(strict_types=1);
namespace Extend;
use \Candy\Extend\Network\{Client,Http};
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Pay {
    /**
     * 支付宝支付接口
     * 
     * 生成支付宝支付链接，支持普通支付和二维码支付
     * 
     * @param array $data 支付参数数组
     *        - id: 商户订单号
     *        - title: 订单标题
     *        - total: 订单金额（单位：分）
     *        - qr: 二维码配置（可选）
     *          - width: 二维码宽度（可选）
     * @return string 生成的支付宝支付链接
     * @throws \Exception 当支付配置未设置时抛出异常
     */
	public static function aliPay(array $data = []): string
	{
		if(is_null(G('payment'))) self::report(__FILE__,__LINE__,'Error#M.7');
		$payment = G('payment');
		// 新版（RSA2）：配置了 alipay_app_id 时走新版网关；否则回退老版 mapi + MD5
		if(!empty($payment['alipay_app_id'])){
			return self::aliPayV2($data, $data['type'] ?? 'page');
		}
		
		$postData = [
				'service'=>'create_direct_pay_by_user',
				'partner'=>$payment['alipid'],
				'_input_charset'=>'utf-8',
				'notify_url'=>$payment['notifyurl'],
				'return_url'=>$payment['returnurl'],
				'out_trade_no'=>$data['id'],
				'subject'=>$data['title'],	
				'payment_type'=>'1',
				'total_fee'=>intval($data['total'])/100,
				'seller_id'=>$payment['alipid'],
				'it_b_pay'=>'1h',
			];
		if(isset($data['qr'])){
			if(isset($data['qr']['width'])){
				$QRData = ['qr_pay_mode'=>'4','qrcode_width'=>$data['qr']['width']];
				$postData = array_merge($postData,$QRData);
			}
			else{
				$QRData = ['qr_pay_mode'=>'3'];
				$postData = array_merge($postData,$QRData);
			}
		}
		ksort($postData);
		$sortString = null;
		foreach ($postData as $key => $val){
			$sortString .= $key .'='. $val .'&';
		}
		$sortString = substr($sortString, 0, -1);
		$sign = md5($sortString . $payment['alikey']);
		$sortString.='&sign='.$sign.'&sign_type=MD5';
		return 'https://mapi.alipay.com/gateway.do?'.$sortString;
	}
	
    /**
     * 微信支付接口
     * 
     * 生成微信支付参数，支持多种支付模式
     * 
     * @param array $data 支付参数数组
     *        - id: 商户订单号
     *        - title: 订单标题
     *        - total: 订单金额（单位：分）
     *        - ip: 客户端IP地址（可选，默认自动获取）
     *        - mode: 支付模式（可选，默认'NATIVE'）
     *          - NATIVE: 原生扫码支付
     *          - JSAPI: JSAPI支付（需提供openid）
     *          - MWEB: H5支付
     *        - openid: 用户openid（JSAPI模式必需）
     * @return string 根据支付模式返回不同结果：
     *                - NATIVE模式：返回二维码链接
     *                - JSAPI模式：返回prepay_id
     *                - MWEB模式：返回H5支付链接
     * @throws \Exception 当支付配置未设置或请求失败时抛出异常
     */
	public static function wxPay(array $data = []): string
	{
		if(is_null(G('payment'))) self::report(__FILE__,__LINE__,'Error#M.7');
		$payment = G('payment');
		// 新版（APIv3）：配置了 wx_apiv3_key 时走 v3 native（返回 code_url 供扫码）；否则回退老版 XML
		if(!empty($payment['wx_apiv3_key'])){
			return self::wxPayV3($data);
		}
		
		if(empty($data['ip'])){
			$ip = Client::getClientIp();
 		}
		
		if(empty($data['mode'])){
			$mode = 'NATIVE';
 		}
		
		$string = null;
		$word = '0123456789qwertyuiopasdfghjklzxcvbnm';
		for($n=1;$n<=31;$n++){
			$random = mt_rand(0,34);
			$string .= $word[$random];
		}
		$expireTime = date('YmdHis', gmTime() + 3600);
		$postData = [
				'appid'=>$payment['wxappid'],
				'mch_id'=>$payment['wxmchid'],
				'nonce_str'=>$string,
				'body'=>$data['title'],
				'out_trade_no'=>$data['id'],
				'total_fee'=>$data['total'],
				'spbill_create_ip'=>$ip,
				'time_expire'=>$expireTime,
				'notify_url'=>$payment['notifyurl'],
				'trade_type'=>$mode,
			];
		if($mode == 'JSAPI'){
			$postData['openid'] = $data['openid'];
		}
		if($mode == 'MWEB'){
			$postData['scene_info'] = json_encode($payment['wxsceneinfo']);
		}
		ksort($postData);
		$sortString = null;
		foreach ($postData as $key => $val){
			$sortString .= $key .'='. $val .'&';
		}
		$sign = md5($sortString .'key='. $payment['wxkey']);
		
		$pay = '<?xml version=\'1.0\'?>'."\r\n".
		'<xml>'."\r\n".
		'<appid>'.$payment['wxappid'].'</appid>'."\r\n".
		'<mch_id>'.$payment['wxmchid'].'</mch_id>'."\r\n".
		'<nonce_str>'.$string.'</nonce_str>'."\r\n".
		'<body>'.$data['title'].'</body>'."\r\n".
		'<out_trade_no>'.$data['id'].'</out_trade_no>'."\r\n".
		'<total_fee>'.$data['total'].'</total_fee>'."\r\n".
		'<spbill_create_ip>'.$ip.'</spbill_create_ip>'."\r\n".
		'<time_expire>'.$expireTime.'</time_expire>'."\r\n".
		'<notify_url>'.$payment['notifyurl'].'</notify_url>'."\r\n".
		'<trade_type>'.$mode.'</trade_type>'."\r\n";
		if($mode == 'JSAPI'){
			$pay .= '<openid>'.$data['openid'].'</openid>'."\r\n";
		}
		if($mode == 'MWEB'){
			$pay .= '<scene_info>'.json_encode($payment['wxsceneinfo']).'</scene_info>'."\r\n";
		}
		$pay .= '<sign>'.$sign.'</sign>'."\r\n".
		'</xml>
		';

		$info = Http::post('https://api.mch.weixin.qq.com/pay/unifiedorder', $pay, [
			'header'=>'Content-Type: text/xml; charset=UTF-8',
			'encode'=> true,
			'timeout'=> 5]);
		
		// 确保$info是字符串类型
		$info = is_array($info) ? json_encode($info) : $info;
		xml_parse_into_struct(xml_parser_create(), $info, $returnInfo);
		$return = false;
		if(empty($returnInfo)){
			self::report(__FILE__,__LINE__,'Error#M.7.0');
		}
		$result = true;
		foreach($returnInfo as $val){
			if($val['tag'] == 'RETURN_CODE' && $val['value'] != 'SUCCESS'){
				$result=false;
			}
			if(!$result && $val['tag'] == 'RETURN_MSG'){
				self::report(__FILE__,__LINE__,'Error#M.7.1 @ '.$val['value']);
			}
			if($val['tag'] == 'PREPAY_ID' && $mode == 'JSAPI'){
				$result=$val['value'];
			}
			if($val['tag'] == 'CODE_URL' && $mode == 'NATIVE'){
				$result=$val['value'];
			}
			if($val['tag'] == 'MWEB_URL' && $mode == 'MWEB'){
				$result = $val['value'];
			}
		}
		return $result;
	}
	
    /**
     * 支付宝支付验签
     * 
     * 验证支付宝异步通知的签名，确保支付结果的真实性
     * 
     * @param array $data 支付配置数据
     *        - alikey: 支付宝密钥
     * @return bool 验签成功返回true，失败返回false
     * @throws \Exception 当支付配置未设置时抛出异常
     * @note 此方法会自动获取POST请求中的支付宝通知数据
     */
	public static function aliPayVerify(array $data = []): bool
	{
		if(!is_null(G('payment')) && !empty(G('payment')['alipay_app_id'])){
			return self::aliPayV2Verify($data);
		}
		$postData = I('post');
		if(empty($postData)){
			return false;
		}
		if($postData['trade_status'] != 'TRADE_SUCCESS'){
			return false;
		}
		ksort($postData);
		$willCheck = null;
		foreach($postData as $key => $val){
			if($key != 'sign' && $key != 'sign_type' && !empty($val)){
				$willCheck .= $key .'=';
				$willCheck .= $val .'&';
			}
		}
		$willCheck = substr($willCheck, 0, -1);
		$sign = md5($willCheck . $data['alikey']);
		// 严格比较：弱比较（!=）在 MD5 为 0e 开头纯数字时存在 magic hash 绕过风险
		if(!hash_equals($sign, (string)($postData['sign'] ?? ''))){
			return false;
		}
		
		if(is_null(G('payment'))) self::report(__FILE__,__LINE__,'Error#M.7');
		$payment = G('payment');
		// 确保notify_id是字符串类型
		$notifyId = is_array($postData['notify_id']) ? json_encode($postData['notify_id']) : $postData['notify_id'];
		$notifyResult = Http::get('https://mapi.alipay.com/gateway.do?service=notify_verify&partner='. $payment['alipid'] .'&notify_id='.$notifyId);
		if(strtoupper((string)$notifyResult) == 'TRUE'){
			return true;
		}
		else{
			return false;
		}
	}
	
    /**
     * 微信支付验签
     * 
     * 验证微信支付异步通知的签名，确保支付结果的真实性
     * 
     * @param string $string 微信支付通知的XML数据
     * @return bool 验签成功返回true，失败返回false
     * @throws \Exception 当支付配置未设置时抛出异常
     * @note 此方法需要传入微信服务器发送的完整XML通知数据
     */
	public static function wxPayVerify(string $string = ''): bool
	{
		// 新版（APIv3）：回调是 JSON + Wechatpay-* 请求头
		if(!empty($string) && $string[0] === '{'){
			return self::wxPayV3Verify($string);
		}
		$xmlArray = json_decode(json_encode(simplexml_load_string($string,'SimpleXMLElement',LIBXML_NOCDATA)),TRUE);
		if(empty($xmlArray)){
			return false;
		}
		ksort($xmlArray);
		$willCheck = null;
		foreach($xmlArray as $key => $val){
			if($key != 'sign' && !empty($val) && !is_array($val)){
				$willCheck .= $key .'='. $val.'&';
			}
		}
		if(is_null(G('payment'))) self::report(__FILE__,__LINE__,'Error#M.7');
		$payment = G('payment');
		$sign=strtoupper(md5($willCheck .'key='. $payment['wxkey']));
		// 严格比较：弱比较（==）在 MD5 为 0e 开头纯数字时存在 magic hash 绕过风险
		if(!hash_equals($sign, strtoupper((string)($xmlArray['sign'] ?? '')))){
			return false;
		}
		else{
			return true;
		}
	}
	
    /**
     * 错误报告方法
     * 
     * 显示支付相关的错误信息，用于调试和错误追踪
     * 
     * @param string $file 错误发生的文件路径
     * @param int $line 错误发生的行号
     * @param string $msg 错误信息内容
     * @return void
     */
	public static function report(string $file,int $line,string $msg){
		\Candy\Core\Debug::showMessage('<font color="red"><b>Shutdown error</b>[在文件 '. $file .' 中,第 '. $line .' 行]:'. $msg .'</font>', 'PAY');
	}

	/* ==================== 新版支付（支付宝 RSA2 / 微信 APIv3） ==================== */

	/**
	 * 支付宝新版（RSA2）支付
	 *
	 * 支持 alipay.trade.page.pay（PC 网页跳转，默认）/ alipay.trade.wap.pay（手机网页）/
	 * alipay.trade.precreate（扫码，返回二维码内容）。
	 *
	 * @param array $data 支付参数（id/title/total 分）
	 * @param string $type page|wap|precreate
	 * @return string 跳转 URL（page/wap）或二维码内容（precreate）
	 */
	public static function aliPayV2(array $data = [], string $type = 'page'): string
	{
		$payment = G('payment');
		$biz = [
			'out_trade_no' => (string) $data['id'],
			'subject'      => (string) $data['title'],
			'total_amount' => number_format((int) $data['total'] / 100, 2, '.', ''),
		];
		if ($type === 'precreate') {
			$method = 'alipay.trade.precreate';
		} elseif ($type === 'wap') {
			$method = 'alipay.trade.wap.pay';
			$biz['product_code'] = 'QUICK_WAP_WAY';
		} else {
			$method = 'alipay.trade.page.pay';
			$biz['product_code'] = 'FAST_INSTANT_TRADE_PAY';
		}

		$params = [
			'app_id'      => $payment['alipay_app_id'],
			'method'      => $method,
			'format'      => 'JSON',
			'charset'     => 'utf-8',
			'sign_type'   => 'RSA2',
			'timestamp'   => date('Y-m-d H:i:s'),
			'version'     => '1.0',
			'notify_url'  => $payment['notifyurl'],
			'return_url'  => $payment['returnurl'] ?? '',
			'biz_content' => json_encode($biz, JSON_UNESCAPED_UNICODE),
		];
		$params['sign'] = self::rsa2Sign($params, $payment['alipay_private_key']);

		return 'https://openapi.alipay.com/gateway.do?' . http_build_query($params);
	}

	/**
	 * 支付宝新版回调验签（RSA2）
	 *
	 * @param array $data 附加数据（未用；验签基于回调 POST 全参数）
	 * @return bool
	 */
	public static function aliPayV2Verify(array $data = []): bool
	{
		// 支持显式传参（测试/手动验签）；真实回调场景走 I('post')
		$postData = $data !== [] ? $data : I('post');
		if (empty($postData)) {
			return false;
		}
		if (($postData['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
			return false;
		}
		$payment = G('payment');
		$sign = (string) ($postData['sign'] ?? '');
		unset($postData['sign'], $postData['sign_type']);
		ksort($postData);
		$content = '';
		foreach ($postData as $key => $val) {
			if ($val === '' || $val === null) {
				continue;
			}
			$content .= $key . '=' . $val . '&';
		}
		$content = substr($content, 0, -1);
		return self::rsa2Verify($content, $sign, $payment['alipay_public_key']);
	}

	/**
	 * 微信支付 APIv3：native 扫码下单，返回 code_url（供生成二维码）
	 *
	 * @param array $data 支付参数（id/title/total 分）
	 * @return string code_url；失败返回空串
	 */
	public static function wxPayV3(array $data = []): string
	{
		$payment = G('payment');
		$path = '/v3/pay/transactions/native';
		$body = [
			'appid'        => $payment['wxappid'],
			'mchid'        => $payment['wx_mchid'],
			'description'  => (string) $data['title'],
			'out_trade_no' => (string) $data['id'],
			'notify_url'   => $payment['notifyurl'],
			'amount'       => ['total' => (int) $data['total'], 'currency' => 'CNY'],
		];
		$json = json_encode($body, JSON_UNESCAPED_UNICODE);
		$auth = self::wxV3Authorization('POST', $path, $json, $payment);

		$resp = \Candy\Extend\Network\Http::request('POST', 'https://api.mch.weixin.qq.com' . $path, [
			'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: ' . $auth],
			'data'    => $json,
		]);
		if (!empty($resp['content'])) {
			$decoded = json_decode($resp['content'], true);
			return (string) ($decoded['code_url'] ?? '');
		}
		return '';
	}

	/**
	 * 微信支付 APIv3 回调：平台证书验签 + AES-256-GCM 解密
	 *
	 * @param string $body 回调 JSON 原文
	 * @return bool 验签+解密成功返回 true
	 */
	public static function wxPayV3Verify(string $body): bool
	{
		$payment = G('payment');
		// 从请求头取微信签名信息（Wechatpay-Timestamp/Nonce/Signature/Serial）
		$ts     = (string) I('server.HTTP_WECHATPAY_TIMESTAMP', '');
		$nonce  = (string) I('server.HTTP_WECHATPAY_NONCE', '');
		$sign   = (string) I('server.HTTP_WECHATPAY_SIGNATURE', '');
		$serial = (string) I('server.HTTP_WECHATPAY_SERIAL', '');
		if ($ts === '' || $sign === '') {
			return false;
		}
		// 1. 平台证书验签
		$message = $ts . "\n" . $nonce . "\n" . $body . "\n";
		$pubKey = openssl_pkey_get_public(@file_get_contents($payment['wx_platform_cert']));
		if (!$pubKey) {
			return false;
		}
		if (openssl_verify($message, base64_decode($sign), $pubKey, OPENSSL_ALGO_SHA256) !== 1) {
			return false;
		}
		// 2. AES-256-GCM 解密 resource
		$decoded = json_decode($body, true);
		$resource = $decoded['resource'] ?? [];
		if (empty($resource['ciphertext']) || empty($resource['nonce'])) {
			return false;
		}
		$plain = openssl_decrypt(
			base64_decode($resource['ciphertext']),
			'aes-256-gcm',
			$payment['wx_apiv3_key'],
			OPENSSL_RAW_DATA,
			$resource['nonce'],
			base64_decode($resource['associated_data'] ?? '')
		);
		return $plain !== false;
	}

	/**
	 * RSA2 签名（SHA256withRSA）
	 *
	 * @param array $params 待签名参数（不含 sign）
	 * @param string $privateKey 应用私钥内容或路径
	 * @return string base64 签名
	 */
	private static function rsa2Sign(array $params, string $privateKey): string
	{
		ksort($params);
		$content = '';
		foreach ($params as $key => $val) {
			if ($val === '' || $val === null) {
				continue;
			}
			$content .= $key . '=' . $val . '&';
		}
		$content = substr($content, 0, -1);
		$key = self::loadKey($privateKey);
		openssl_sign($content, $signature, $key, OPENSSL_ALGO_SHA256);
		return base64_encode($signature);
	}

	/**
	 * RSA2 验签
	 *
	 * @param string $content 待验签内容
	 * @param string $sign base64 签名
	 * @param string $publicKey 支付宝公钥内容或路径
	 * @return bool
	 */
	private static function rsa2Verify(string $content, string $sign, string $publicKey): bool
	{
		$key = self::loadKey($publicKey, false);
		return $key !== false && openssl_verify($content, base64_decode($sign), $key, OPENSSL_ALGO_SHA256) === 1;
	}

	/**
	 * 微信 APIv3 请求签名头（WECHATPAY2-SHA256-RSA2048）
	 *
	 * @param string $method 请求方法
	 * @param string $path 请求路径
	 * @param string $body 请求体 JSON
	 * @param array $payment 支付配置
	 * @return string Authorization 头值
	 */
	private static function wxV3Authorization(string $method, string $path, string $body, array $payment): string
	{
		$timestamp = time();
		$nonce = bin2hex(random_bytes(16));
		$message = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
		$key = self::loadKey($payment['wx_private_key']);
		openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256);
		return sprintf(
			'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"',
			$payment['wx_mchid'], $nonce, base64_encode($signature), $timestamp, $payment['wx_serial_no']
		);
	}

	/**
	 * 加载 PEM 密钥（支持内容或文件路径）
	 *
	 * @param string $pem PEM 内容或路径
	 * @param bool $isPrivate 是否私钥
	 * @return \OpenSSLAsymmetricKey|false
	 */
	private static function loadKey(string $pem, bool $isPrivate = true)
	{
		$content = str_contains($pem, 'BEGIN') ? $pem : (@file_get_contents($pem) ?: $pem);
		return $isPrivate
			? openssl_pkey_get_private($content)
			: openssl_pkey_get_public($content);
	}

}