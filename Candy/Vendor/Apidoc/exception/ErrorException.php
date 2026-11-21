<?php

namespace Apidoc\exception;

use \Apidoc\utils\{ConfigProvider,Helper};

class ErrorException extends \Exception
{

    protected $exceptions = [
        'apidoc close'     => ['status'=>404,'code' => 4004, 'msg' => '文档已关闭'],
        'password error'     => ['status'=>402,'code' => 4002, 'msg' => '密码不正确，请重新输入'],
        'password not found' => ['status'=>402,'code' => 4002, 'msg' => '密码不可为空'],
        'token error'        => ['status'=>401,'code' => 4001, 'msg' => '不合法的Token'],
        'token not found'    => ['status'=>401,'code' => 4001, 'msg' => '不存在Token'],
        'appkey not found'     => ['status'=>412,'code' => 4005, 'msg' => '缺少必要参数appKey'],
        'mdPath not found'     => ['status'=>412,'code' => 4006, 'msg' => '缺少必要参数path'],
        'appKey error'         => ['status'=>412,'code' => 4007, 'msg' => '不存在 key为${appKey}的apps配置'],
        'template not found'   => ['status'=>412,'code' => 4008, 'msg' => '${template}模板不存在'],
        'path not found'       => ['status'=>412,'code' => 4009, 'msg' => '${path}目录不存在'],
        'classname error'      => ['status'=>412,'code' => 4010, 'msg' => '${classname}文件名不合法'],
        'apiKey not found'     => ['status'=>412,'code' => 4011, 'msg' => '缺少必要参数apiKey'],
        'api detail not found' => ['status'=>404,'code' => 4012, 'msg' => '接口 ${path} 无 Apidoc 文档或不存在'],
        'no config apps'       => ['status'=>412,'code' => 5000, 'msg' => 'apps配置不可为空'],
        'unknown error'       => ['status'=>501,'code' => 5000, 'msg' => '未知异常'],
        'no debug'             => ['status'=>412,'code' => 5001, 'msg' => '请在debug模式下，使用该功能'],
        'no config crud'       => ['status'=>412,'code' => 5002, 'msg' => 'crud未配置'],
        'datatable crud error' => ['status'=>412,'code' => 5003, 'msg' => '数据表创建失败，请检查配置'],
        'file already exists' => ['status'=>412,'code' => 5004, 'msg' => '${filepath}文件已存在'],
        'file not exists' => ['status'=>412,'code' => 5005, 'msg' => '${filepath}文件不存在'],
        'datatable already exists' => ['status'=>412,'code' => 5004, 'msg' => '数据表${table}已存在'],
        'datatable not exists' => ['status'=>412,'code' => 5004, 'msg' => '数据表${table}不存在'],
        'ref file not exists' => ['status'=>412,'code' => 5005, 'msg' => 'ref引入 ${path} 文件不存在'],
        'ref method not exists' => ['status'=>412,'code' => 5005, 'msg' => 'ref引入${path} 中 ${method} 方法不存在'],
        'datatable create error' => ['status'=>412,'code' => 5006, 'msg' => '数据表[${table}]创建失败,error:${message},sql:${sql}'],
        'field not found' => ['status'=>412,'code' => 5006, 'msg' => '${field}字段不能为空'],
        'share not exists' => ['status'=>404,'code' => 4004, 'msg' => '该分享不存在'],
    ];

    public function __construct(string $exceptionCode, array $data = [])
    {
        $config = ConfigProvider::get();
        $exception = $this->getException($exceptionCode);
        if ($exception){
            $msg       = Helper::replaceTemplate($exception['msg'], $data);
        }else{
            $exception = $this->exceptions['unknown error'];
            $msg = $exceptionCode;
        }
		
		// code 用业务码（4001/4002…），供前端 AUTH.ERROR_CODE_FIELD 识别并弹出登录
		// 勿把 HTTP status 写进 code（否则 401 易被框架页当成 500/异常页）
		echo json([
			'code'    => (int)$exception['code'],
			'message' => $msg,
		]);
		stop();

    }

    public function getException($exceptionCode)
    {
        if (isset($this->exceptions[$exceptionCode])) {
            return $this->exceptions[$exceptionCode];
        }
        return null;
    }

}