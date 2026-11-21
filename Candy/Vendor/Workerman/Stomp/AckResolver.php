<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Stomp;

use \Workerman\Stomp\Client;

#[\AllowDynamicProperties]
/**
 * STOMP 消息确认解析器类
 * 
 * 该类用于处理 STOMP 协议中的消息确认（ack）和否定确认（nack）操作，
 * 提供了简单的接口来确认或拒绝收到的消息。
 * 
 * @package Workerman\Stomp
 */
class AckResolver
{
    /**
     * STOMP 客户端实例
     * @var Client
     */
    protected $_client;
    
    /**
     * 订阅 ID
     * @var string
     */
    protected $_subscription;
    
    /**
     * 消息 ID
     * @var string
     */
    protected $_messageId;
    
    /**
     * 确认状态标志
     * @var bool
     */
    protected $_done;

    /**
     * 构造函数
     * 
     * @param Client $client STOMP 客户端实例
     * @param string $subscription 订阅 ID
     * @param string $messageId 消息 ID
     */
    public function __construct(Client $client, $subscription, $messageId)
    {
        $this->_client = $client;
        $this->_subscription = $subscription;
        $this->_messageId = $messageId;
    }

    /**
     * 确认消息
     * 
     * 向 STOMP 服务器发送消息确认，通知服务器消息已被成功处理。
     * 
     * @param array $headers 额外的头信息
     * @return void
     */
    public function ack(array $headers = [])
    {
        if ($this->_done) {
            return;
        }
        $this->_client->ack($this->_subscription, $this->_messageId, $headers);
        $this->_done = true;
        $this->_client = null;
    }

    /**
     * 否定确认消息
     * 
     * 向 STOMP 服务器发送否定确认，通知服务器消息处理失败。
     * 
     * @param array $headers 额外的头信息
     * @return void
     */
    public function nack(array $headers = [])
    {
        if ($this->_done) {
            return;
        }
        $this->_client->nack($this->_subscription, $this->_messageId, $headers);
        $this->_done = true;
        $this->_client = null;
    }

    /**
     * 标记为已完成
     * 
     * 直接标记消息处理完成，不向服务器发送确认或否定确认。
     * 
     * @return void
     */
    public function done()
    {
        $this->_done = true;
    }

}
