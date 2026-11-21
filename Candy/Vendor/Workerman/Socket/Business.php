<?php
namespace Socket;

use \Candy\Core\Build;
use \GatewayWorker\BusinessWorker;

/**
 * Socket 业务处理类
 * 
 * 该类继承自 GatewayWorker\BusinessWorker，负责处理 Socket 连接的业务逻辑，
 * 包括初始化业务进程、设置事件处理类等。
 * 
 * @package Socket
 * @extends BusinessWorker
 */
class Business extends BusinessWorker
{
    /**
     * 构造函数
     * 
     * @param array $config 配置数组，包含业务进程的配置信息
     */
    public function __construct(array $config = [])
    {
        parent::__construct();
        $bussinessConfig = isset($config['bussiness']) ? $config['bussiness'] : [];
        $this->name = $bussinessConfig['name'] ??= 'BussinessWorker';
        $this->count = 4; // 业务进程数量
        $this->registerAddress = $bussinessConfig['address'] ??= '127.0.0.1:1238';

        // 定义事件处理类
        $event = C('SOURCEPATH') . 'Event/wsEvent.class.php';
        if (is_file($event) === false) {
            // 创建默认的事件处理类
            Build::touch($event, base64_decode('PD9waHAKbmFtZXNwYWNlIEFQUFxFdmVudDsKCmRlZmluZWQoJ0NBTkRZJykgT1IgZGllKCdZb3UgQXJlIEEgQmFkIEd1eS4gb19PPz8/Jyk7CgovKioKICogd3PmnI3liqHlmajkuovku7blpITnkIbnsbsKICog5Li76KaB5piv5aSE55CGIG9uQ29ubmVjdCBvbk1lc3NhZ2Ugb25DbG9zZSDkuInkuKrmlrnms5UKICogb25Db25uZWN0IOWSjCBvbkNsb3NlIOWmguaenOS4jemcgOimgeWPr+S7peS4jeeUqOWunueOsOW5tuWIoOmZpAogKi8KY2xhc3Mgd3NFdmVudAp7CgogICAgLyoqCiAgICAgKiDov5vnqIvlkK/liqjlkI7liJ3lp4vljJbmlbDmja7lupPov57mjqUKICAgICAqLwogICAgcHVibGljIHN0YXRpYyBmdW5jdGlvbiBvbldvcmtlclN0YXJ0KCR3b3JrZXIpCiAgICB7CiAgICAgICAgLy/ov5DooYzliY3nmoTmnI3liqHlmajliJ3lp4vljJYg5q+U5aaCUmVkaXMKCiAgICB9CgogICAgLyoqCiAgICAgKiDlvZPlrqLmiLfnq6/ov57mjqXml7bop6blj5EKICAgICAqIOWmguaenOS4muWKoeS4jemcgOatpOWbnuiwg+WPr+S7peWIoOmZpG9uQ29ubmVjdAogICAgICoKICAgICAqIEBwYXJhbSBpbnQgJGNsaWVudF9pZCDov57mjqVpZAogICAgICovCiAgICBwdWJsaWMgc3RhdGljIGZ1bmN0aW9uIG9uQ29ubmVjdCgkY2xpZW50X2lkKQogICAgewogICAgICAgIC8vIOWQkeW9k+WJjWNsaWVudF9pZOWPkemAgeaVsOaNrgogICAgICAgIC8vR2F0ZXdheTo6c2VuZFRvQ2xpZW50KCRjbGllbnRfaWQsICJIZWxsbyAkY2xpZW50X2lkXHJcbiIpOwogICAgICAgIC8vIOWQkeaJgOacieS6uuWPkemAgQogICAgICAgIC8vR2F0ZXdheTo6c2VuZFRvQWxsKCIkY2xpZW50X2lkIGxvZ2luXHJcbiIpOwoKICAgIH0KCiAgIC8qKgogICAgKiDlvZPlrqLmiLfnq6/lj5HmnaXmtojmga/ml7bop6blj5EKICAgICogQHBhcmFtIGludCAkY2xpZW50X2lkIOi/nuaOpWlkCiAgICAqIEBwYXJhbSBtaXhlZCAkbWVzc2FnZSDlhbfkvZPmtojmga8KICAgICovCiAgIHB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gb25NZXNzYWdlKCRjbGllbnRfaWQsICRtZXNzYWdlKQogICB7CiAgICAgICBpZiAoJG1lc3NhZ2UgPT0gJ3BpbmcnKQogICAgICAgICAgIHJldHVybjsKCSAgIAoJICAgLy/ku6XkuIvmmK/moYbmnrbmiafooYzpgLvovpEKICAgICAgIEFwcDo6cnVuKCRjbGllbnRfaWQsICRtZXNzYWdlKTsKICAgfQoKICAgLyoqCiAgICAqIOW9k+eUqOaIt+aWreW8gOi/nuaOpeaXtuinpuWPkQogICAgKiBAcGFyYW0gaW50ICRjbGllbnRfaWQg6L+e5o6laWQKICAgICovCiAgIHB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gb25DbG9zZSgkY2xpZW50X2lkKQogICB7CiAgICAgICAvLyDlkJHmiYDmnInkurrlj5HpgIEKICAgICAgIC8vR2F0ZVdheTo6c2VuZFRvQWxsKCIkY2xpZW50X2lkIGxvZ291dFxyXG4iKTsKICAgfQp9Cg=='));
        }
        $this->eventHandler = "\APP\Event\wsEvent"; // 设置事件处理类
    }

    /**
     * 客户端连接时触发
     * 
     * @param string $client_id 客户端ID
     * @return void
     */
    public function onConnect(string $client_id): void
    {
        print_r($_SESSION);
    }
}
