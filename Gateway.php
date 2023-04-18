<?php
namespace GatewayClient;
use \Exception;
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
 * 数据发送相关
 * @version 3.0.12
 */

/**
 * 数据发送相关
 */
class Gateway
{
    /**
     * gateway 实例
     *
     * @var object
     */
    protected static $businessWorker = null;

    /**
     * 注册中心地址
     *
     * @var string|array
     */
    public static $registerAddress = '127.0.0.1:1236';

    /**
     * 秘钥
     * @var string
     */
    public static $secretKey = '';

    /**
     * 链接超时时间
     * @var int
     */
    public static $connectTimeout = 3;

    /**
     * 与Gateway是否是长链接
     * @var bool
     */
    public static $persistentConnection = false;

    /**
     * 是否清除注册地址缓存
     * @var bool
     */
    public static $addressesCacheDisable = false;
    
    /**
     * 向所有客户端连接(或者 client_id_array 指定的客户端连接)广播消息
     *
     * @param string $message           向客户端发送的消息
     * @param array  $client_id_array   客户端 id 数组
     * @param array  $exclude_client_id 不给这些client_id发
     * @param bool   $raw               是否发送原始数据（即不调用gateway的协议的encode方法）
     * @return void
     * @throws Exception
     */
    public static function sendToAll($message, $client_id_array = null, $exclude_client_id = null, $raw = false)
    {
        $gateway_data         = GatewayProtocol::$empty;
        $gateway_data['cmd']  = GatewayProtocol::CMD_SEND_TO_ALL;
        $gateway_data['body'] = $message;
        if ($raw) {
            $gateway_data['flag'] |= GatewayProtocol::FLAG_NOT_CALL_ENCODE;
        }

        if ($exclude_client_id) {
            if (!is_array($exclude_client_id)) {
                $exclude_client_id = array($exclude_client_id);
            }
            if ($client_id_array) {
                $exclude_client_id = array_flip($exclude_client_id);
            }
        }

        if ($client_id_array) {
            if (!is_array($client_id_array)) {
                echo new \Exception('bad $client_id_array:'.var_export($client_id_array, true));
                return;
            }
            $data_array = array();
            foreach ($client_id_array as $client_id) {
                if (isset($exclude_client_id[$client_id])) {
                    continue;
                }
                $address = Context::clientIdToAddress($client_id);
                if ($address) {
                    $key                                         = long2ip($address['local_ip']) . ":{$address['local_port']}";
                    $data_array[$key][$address['connection_id']] = $address['connection_id'];
                }
            }
            foreach ($data_array as $addr => $connection_id_list) {
                $the_gateway_data             = $gateway_data;
                $the_gateway_data['ext_data'] = json_encode(array('connections' => $connection_id_list));
                static::sendToGateway($addr, $the_gateway_data);
            }
            return;
        } elseif (empty($client_id_array) && is_array($client_id_array)) {
            return;
        }

        if (!$exclude_client_id) {
            return static::sendToAllGateway($gateway_data);
        }

        $address_connection_array = static::clientIdArrayToAddressArray($exclude_client_id);

        // 如果有businessWorker实例，说明运行在workerman环境中，通过businessWorker中的长连接发送数据
        if (static::$businessWorker) {
            foreach (static::$businessWorker->gatewayConnections as $address => $gateway_connection) {
                $gateway_data['ext_data'] = isset($address_connection_array[$address]) ?
                    json_encode(array('exclude'=> $address_connection_array[$address])) : '';
                /** @var TcpConnection $gateway_connection */
                $gateway_connection->send($gateway_data);
            }
        } // 运行在其它环境中，通过注册中心得到gateway地址
        else {
            $all_addresses = static::getAllGatewayAddressesFromRegister();
            foreach ($all_addresses as $address) {
                $gateway_data['ext_data'] = isset($address_connection_array[$address]) ?
                    json_encode(array('exclude'=> $address_connection_array[$address])) : '';
                static::sendToGateway($address, $gateway_data);
            }
        }

    }

