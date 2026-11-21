<?php
/**
 * Apidoc 配置模板（Vendor 内置）
 *
 * 由 php candy apidoc:init 发布到 Source/Inc/Apidoc.inc.php（并改 enable=true）
 * 前端解压目录：站点根 /Apidoc/（包内 apidoc.zip 顶层目录名为 Apidoc）
 */
return array (
  'enable' => false,
  'title' => 'Candy API',
  'desc' => '基于控制器 Apidoc 注解自动生成',
  'route_prefix' => '/Apidoc',
  'apps' => 
  array (
    0 =>
    array (
      'title' => 'IM 服务 API v1',
      'path' => 'Application/Home/Controls',
      'host' => '',
      'key' => 'im_v1',
      'controllers' =>
      array (
        0 => 'ImClient',
        1 => 'ImMessage',
        2 => 'ImSupport',
        3 => 'ImMedia',
      ),
      'groups' =>
      array (
        0 =>
        array (
          'title' => '认证与会话',
          'name' => 'auth_conversation',
        ),
        1 =>
        array (
          'title' => '消息与互动',
          'name' => 'message_interaction',
        ),
        2 =>
        array (
          'title' => '客服与访客',
          'name' => 'support_visitor',
        ),
        3 =>
        array (
          'title' => '同步与媒体',
          'name' => 'sync_media',
        ),
      ),
    ),
    1 =>
    array (
      'title' => '租户业务 API v1',
      'path' => 'Application/Portal/Controls',
      'host' => '',
      'key' => 'tenant_v1',
      'controllers' =>
      array (
        0 => 'Auth',
        1 => 'TenantSetup',
      ),
      'groups' =>
      array (
        0 =>
        array (
          'title' => '认证与初始化',
          'name' => 'auth_setup',
        ),
      ),
    ),
  ),
  'definitions' => '',
  /**
   * 未写 @Url 时的自动路径：与 Route.inc.php API_URL.prefix 对齐
   * 标准形态：/api/v1/{App}/{Control}/{action}.json
   */
  'auto_url' => 
  array (
    'letter_rule' => '',
    'prefix' => '/api/v1',
    'suffix' => '.json',
    'filter_keys' => 
    array (
      0 => 'app',
      1 => 'controller',
    ),
  ),
  'auto_register_routes' => false,
  'cache' => 
  array (
    'enable' => false,
  ),
  'auth' => 
  array (
    'enable' => true,
    'password' => 'change-me',
    'secret_key' => 'apidoc#candy',
    'expire' => 86400,
  ),
  'params' => 
  array (
    'header' => 
    array (
    ),
    'query' => 
    array (
    ),
    'body' => 
    array (
    ),
  ),
  'responses' => 
  array (
    'success' => 
    array (
      0 => 
      array (
        'name' => 'code',
        'desc' => '业务代码',
        'type' => 'int',
        'required' => 1,
      ),
      1 => 
      array (
        'name' => 'message',
        'desc' => '业务信息',
        'type' => 'string',
        'required' => 1,
      ),
      2 => 
      array (
        'name' => 'data',
        'desc' => '业务数据',
        'main' => true,
        'type' => 'object',
        'required' => 1,
      ),
    ),
    'error' => 
    array (
      0 => 
      array (
        'name' => 'code',
        'desc' => '业务代码',
        'type' => 'int',
        'required' => 1,
      ),
      1 => 
      array (
        'name' => 'message',
        'desc' => '业务信息',
        'type' => 'string',
        'required' => 1,
      ),
    ),
  ),
  'default_author' => '',
  'default_method' => 'GET',
  'allowCrossDomain' => false,
  'ignored_annitation' => 
  array (
    0 => 'Auth',
    1 => 'Menu',
    2 => 'Log',
    3 => 'Annotation',
    4 => 'RateLimiter',
    5 => 'note',
    6 => 'Note',
    7 => 'Author',
    8 => 'Date',
    9 => 'param',
    10 => 'return',
    11 => 'access',
    12 => 'static',
    13 => 'var',
    14 => 'package',
    15 => 'example',
  ),
  'contract' => 
  array (
    'enable' => true,
    'scene_map' => 
    array (
      'add' => 'add',
      'create' => 'add',
      'store' => 'add',
      'edit' => 'mod',
      'update' => 'mod',
      'save' => 'mod',
      'mod' => 'mod',
    ),
  ),
  'openapi' => 
  array (
    'enable' => true,
    'version' => '1.0.0',
    'server' => '',
    'public' => false,
    'public_controllers' => 
    array (
      0 => 'Login',
    ),
    'request_body_content_type' => 'application/json',
    'cache' => 
    array (
      'enable' => false,
    ),
  ),
  'database' => 
  array (
  ),
  'docs' => 
  array (
    0 =>
    array (
      'title' => 'IM WebSocket v1 协议',
      'appKey' => 'im_v1',
      'path' => 'raw/doc/ImApiWsV1.md',
    ),
  ),
  'generator' => 
  array (
  ),
);
