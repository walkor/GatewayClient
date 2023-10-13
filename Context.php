<?php
namespace GatewayClient;

/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 上下文 包含当前用户uid， 内部通信local_ip local_port socket_id ，以及客户端client_ip client_port
 */
class Context
{
    /**
     * 内部通讯id
     * @var string
     */
    public static $local_ip;
    /**
     * 内部通讯端口
     * @var int
     */
    public static $local_port;
    /**
     * 客户端ip
     * @var string
     */
    public static $client_ip;
    /**
     * 客户端端口
     * @var int
     */
    public static $client_port;
    /**
     * client_id
     * @var string
     */
    public static $client_id;
    /**
     * 连接connection->id
     * @var int
     */
    public static $connection_id;

    /**
     * 旧的session
     *
     * @var string
     */
    public static $old_session;

    /**
     * 编码session
     * @param mixed $session_data
     * @return string
     */
    public static function sessionEncode($session_data = '')
    {
        if ($session_data !== '') {
            return serialize($session_data);
        }

        return '';
    }

    /**
     * 解码session
     * @param string $session_buffer
     * @return mixed
     */
    public static function sessionDecode($session_buffer)
    {
        return unserialize($session_buffer);
    }

    /**
     * 清除上下文
     * @return void
     */
    public static function clear()
    {
        static::$local_ip = static::$local_port = static::$client_ip = static::$client_port =
        static::$client_id = static::$connection_id  = static::$old_session = null;
    }

    /**
     * 通讯地址到client_id的转换
     * @return string
     */
    public static function addressToClientId($local_ip, $local_port, $connection_id)
    {
        return bin2hex(pack('NnN', $local_ip, $local_port, $connection_id));
    }

    /**
     * client_id到通讯地址的转换
     * @return array
     */
    public static function clientIdToAddress($client_id)
    {
        if (strlen($client_id) !== 20) {
            throw new \Exception("client_id $client_id is invalid");
        }
        return unpack('Nlocal_ip/nlocal_port/Nconnection_id' ,pack('H*', $client_id));
    }

}