    /**
     * 向某个client_id对应的连接发消息
     *
     * @param string    $client_id
     * @param string $message
     * @return void
     */
    public static function sendToClient($client_id, $message)
    {
        return static::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_SEND_TO_ONE, $message);
    }

    /**
     * 判断某个uid是否在线
     *
     * @param string $uid
     * @return int 0|1
     */
    public static function isUidOnline($uid)
    {
        return (int)static::getClientIdByUid($uid);
    }
    
    /**
     * 判断client_id对应的连接是否在线
     *
     * @param string $client_id
     * @return int 0|1
     */
    public static function isOnline($client_id)
    {
        $address_data = Context::clientIdToAddress($client_id);
        if (!$address_data) {
            return 0;
        }
        $address      = long2ip($address_data['local_ip']) . ":{$address_data['local_port']}";
        if (isset(static::$businessWorker)) {
            if (!isset(static::$businessWorker->gatewayConnections[$address])) {
                return 0;
            }
        }
        $gateway_data                  = GatewayProtocol::$empty;
        $gateway_data['cmd']           = GatewayProtocol::CMD_IS_ONLINE;
        $gateway_data['connection_id'] = $address_data['connection_id'];
        return (int)static::sendAndRecv($address, $gateway_data);
    }

    /**
     * 获取所有在线用户的session，client_id为 key(弃用，请用getAllClientSessions代替)
     *
     * @param string $group
     * @return array
     */
    public static function getAllClientInfo($group = '')
    {
        echo "Warning: Gateway::getAllClientInfo is deprecated and will be removed in a future, please use Gateway::getAllClientSessions instead.";
        return static::getAllClientSessions($group);
    }

    /**
     * 获取所有在线client_id的session，client_id为 key
     *
     * @param string $group
     * @return array
     */
    public static function getAllClientSessions($group = '')
    {
        $gateway_data = GatewayProtocol::$empty;
        if (!$group) {
            $gateway_data['cmd']      = GatewayProtocol::CMD_GET_ALL_CLIENT_SESSIONS;
        } else {
            $gateway_data['cmd']      = GatewayProtocol::CMD_GET_CLIENT_SESSIONS_BY_GROUP;
            $gateway_data['ext_data'] = $group;
        }
        $status_data      = array();
        $all_buffer_array = static::getBufferFromAllGateway($gateway_data);
        foreach ($all_buffer_array as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $data) {
                if ($data) {
                    foreach ($data as $connection_id => $session_buffer) {
                        $client_id = Context::addressToClientId($local_ip, $local_port, $connection_id);
                        if ($client_id === Context::$client_id) {
                            $status_data[$client_id] = (array)$_SESSION;
                        } else {
                            $status_data[$client_id] = $session_buffer ? Context::sessionDecode($session_buffer) : array();
                        }
                    }
                }
            }
        }
        return $status_data;
    }

    /**
     * 获取某个组的连接信息（弃用，请用getClientSessionsByGroup代替）
     *
     * @param string $group
     * @return array
     */
    public static function getClientInfoByGroup($group)
    {
        echo "Warning: Gateway::getClientInfoByGroup is deprecated and will be removed in a future, please use Gateway::getClientSessionsByGroup instead.";
        return static::getAllClientSessions($group);
    }

    /**
     * 获取某个组的所有client_id的session信息
     *
     * @param string $group
     *
     * @return array
     */
    public static function getClientSessionsByGroup($group)
    {
        if (static::isValidGroupId($group)) {
            return static::getAllClientSessions($group);
        }
        return array();
    }

    /**
     * 获取所有在线client_id数
     *
     * @return int
     */
    public static function getAllClientIdCount()
    {
        return static::getClientCountByGroup();
    }

    /**
     * 获取所有在线client_id数(getAllClientIdCount的别名)
     *
     * @return int
     */
    public static function getAllClientCount()
    {
        return static::getAllClientIdCount();
    }

    /**
     * 获取某个组的在线client_id数
     *
     * @param string $group
     * @return int
     */
    public static function getClientIdCountByGroup($group = '')
    {
        $gateway_data             = GatewayProtocol::$empty;
        $gateway_data['cmd']      = GatewayProtocol::CMD_GET_CLIENT_COUNT_BY_GROUP;
        $gateway_data['ext_data'] = $group;
        $total_count              = 0;
        $all_buffer_array         = static::getBufferFromAllGateway($gateway_data);
        foreach ($all_buffer_array as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $count) {
                if ($count) {
                    $total_count += $count;
                }
            }
        }
        return $total_count;
    }

    /**
     * getClientIdCountByGroup 函数的别名
     *
     * @param string $group
     * @return int
     */
    public static function getClientCountByGroup($group = '')
    {
        return static::getClientIdCountByGroup($group);
    }

    /**
     * 获取某个群组在线client_id列表
     *
     * @param string $group
     * @return array
     */
    public static function getClientIdListByGroup($group)
    {
        if (!static::isValidGroupId($group)) {
            return array();
        }

        $data = static::select(array('uid'), array('groups' => is_array($group) ? $group : array($group)));
        $client_id_map = array();
        foreach ($data as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $items) {
                //$items = ['connection_id'=>['uid'=>x, 'group'=>[x,x..], 'session'=>[..]], 'client_id'=>[..], ..];
                foreach ($items as $connection_id => $info) {
                    $client_id = Context::addressToClientId($local_ip, $local_port, $connection_id);
                    $client_id_map[$client_id] = $client_id;
                }
            }
        }
        return $client_id_map;
    }

    /**
     * 获取集群所有在线client_id列表
     *
     * @return array
     */
    public static function getAllClientIdList()
    {
        return static::formatClientIdFromGatewayBuffer(static::select(array('uid')));
    }

    /**
     * 格式化client_id
     *
     * @param $data
     * @return array
     */
    protected static function formatClientIdFromGatewayBuffer($data)
    {
        $client_id_list = array();
        foreach ($data as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $items) {
                //$items = ['connection_id'=>['uid'=>x, 'group'=>[x,x..], 'session'=>[..]], 'client_id'=>[..], ..];
                foreach ($items as $connection_id => $info) {
                    $client_id = Context::addressToClientId($local_ip, $local_port, $connection_id);
                    $client_id_list[$client_id] = $client_id;
                }
            }
        }
        return $client_id_list;
    }


    /**
     * 获取与 uid 绑定的 client_id 列表
     *
     * @param string $uid
     * @return array
     */
    public static function getClientIdByUid($uid)
    {
        $gateway_data             = GatewayProtocol::$empty;
        $gateway_data['cmd']      = GatewayProtocol::CMD_GET_CLIENT_ID_BY_UID;
        $gateway_data['ext_data'] = $uid;
        $client_list              = array();
        $all_buffer_array         = static::getBufferFromAllGateway($gateway_data);
        foreach ($all_buffer_array as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $connection_id_array) {
                if ($connection_id_array) {
                    foreach ($connection_id_array as $connection_id) {
                        $client_list[] = Context::addressToClientId($local_ip, $local_port, $connection_id);
                    }
                }
            }
        }
        return $client_list;
    }

    /**
     * 获取某个群组在线uid列表
     *
     * @param string $group
     * @return array
     */
    public static function getUidListByGroup($group)
    {
        if (!static::isValidGroupId($group)) {
            return array();
        }

        $group = is_array($group) ? $group : array($group);
        $data = static::select(array('uid'), array('groups' => $group));
        $uid_map = array();
        foreach ($data as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $items) {
                //$items = ['connection_id'=>['uid'=>x, 'group'=>[x,x..], 'session'=>[..]], 'client_id'=>[..], ..];
                foreach ($items as $connection_id => $info) {
                    if (!empty($info['uid'])) {
                        $uid_map[$info['uid']] = $info['uid'];
                    }
                }
            }
        }
        return $uid_map;
    }

    /**
     * 获取某个群组在线uid数
     *
     * @param string $group
     * @return int
     */
    public static function getUidCountByGroup($group)
    {
        if (static::isValidGroupId($group)) {
            return count(static::getUidListByGroup($group));
        }
        return 0;
    }

    /**
     * 获取全局在线uid列表
     *
     * @return array
     */
    public static function getAllUidList()
    {
        $data = static::select(array('uid'));
        $uid_map = array();
        foreach ($data as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $items) {
                //$items = ['connection_id'=>['uid'=>x, 'group'=>[x,x..], 'session'=>[..]], 'client_id'=>[..], ..];
                foreach ($items as $connection_id => $info) {
                    if (!empty($info['uid'])) {
                        $uid_map[$info['uid']] = $info['uid'];
                    }
                }
            }
        }
        return $uid_map;
    }

    /**
     * 获取全局在线uid数
     * @return int
     */
    public static function getAllUidCount()
    {
        return count(static::getAllUidList());
    }

    /**
     * 通过client_id获取uid
     *
     * @param $client_id
     * @return mixed
     */
    public static function getUidByClientId($client_id)
    {
        $data = static::select(array('uid'), array('client_id'=>array($client_id)));
        foreach ($data as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $items) {
                //$items = ['connection_id'=>['uid'=>x, 'group'=>[x,x..], 'session'=>[..]], 'client_id'=>[..], ..];
                foreach ($items as $info) {
                    return $info['uid'];
                }
            }
        }
    }

    /**
     * 获取所有在线的群组id
     *
     * @return array
     */
    public static function getAllGroupIdList()
    {
        $gateway_data             = GatewayProtocol::$empty;
        $gateway_data['cmd']      = GatewayProtocol::CMD_GET_GROUP_ID_LIST;
        $group_id_list            = array();
        $all_buffer_array         = static::getBufferFromAllGateway($gateway_data);
        foreach ($all_buffer_array as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $group_id_array) {
                if (is_array($group_id_array)) {
                    foreach ($group_id_array as $group_id) {
                        if (!isset($group_id_list[$group_id])) {
                            $group_id_list[$group_id] = $group_id;
                        }
                    }
                }
            }
        }
        return $group_id_list;
    }


    /**
     * 获取所有在线分组的uid数量，也就是每个分组的在线用户数
     *
     * @return array
     */
    public static function getAllGroupUidCount()
    {
        $group_uid_map = static::getAllGroupUidList();
        $group_uid_count_map = array();
        foreach ($group_uid_map as $group_id => $uid_list) {
            $group_uid_count_map[$group_id] = count($uid_list);
        }
        return $group_uid_count_map;
    }



    /**
     * 获取所有分组uid在线列表
     *
     * @return array
     */
    public static function getAllGroupUidList()
    {
        $data = static::select(array('uid','groups'));
        $group_uid_map = array();
        foreach ($data as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $items) {
                //$items = ['connection_id'=>['uid'=>x, 'group'=>[x,x..], 'session'=>[..]], 'client_id'=>[..], ..];
                foreach ($items as $connection_id => $info) {
                    if (empty($info['uid']) || empty($info['groups'])) {
                        break;
                    }
                    $uid = $info['uid'];
                    foreach ($info['groups'] as $group_id) {
                        if(!isset($group_uid_map[$group_id])) {
                            $group_uid_map[$group_id] = array();
                        }
                        $group_uid_map[$group_id][$uid] = $uid;
                    }
                }
            }
        }
        return $group_uid_map;
    }

    /**
     * 获取所有群组在线client_id列表
     *
     * @return array
     */
    public static function getAllGroupClientIdList()
    {
        $data = static::select(array('groups'));
        $group_client_id_map = array();
        foreach ($data as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $items) {
                //$items = ['connection_id'=>['uid'=>x, 'group'=>[x,x..], 'session'=>[..]], 'client_id'=>[..], ..];
                foreach ($items as $connection_id => $info) {
                    if (empty($info['groups'])) {
                        break;
                    }
                    $client_id = Context::addressToClientId($local_ip, $local_port, $connection_id);
                    foreach ($info['groups'] as $group_id) {
                        if(!isset($group_client_id_map[$group_id])) {
                            $group_client_id_map[$group_id] = array();
                        }
                        $group_client_id_map[$group_id][$client_id] = $client_id;
                    }
                }
            }
        }
        return $group_client_id_map;
    }

    /**
     * 获取所有群组在线client_id数量，也就是获取每个群组在线连接数
     *
     * @return array
     */
    public static function getAllGroupClientIdCount()
    {
        $group_client_map = static::getAllGroupClientIdList();
        $group_client_count_map = array();
        foreach ($group_client_map as $group_id => $client_id_list) {
            $group_client_count_map[$group_id] = count($client_id_list);
        }
        return $group_client_count_map;
    }


    /**
     * 根据条件到gateway搜索数据
     *
     * @param array $fields
     * @param array $where
     * @return array
     */
    protected static function select($fields = array('session','uid','groups'), $where = array())
    {
        $t = microtime(true);
        $gateway_data             = GatewayProtocol::$empty;
        $gateway_data['cmd']      = GatewayProtocol::CMD_SELECT;
        $gateway_data['ext_data'] = array('fields' => $fields, 'where' => $where);
        $gateway_data_list   = array();
        // 有client_id，能计算出需要和哪些gateway通讯，只和必要的gateway通讯能降低系统负载
        if (isset($where['client_id'])) {
            $client_id_list = $where['client_id'];
            unset($gateway_data['ext_data']['where']['client_id']);
            $gateway_data['ext_data']['where']['connection_id'] = array();
            foreach ($client_id_list as $client_id) {
                $address_data = Context::clientIdToAddress($client_id);
                if (!$address_data) {
                    continue;
                }
                $address = long2ip($address_data['local_ip']) . ":{$address_data['local_port']}";
                if (!isset($gateway_data_list[$address])) {
                    $gateway_data_list[$address] = $gateway_data;
                }
                $gateway_data_list[$address]['ext_data']['where']['connection_id'][$address_data['connection_id']] = $address_data['connection_id'];
            }
            foreach ($gateway_data_list as $address => $item) {
                $gateway_data_list[$address]['ext_data'] = json_encode($item['ext_data']);
            }
            // 有其它条件，则还是需要向所有gateway发送
            if (count($where) !== 1) {
                $gateway_data['ext_data'] = json_encode($gateway_data['ext_data']);
                foreach (static::getAllGatewayAddress() as $address) {
                    if (!isset($gateway_data_list[$address])) {
                        $gateway_data_list[$address] = $gateway_data;
                    }
                }
            }
            $data = static::getBufferFromSomeGateway($gateway_data_list);
        } else {
            $gateway_data['ext_data'] = json_encode($gateway_data['ext_data']);
            $data = static::getBufferFromAllGateway($gateway_data);
        }

        return $data;
    }

    /**
     * 生成验证包，用于验证此客户端的合法性
     * 
     * @return string
     */
    protected static function generateAuthBuffer()
    {
        $gateway_data         = GatewayProtocol::$empty;
        $gateway_data['cmd']  = GatewayProtocol::CMD_GATEWAY_CLIENT_CONNECT;
        $gateway_data['body'] = json_encode(array(
            'secret_key' => static::$secretKey,
        ));
        return GatewayProtocol::encode($gateway_data);
    }

    /**
     * 批量向某些gateway发包，并得到返回数组
     *
     * @param array $gateway_data_array
     * @return array
     * @throws Exception
     */
    protected static function getBufferFromSomeGateway($gateway_data_array)
    {
        $gateway_buffer_array = array();
        $auth_buffer = static::$secretKey ? static::generateAuthBuffer() : '';
        foreach ($gateway_data_array as $address => $gateway_data) {
            if ($auth_buffer) {
                $gateway_buffer_array[$address] = $auth_buffer.GatewayProtocol::encode($gateway_data);
            } else {
                $gateway_buffer_array[$address] = GatewayProtocol::encode($gateway_data);
            }
        }
        return static::getBufferFromGateway($gateway_buffer_array);
    }

    /**
     * 批量向所有 gateway 发包，并得到返回数组
     *
     * @param string $gateway_data
     * @return array
     * @throws Exception
     */
    protected static function getBufferFromAllGateway($gateway_data)
    {
        $addresses = static::getAllGatewayAddress();
        $gateway_buffer_array = array();
        $gateway_buffer = GatewayProtocol::encode($gateway_data);
        $gateway_buffer = static::$secretKey ? static::generateAuthBuffer() . $gateway_buffer : $gateway_buffer;
        foreach ($addresses as $address) {
            $gateway_buffer_array[$address] = $gateway_buffer;
        }

        return static::getBufferFromGateway($gateway_buffer_array);
    }

    /**
     * 获取所有gateway内部通讯地址
     *
     * @return array
     * @throws Exception
     */
    protected static function getAllGatewayAddress()
    {
        if (isset(static::$businessWorker)) {
            $addresses = static::$businessWorker->getAllGatewayAddresses();
            if (empty($addresses)) {
                throw new Exception('businessWorker::getAllGatewayAddresses return empty');
            }
        } else {
            $addresses = static::getAllGatewayAddressesFromRegister();
            if (empty($addresses)) {
                return array();
            }
        }
        return $addresses;
    }

    /**
     * 批量向gateway发送并获取数据
     * @param $gateway_buffer_array
     * @return array
     */
    protected static function getBufferFromGateway($gateway_buffer_array)
    {
        $client_array = $status_data = $client_address_map = $receive_buffer_array = $recv_length_array = array();
        // 批量向所有gateway进程发送请求数据
        foreach ($gateway_buffer_array as $address => $gateway_buffer) {
            $client = stream_socket_client("tcp://$address", $errno, $errmsg, static::$connectTimeout);
            if ($client && strlen($gateway_buffer) === stream_socket_sendto($client, $gateway_buffer)) {
                $socket_id                        = (int)$client;
                $client_array[$socket_id]         = $client;
                $client_address_map[$socket_id]   = explode(':', $address);
                $receive_buffer_array[$socket_id] = '';
            }
        }
        // 超时5秒
        $timeout    = 5;
        $time_start = microtime(true);
        // 批量接收请求
        while (count($client_array) > 0) {
            $write = $except = array();
            $read  = $client_array;
            if (@stream_select($read, $write, $except, $timeout)) {
                foreach ($read as $client) {
                    $socket_id = (int)$client;
                    $buffer    = stream_socket_recvfrom($client, 65535);
                    if ($buffer !== '' && $buffer !== false) {
                        $receive_buffer_array[$socket_id] .= $buffer;
                        $receive_length = strlen($receive_buffer_array[$socket_id]);
                        if (empty($recv_length_array[$socket_id]) && $receive_length >= 4) {
                            $recv_length_array[$socket_id] = current(unpack('N', $receive_buffer_array[$socket_id]));
                        }
                        if (!empty($recv_length_array[$socket_id]) && $receive_length >= $recv_length_array[$socket_id] + 4) {
                            unset($client_array[$socket_id]);
                        }
                    } elseif (feof($client)) {
                        unset($client_array[$socket_id]);
                    }
                }
            }
            if (microtime(true) - $time_start > $timeout) {
                break;
            }
        }
        $format_buffer_array = array();
        foreach ($receive_buffer_array as $socket_id => $buffer) {
            $local_ip                                    = ip2long($client_address_map[$socket_id][0]);
            $local_port                                  = $client_address_map[$socket_id][1];
            $format_buffer_array[$local_ip][$local_port] = unserialize(substr($buffer, 4));
        }
        return $format_buffer_array;
    }

    /**
     * 踢掉某个客户端，并以$message通知被踢掉客户端
     *
     * @param string $client_id
     * @param string $message
     * @return void
     */
    public static function closeClient($client_id, $message = null)
    {
        if ($client_id === Context::$client_id) {
            return static::closeCurrentClient($message);
        } // 不是发给当前用户则使用存储中的地址
        else {
            $address_data = Context::clientIdToAddress($client_id);
            if (!$address_data) {
                return false;
            }
            $address      = long2ip($address_data['local_ip']) . ":{$address_data['local_port']}";
            return static::kickAddress($address, $address_data['connection_id'], $message);
        }
    }

    /**
     * 踢掉某个客户端并直接立即销毁相关连接
     *
     * @param string $client_id
     * @return bool
     */
    public static function destoryClient($client_id)
    {
        if ($client_id === Context::$client_id) {
            return static::destoryCurrentClient();
        } // 不是发给当前用户则使用存储中的地址
        else {
            $address_data = Context::clientIdToAddress($client_id);
            if (!$address_data) {
                return false;
            }
            $address = long2ip($address_data['local_ip']) . ":{$address_data['local_port']}";
            return static::destroyAddress($address, $address_data['connection_id']);
        }
    }

    /**
     * 踢掉当前客户端并直接立即销毁相关连接
     *
     * @return bool
     * @throws Exception
     */
    public static function destoryCurrentClient()
    {
        if (!Context::$connection_id) {
            throw new Exception('destoryCurrentClient can not be called in async context');
        }
        $address = long2ip(Context::$local_ip) . ':' . Context::$local_port;
        return static::destroyAddress($address, Context::$connection_id);
    }

    /**
     * 将 client_id 与 uid 绑定
     *
     * @param string        $client_id
     * @param int|string $uid
     * @return void
     */
    public static function bindUid($client_id, $uid)
    {
        static::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_BIND_UID, '', $uid);
    }

    /**
     * 将 client_id 与 uid 解除绑定
     *
     * @param string        $client_id
     * @param int|string $uid
     * @return void
     */
    public static function unbindUid($client_id, $uid)
    {
        static::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_UNBIND_UID, '', $uid);
    }

    /**
     * 将 client_id 加入组
     *
     * @param string        $client_id
     * @param int|string $group
     * @return void
     */
    public static function joinGroup($client_id, $group)
    {

        static::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_JOIN_GROUP, '', $group);
    }

    /**
     * 将 client_id 离开组
     *
     * @param string        $client_id
     * @param int|string $group
     *
     * @return void
     */
    public static function leaveGroup($client_id, $group)
    {
        static::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_LEAVE_GROUP, '', $group);
    }

    /**
     * 取消分组
     *
     * @param int|string $group
     *
     * @return void
     */
    public static function ungroup($group)
    {
        if (!static::isValidGroupId($group)) {
            return false;
        }
        $gateway_data             = GatewayProtocol::$empty;
        $gateway_data['cmd']      = GatewayProtocol::CMD_UNGROUP;
        $gateway_data['ext_data'] = $group;
        return static::sendToAllGateway($gateway_data);

    }

    /**
     * 向所有 uid 发送
     *
     * @param int|string|array $uid
     * @param string           $message
     *
     * @return void
     */
    public static function sendToUid($uid, $message)
    {
        $gateway_data         = GatewayProtocol::$empty;
        $gateway_data['cmd']  = GatewayProtocol::CMD_SEND_TO_UID;
        $gateway_data['body'] = $message;

        if (!is_array($uid)) {
            $uid = array($uid);
        }

        $gateway_data['ext_data'] = json_encode($uid);

        static::sendToAllGateway($gateway_data);
    }

    /**
     * 向 group 发送
     *
     * @param int|string|array $group             组（不允许是 0 '0' false null array()等为空的值）
     * @param string           $message           消息
     * @param array            $exclude_client_id 不给这些client_id发
     * @param bool             $raw               发送原始数据（即不调用gateway的协议的encode方法）
     *
     * @return void
     */
    public static function sendToGroup($group, $message, $exclude_client_id = null, $raw = false)
    {
        if (!static::isValidGroupId($group)) {
            return false;
        }
        $gateway_data         = GatewayProtocol::$empty;
        $gateway_data['cmd']  = GatewayProtocol::CMD_SEND_TO_GROUP;
        $gateway_data['body'] = $message;
        if ($raw) {
            $gateway_data['flag'] |= GatewayProtocol::FLAG_NOT_CALL_ENCODE;
        }

        if (!is_array($group)) {
            $group = array($group);
        }

        // 分组发送，没有排除的client_id，直接发送
        $default_ext_data_buffer = json_encode(array('group'=> $group, 'exclude'=> null));
        if (empty($exclude_client_id)) {
            $gateway_data['ext_data'] = $default_ext_data_buffer;
            return static::sendToAllGateway($gateway_data);
        }

        // 分组发送，有排除的client_id，需要将client_id转换成对应gateway进程内的connectionId
        if (!is_array($exclude_client_id)) {
            $exclude_client_id = array($exclude_client_id);
        }

        $address_connection_array = static::clientIdArrayToAddressArray($exclude_client_id);
        // 如果有businessWorker实例，说明运行在workerman环境中，通过businessWorker中的长连接发送数据
        if (static::$businessWorker) {
            foreach (static::$businessWorker->gatewayConnections as $address => $gateway_connection) {
                $gateway_data['ext_data'] = isset($address_connection_array[$address]) ?
                    json_encode(array('group'=> $group, 'exclude'=> $address_connection_array[$address])) :
                    $default_ext_data_buffer;
                /** @var TcpConnection $gateway_connection */
                $gateway_connection->send($gateway_data);
            }
        } // 运行在其它环境中，通过注册中心得到gateway地址
        else {
            $addresses = static::getAllGatewayAddressesFromRegister();
            foreach ($addresses as $address) {
                $gateway_data['ext_data'] = isset($address_connection_array[$address]) ?
                    json_encode(array('group'=> $group, 'exclude'=> $address_connection_array[$address])) :
                    $default_ext_data_buffer;
                static::sendToGateway($address, $gateway_data);
            }
        }
    }

    /**
     * 更新 session，框架自动调用，开发者不要调用
     *
     * @param string    $client_id
     * @param string $session_str
     * @return bool
     */
    public static function setSocketSession($client_id, $session_str)
    {
        return static::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_SET_SESSION, '', $session_str);
    }

    /**
     * 设置 session，原session值会被覆盖
     *
     * @param string   $client_id
     * @param array $session
     *
     * @return void
     */
    public static function setSession($client_id, array $session)
    {
        if (Context::$client_id === $client_id) {
            $_SESSION = $session;
            Context::$old_session = $_SESSION;
        }
        static::setSocketSession($client_id, Context::sessionEncode($session));
    }
    
    /**
     * 更新 session，实际上是与老的session合并
     *
     * @param string   $client_id
     * @param array $session
     *
     * @return void
     */
    public static function updateSession($client_id, array $session)
    {
        if (Context::$client_id === $client_id) {
            $_SESSION = array_replace_recursive((array)$_SESSION, $session);
            Context::$old_session = $_SESSION;
        }
        static::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_UPDATE_SESSION, '', Context::sessionEncode($session));
    }
    
    /**
     * 获取某个client_id的session
     *
     * @param string   $client_id
     * @return mixed false表示出错、null表示用户不存在、array表示具体的session信息 
     */
    public static function getSession($client_id)
    {
        $address_data = Context::clientIdToAddress($client_id);
        if (!$address_data) {
            return false;
        }
        $address      = long2ip($address_data['local_ip']) . ":{$address_data['local_port']}";
        if (isset(static::$businessWorker)) {
            if (!isset(static::$businessWorker->gatewayConnections[$address])) {
                return null;
            }
        }
        $gateway_data                  = GatewayProtocol::$empty;
        $gateway_data['cmd']           = GatewayProtocol::CMD_GET_SESSION_BY_CLIENT_ID;
        $gateway_data['connection_id'] = $address_data['connection_id'];
        return static::sendAndRecv($address, $gateway_data);
    }

    /**
     * 向某个用户网关发送命令和消息
     *
     * @param string    $client_id
     * @param int    $cmd
     * @param string $message
     * @param string $ext_data
     * @return boolean
     */
    protected static function sendCmdAndMessageToClient($client_id, $cmd, $message, $ext_data = '')
    {
        // 如果是发给当前用户则直接获取上下文中的地址
        if ($client_id === Context::$client_id || $client_id === null) {
            $address       = long2ip(Context::$local_ip) . ':' . Context::$local_port;
            $connection_id = Context::$connection_id;
        } else {
            $address_data  = Context::clientIdToAddress($client_id);
            if (!$address_data) {
                return false;
            }
            $address       = long2ip($address_data['local_ip']) . ":{$address_data['local_port']}";
            $connection_id = $address_data['connection_id'];
        }
        $gateway_data                  = GatewayProtocol::$empty;
        $gateway_data['cmd']           = $cmd;
        $gateway_data['connection_id'] = $connection_id;
        $gateway_data['body']          = $message;
        if (!empty($ext_data)) {
            $gateway_data['ext_data'] = $ext_data;
        }

        return static::sendToGateway($address, $gateway_data);
    }

    /**
     * 发送数据并返回
     *
     * @param int   $address
     * @param mixed $data
     * @return bool
     * @throws Exception
     */
    protected static function sendAndRecv($address, $data)
    {
        $buffer = GatewayProtocol::encode($data);
        $buffer = static::$secretKey ? static::generateAuthBuffer() . $buffer : $buffer;
        $client = stream_socket_client("tcp://$address", $errno, $errmsg, static::$connectTimeout);
        if (!$client) {
            throw new Exception("can not connect to tcp://$address $errmsg");
        }
        if (strlen($buffer) === stream_socket_sendto($client, $buffer)) {
            $timeout = 5;
            // 阻塞读
            stream_set_blocking($client, 1);
            // 1秒超时
            stream_set_timeout($client, 1);
            $all_buffer = '';
            $time_start = microtime(true);
            $pack_len = 0;
            while (1) {
                $buf = stream_socket_recvfrom($client, 655350);
                if ($buf !== '' && $buf !== false) {
                    $all_buffer .= $buf;
                } else {
                    if (feof($client)) {
                        throw new Exception("connection close tcp://$address");
                    } elseif (microtime(true) - $time_start > $timeout) {
                        break;
                    }
                    continue;
                }
                $recv_len = strlen($all_buffer);
                if (!$pack_len && $recv_len >= 4) {
                    $pack_len= current(unpack('N', $all_buffer));
                }
                // 回复的数据都是以\n结尾
                if (($pack_len && $recv_len >= $pack_len + 4) || microtime(true) - $time_start > $timeout) {
                    break;
                }
            }
            // 返回结果
            return unserialize(substr($all_buffer, 4));
        } else {
            throw new Exception("sendAndRecv($address, \$bufer) fail ! Can not send data!", 502);
        }
    }

    /**
     * 发送数据到网关
     *
     * @param string $address
     * @param array  $gateway_data
     * @return bool
     */
    protected static function sendToGateway($address, $gateway_data)
    {
        return static::sendBufferToGateway($address, GatewayProtocol::encode($gateway_data));
    }

    /**
     * 发送buffer数据到网关
     * @param string $address
     * @param string $gateway_buffer
     * @return bool
     */
    protected static function sendBufferToGateway($address, $gateway_buffer)
    {
        // 有$businessWorker说明是workerman环境，使用$businessWorker发送数据
        if (static::$businessWorker) {
            if (!isset(static::$businessWorker->gatewayConnections[$address])) {
                return false;
            }
            return static::$businessWorker->gatewayConnections[$address]->send($gateway_buffer, true);
        }
        // 非workerman环境
        $gateway_buffer = static::$secretKey ? static::generateAuthBuffer() . $gateway_buffer : $gateway_buffer;
        $flag           = static::$persistentConnection ? STREAM_CLIENT_PERSISTENT | STREAM_CLIENT_CONNECT : STREAM_CLIENT_CONNECT;
        $client         = stream_socket_client("tcp://$address", $errno, $errmsg, static::$connectTimeout, $flag);
        return strlen($gateway_buffer) == stream_socket_sendto($client, $gateway_buffer);
    }

    /**
     * 向所有 gateway 发送数据
     *
     * @param string $gateway_data
     * @throws Exception
     *
     * @return void
     */
    protected static function sendToAllGateway($gateway_data)
    {
        $buffer = GatewayProtocol::encode($gateway_data);
        // 如果有businessWorker实例，说明运行在workerman环境中，通过businessWorker中的长连接发送数据
        if (static::$businessWorker) {
            foreach (static::$businessWorker->gatewayConnections as $gateway_connection) {
                /** @var TcpConnection $gateway_connection */
                $gateway_connection->send($buffer, true);
            }
        } // 运行在其它环境中，通过注册中心得到gateway地址
        else {
            $all_addresses = static::getAllGatewayAddressesFromRegister();
            foreach ($all_addresses as $address) {
                static::sendBufferToGateway($address, $buffer);
            }
        }
    }

    /**
     * 踢掉某个网关的 socket
     *
     * @param string $address
     * @param int    $connection_id
     * @return bool
     */
    protected static function kickAddress($address, $connection_id, $message)
    {
        $gateway_data                  = GatewayProtocol::$empty;
        $gateway_data['cmd']           = GatewayProtocol::CMD_KICK;
        $gateway_data['connection_id'] = $connection_id;
        $gateway_data['body'] = $message;
        return static::sendToGateway($address, $gateway_data);
    }

    /**
     * 销毁某个网关的 socket
     *
     * @param string $address
     * @param int    $connection_id
     * @return bool
     */
    protected static function destroyAddress($address, $connection_id)
    {
        $gateway_data                  = GatewayProtocol::$empty;
        $gateway_data['cmd']           = GatewayProtocol::CMD_DESTROY;
        $gateway_data['connection_id'] = $connection_id;
        return static::sendToGateway($address, $gateway_data);
    }

    /**
     * 将clientid数组转换成address数组
     *
     * @param array $client_id_array
     * @return array
     */
    protected static function clientIdArrayToAddressArray(array $client_id_array)
    {
        $address_connection_array = array();
        foreach ($client_id_array as $client_id) {
            $address_data = Context::clientIdToAddress($client_id);
            if ($address_data) {
                $address                                                            = long2ip($address_data['local_ip']) .
                    ":{$address_data['local_port']}";
                $address_connection_array[$address][$address_data['connection_id']] = $address_data['connection_id'];
            }
        }
        return $address_connection_array;
    }

    /**
     * 设置 gateway 实例
     *
     * @param \GatewayWorker\BusinessWorker $business_worker_instance
     */
    public static function setBusinessWorker($business_worker_instance)
    {
        static::$businessWorker = $business_worker_instance;
    }

    /**
     * 获取通过注册中心获取所有 gateway 通讯地址
     *
     * @return array
     * @throws Exception
     */
    protected static function getAllGatewayAddressesFromRegister()
    {
        static $addresses_cache, $last_update;
        if (static::$addressesCacheDisable) {
            $addresses_cache = null;
        }
        $time_now = time();
        $expiration_time = 1;
        $register_addresses = (array)static::$registerAddress;
        $client = null;
        if(empty($addresses_cache) || $time_now - $last_update > $expiration_time) {
            foreach ($register_addresses as $register_address) {
                set_error_handler(function(){});
                $client = stream_socket_client('tcp://' . $register_address, $errno, $errmsg, static::$connectTimeout);
                restore_error_handler();
                if ($client) {
                    break;
                }
            }
            if (!$client) {
                throw new Exception('Can not connect to tcp://' . $register_address . ' ' . $errmsg);
            }

            fwrite($client, '{"event":"worker_connect","secret_key":"' . static::$secretKey . '"}' . "\n");
            stream_set_timeout($client, 5);
            $ret = fgets($client, 655350);
            if (!$ret || !$data = json_decode(trim($ret), true)) {
                throw new Exception('getAllGatewayAddressesFromRegister fail. tcp://' .
                    $register_address . ' return ' . var_export($ret, true));
            }
            $last_update = $time_now;
            $addresses_cache = $data['addresses'];
        }
        if (!$addresses_cache) {
            throw new Exception('Gateway::getAllGatewayAddressesFromRegister() with registerAddress:' .
                json_encode(static::$registerAddress) . '  return ' . var_export($addresses_cache, true));
        }
        return $addresses_cache;
    }

    /**
     * 检查群组id是否合法
     *
     * @param $group
     * @return bool
     */
    protected static function isValidGroupId($group)
    {
        if (empty($group)) {
            echo new \Exception('group('.var_export($group, true).') empty');
            return false;
        }
        return true;
    }
}


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
        if($session_data !== '')
        {
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
        if(strlen($client_id) !== 20)
        {
            throw new \Exception("client_id $client_id is invalid");
        }
        return unpack('Nlocal_ip/nlocal_port/Nconnection_id' ,pack('H*', $client_id));
    }

}


