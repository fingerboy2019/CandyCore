<?php
/**
 * 短信发送服务类
 * 
 * 提供基于多种服务提供商的短信发送功能，目前支持阿里云短信服务
 * 该类封装了短信发送的核心逻辑，包括签名生成、请求构建和结果处理
 * 支持模板短信发送，可批量发送给多个手机号
 * 
 * @package Extend
 * @author 刘森 (fingerboy@qq.com)
 * @since 2020-01-23 18:50:08
 */
 
declare(strict_types=1);
namespace Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Sms {
    /**
     * 阿里云云通信接口特殊编码
     * 
     * 对字符串进行URL编码，并替换特定字符以符合阿里云API的要求
     * 该方法用于生成阿里云API请求中的签名和参数编码
     * 
     * @param string $waitEncode 需要进行编码的字符串
     * @return string 返回编码后的字符串
     */
	private static function aliYunEncode(string $waitEncode): string
	{
		$tempEncode = urlencode($waitEncode);
		$tempEncode = str_replace('+','%20',$tempEncode);
		$tempEncode = str_replace('*','%2A',$tempEncode);
		$tempEncode = str_replace('%7E','~',$tempEncode);
		return $tempEncode;
	}
	
    /**
     * 阿里云云通信接口
     * 
     * 通过阿里云短信服务发送短信，支持单个或多个手机号，可配置模板参数
     * 该方法会处理请求签名生成、接口调用和结果解析等完整流程
     * 
     * @param array $data 短信发送参数，包含以下字段：
     *        - number: 手机号，可以是单个字符串或数组
     *        - templateCode: 短信模板ID
     *        - param: 模板参数，可选，关联数组格式
     * @return bool 发送成功返回true，失败返回false
     * @throws Exception 当配置不存在时会触发错误提示
     */
	public static function aliYun(array $data = []): bool
	{
		$phoneNumber = null;
		$tempTimestamp = gmdate('Y-m-d\TH:i:s\Z');
		is_array($data['number']) OR $data['number'] = [$data['number']];
		foreach ($data['number'] as $val) {
			$phoneNumber .= $val.',';
		}
		$recNum = substr($phoneNumber,0,-1);
		if(is_null(G('sms'))) \Candy\Core\Debug::showMessage(__FILE__ . __LINE__,'0','Error#M.7');
		$sms = G('sms');
		$getData = [
				'AccessKeyId'=>$sms['aliYunAccessKeyID'],
				'Timestamp'=>$tempTimestamp,
				'SignatureMethod'=>'HMAC-SHA1',
				'SignatureVersion'=>'1.0',
				'SignatureNonce'=>uniqid((string)mt_rand(0,0xffff), true),
				'Format'=>'JSON',
				'Action'=>'SendSms',
				'Version'=>'2017-05-25',
				'RegionId'=>$sms['aliYunRegionId'],
				'PhoneNumbers'=>$recNum,
				'SignName'=>$sms['aliYunSignName'],
				'TemplateCode'=>$data['templateCode']
			];
		
		if(!empty($data['param'])){
			$getData['TemplateParam'] = json_encode($data['param']);
		}
		ksort($getData);
		$sortString = null;
		foreach ($getData as $key => $val) {
			$sortString .= self::aliYunEncode($key) .'='. self::aliYunEncode($val) .'&';
		}
		$sortString = substr($sortString,0,-1);

		$signed = base64_encode(hash_hmac('sha1','POST&%2F&' . self::aliYunEncode($sortString), $sms['aliYunAccessKeySecret']."&", true));
		$signData = ['Signature'=>$signed];
		$getData=array_merge($getData, $signData);
		$info = \Candy\Extend\Network\Http::post('http://dysmsapi.aliyuncs.com/', $getData, ['header'=>'x-sdk-client: php/2.0.0']);
		
		$info = json_decode($info);
		$info = $info->Code;
		if($info == 'OK'){
			return true;
		}else{
			return false;
		}
	}
}