/**
 * Gateway 与 Worker 间通讯的二进制协议
 *
 * struct GatewayProtocol
 * {
 *     unsigned int        pack_len,
 *     unsigned char       cmd,//命令字
 *     unsigned int        local_ip,
 *     unsigned short      local_port,
 *     unsigned int        client_ip,
 *     unsigned short      client_port,
 *     unsigned int        connection_id,
 *     unsigned char       flag,
 *     unsigned short      gateway_port,
 *     unsigned int        ext_len,
 *     char[ext_len]       ext_data,
 *     char[pack_length-HEAD_LEN] body//包体
 * }
 * NCNnNnNCnN
 */
class GatewayProtocol
{
    // 发给worker，gateway有一个新的连接
    const CMD_ON_CONNECT = 1;
    // 发给worker的，客户端有消息
    const CMD_ON_MESSAGE = 3;
    // 发给worker上的关闭链接事件
    const CMD_ON_CLOSE = 4;
    // 发给gateway的向单个用户发送数据
    const CMD_SEND_TO_ONE = 5;
    // 发给gateway的向所有用户发送数据
    const CMD_SEND_TO_ALL = 6;
    // 发给gateway的踢出用户
    // 1、如果有待发消息，将在发送完后立即销毁用户连接
    // 2、如果无待发消息，将立即销毁用户连接
    const CMD_KICK = 7;
    // 发给gateway的立即销毁用户连接
    const CMD_DESTROY = 8;
    // 发给gateway，通知用户session更新
    const CMD_UPDATE_SESSION = 9;
    // 获取在线状态
    const CMD_GET_ALL_CLIENT_SESSIONS = 10;
    // 判断是否在线
    const CMD_IS_ONLINE = 11;
    // client_id绑定到uid
    const CMD_BIND_UID = 12;
    // 解绑
    const CMD_UNBIND_UID = 13;
    // 向uid发送数据
    const CMD_SEND_TO_UID = 14;
    // 根据uid获取绑定的clientid
    const CMD_GET_CLIENT_ID_BY_UID = 15;
    // 加入组
    const CMD_JOIN_GROUP = 20;
    // 离开组
    const CMD_LEAVE_GROUP = 21;
    // 向组成员发消息
    const CMD_SEND_TO_GROUP = 22;
    // 获取组成员
    const CMD_GET_CLIENT_SESSIONS_BY_GROUP = 23;
    // 获取组在线连接数
    const CMD_GET_CLIENT_COUNT_BY_GROUP = 24;
    // 按照条件查找
    const CMD_SELECT = 25;
    // 获取在线的群组ID
    const CMD_GET_GROUP_ID_LIST = 26;
    // 取消分组
    const CMD_UNGROUP = 27;
    // worker连接gateway事件
    const CMD_WORKER_CONNECT = 200;
    // 心跳
    const CMD_PING = 201;
    // GatewayClient连接gateway事件
    const CMD_GATEWAY_CLIENT_CONNECT = 202;
    // 根据client_id获取session
    const CMD_GET_SESSION_BY_CLIENT_ID = 203;
    // 发给gateway，覆盖session
    const CMD_SET_SESSION = 204;
    // 当websocket握手时触发，只有websocket协议支持此命令字
    const CMD_ON_WEBSOCKET_CONNECT = 205;
    // 包体是标量
    const FLAG_BODY_IS_SCALAR = 0x01;
    // 通知gateway在send时不调用协议encode方法，在广播组播时提升性能
    const FLAG_NOT_CALL_ENCODE = 0x02;
    /**
     * 包头长度
     *
     * @var int
     */
    const HEAD_LEN = 28;
    public static $empty = array(
        'cmd'           => 0,
        'local_ip'      => 0,
        'local_port'    => 0,
        'client_ip'     => 0,
        'client_port'   => 0,
        'connection_id' => 0,
        'flag'          => 0,
        'gateway_port'  => 0,
        'ext_data'      => '',
        'body'          => '',
    );
    /**
     * 返回包长度
     *
     * @param string $buffer
     * @return int return current package length
     */
    public static function input($buffer)
    {
        if (strlen($buffer) < self::HEAD_LEN) {
            return 0;
        }
        $data = unpack("Npack_len", $buffer);
        return $data['pack_len'];
    }
    /**
     * 获取整个包的 buffer
     *
     * @param mixed $data
     * @return string
     */
    public static function encode($data)
    {
        $flag = (int)is_scalar($data['body']);
        if (!$flag) {
            $data['body'] = serialize($data['body']);
        }
        $data['flag'] |= $flag;
        $ext_len      = strlen($data['ext_data']);
        $package_len  = self::HEAD_LEN + $ext_len + strlen($data['body']);
        return pack("NCNnNnNCnN", $package_len,
                $data['cmd'], $data['local_ip'],
                $data['local_port'], $data['client_ip'],
                $data['client_port'], $data['connection_id'],
                $data['flag'], $data['gateway_port'],
                $ext_len) . $data['ext_data'] . $data['body'];
    }
    /**
     * 从二进制数据转换为数组
     *
     * @param string $buffer
     * @return array
     */
    public static function decode($buffer)
    {
        $data = unpack("Npack_len/Ccmd/Nlocal_ip/nlocal_port/Nclient_ip/nclient_port/Nconnection_id/Cflag/ngateway_port/Next_len",
            $buffer);
        if ($data['ext_len'] > 0) {
            $data['ext_data'] = substr($buffer, self::HEAD_LEN, $data['ext_len']);
            if ($data['flag'] & self::FLAG_BODY_IS_SCALAR) {
                $data['body'] = substr($buffer, self::HEAD_LEN + $data['ext_len']);
            } else {
                $data['body'] = unserialize(substr($buffer, self::HEAD_LEN + $data['ext_len']));
            }
        } else {
            $data['ext_data'] = '';
            if ($data['flag'] & self::FLAG_BODY_IS_SCALAR) {
                $data['body'] = substr($buffer, self::HEAD_LEN);
            } else {
                $data['body'] = unserialize(substr($buffer, self::HEAD_LEN));
            }
        }
        return $data;
    }
}
