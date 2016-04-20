<?php
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
 */
class Gateway
{
    /**
     * 版本
     *
     * @var string
     */
    const VERSION = '2.0.4';
    
    /**
     * gateway实例
     * @var object
     */
    protected static $businessWorker = null;

    /**
     * 注册中心地址
     * @var string
     */
    public static $registerAddress = '127.0.0.1:1236';
    
    /**
     * 秘钥
     * @var string
     */
    public static $secretKey = '';
    
   /**
    * 向所有客户端(或者client_id_array指定的客户端)广播消息
    * @param string $message 向客户端发送的消息
    * @param array $client_id_array 客户端id数组
    */
   public static function sendToAll($message, $client_id_array = null)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_SEND_TO_ALL;
       $gateway_data['body'] = $message;
       
       if($client_id_array)
       {
           $data_array = array();
           foreach($client_id_array as $client_id)
           {
              $address = Context::clientIdToAddress($client_id);
              $data_array[long2ip($address['local_ip']).":{$address['local_port']}"][$address['connection_id']] = $address['connection_id'];
           }
           foreach($data_array as $addr=>$connection_id_list)
           {
              $the_gateway_data = $gateway_data;
              $the_gateway_data['ext_data'] = call_user_func_array('pack', array_merge(array('N*'), $connection_id_list));
              self::sendToGateway($addr, $the_gateway_data); 
           }
           return;
       }
       elseif(empty($client_id_array) && is_array($client_id_array))
       {
           return;
       }
       
       return self::sendToAllGateway($gateway_data);
   }
   
   /**
    * 向某个客户端发消息
    * @param int $client_id 
    * @param string $message
    */
   public static function sendToClient($client_id, $message)
   {
       return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_SEND_TO_ONE, $message);
   } 
   
   /**
    * 判断某个客户端是否在线
    * @param int $client_id
    * @return 0/1
    */
   public static function isOnline($client_id)
   {
       $address_data = Context::clientIdToAddress($client_id);
       $address = long2ip($address_data['local_ip']).":{$address_data['local_port']}";
       if(isset(self::$businessWorker))
       {
           if(!isset(self::$businessWorker->gatewayConnections[$address]))
           {
               return 0;
           }
       }
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_IS_ONLINE;
       $gateway_data['connection_id'] = $address_data['connection_id'];
       return (int)self::sendAndRecv($address, $gateway_data);
   }
   
   /**
    * 获取在线状态，目前返回一个在线client_id数组,client_id为key
    * @return array
    */
   public static function getALLClientInfo($group = null)
   {
       $gateway_data = GatewayProtocol::$empty;
       if(!$group)
       {
           $gateway_data['cmd'] = GatewayProtocol::CMD_GET_ALL_CLIENT_INFO;
       }
       else
       {
           $gateway_data['cmd'] = GatewayProtocol::CMD_GET_CLINET_INFO_BY_GROUP;
           $gateway_data['ext_data'] = $group;
       }
       $status_data = array();
       $all_buffer_array = self::getBufferFromAllGateway($gateway_data);
       foreach($all_buffer_array as $local_ip=>$buffer_array)
       {
           foreach($buffer_array as $local_port=>$buffer)
           {
               $data = json_decode(rtrim($buffer), true);
               if($data)
               {
                   foreach($data as $connection_id=>$session_buffer)
                   {
                       $status_data[Context::addressToClientId($local_ip, $local_port, $connection_id)] = $session_buffer ? Context::sessionDecode($session_buffer) : array();
                   }
               }
           }
       }
       return $status_data;
   }
  
   /**
    * 获取某个组的成员信息
    * @param string group
    * @return array
    */ 
   public static function getClientInfoByGroup($group)
   {
       return self::getALLClientInfo($group);
   }
   
   /**
    * 获取某个组的成员数目
    * @param string $group
    * @return int
    */
   public static function getClientCountByGroup($group)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_GET_CLIENT_COUNT_BY_GROUP;
       $gateway_data['ext_data'] = $group;
       $total_count = 0;
       $all_buffer_array = self::getBufferFromAllGateway($gateway_data);
       foreach($all_buffer_array as $local_ip=>$buffer_array)
       {
           foreach($buffer_array as $local_port=>$buffer)
           {
               $count = intval($buffer);
               if($count)
               {
                   $total_count += $count;
               }
           }
       }
       return $total_count;
   }
   
   /**
    * 获取与uid绑定的client_id列表
    * @param string $uid
    * @return array
    */
   public static function getClientIdByUid($uid)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_GET_CLIENT_ID_BY_UID;
       $gateway_data['ext_data'] = $uid;
       $client_list = array();
       $all_buffer_array = self::getBufferFromAllGateway($gateway_data);
       foreach($all_buffer_array as $local_ip=>$buffer_array)
       {
           foreach($buffer_array as $local_port=>$buffer)
           {
               $connection_id_array = json_decode(rtrim($buffer), true);
               if($connection_id_array)
               {
                   foreach($connection_id_array as $connection_id)
                   {
                       $client_list[] = Context::addressToClientId($local_ip, $local_port, $connection_id);
                   }
               }
           }
       }
       return $client_list;
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
               'secret_key' => self::$secretKey,
       ));
       return GatewayProtocol::encode($gateway_data);
   }
   
   /**
    * 批量向所有gateway发包，并得到返回数组
    * @param string $gateway_data
    * @return array
    */
   protected static function getBufferFromAllGateway($gateway_data)
   {
        $gateway_buffer = GatewayProtocol::encode($gateway_data);
        $gateway_buffer = self::$secretKey ? self::generateAuthBuffer() . $gateway_buffer : $gateway_buffer;
        if(isset(self::$businessWorker))
        {
           $all_addresses = self::$businessWorker->getAllGatewayAddresses();
           if(empty($all_addresses))
           {
               throw new \Exception('businessWorker::getAllGatewayAddresses return empty');
           }
        }
        else
        {
           $all_addresses = self::getAllGatewayAddressesFromRegister();
           if(empty($all_addresses))
           {
               return array();
           }
        }
        $client_array = $status_data = $client_address_map = $receive_buffer_array = array();
        // 批量向所有gateway进程发送请求数据
        foreach($all_addresses as $address)
        {
           $client = stream_socket_client("tcp://$address", $errno, $errmsg);
           if($client && strlen($gateway_buffer) === stream_socket_sendto($client, $gateway_buffer))
           {
               $socket_id = (int) $client;
               $client_array[$socket_id] = $client;
               $client_address_map[$socket_id] = explode(':',$address);
               $receive_buffer_array[$socket_id] = '';
           }
        }
        // 超时1秒
        $timeout = 1;
        $time_start = microtime(true);
        // 批量接收请求
        while(count($client_array) > 0)
        {
           $write = $except = array();
           $read = $client_array;
           if(@stream_select($read, $write, $except, $timeout))
           {
               foreach($read as $client)
               {
                   $socket_id = (int)$client;
                   $buffer = stream_socket_recvfrom($client, 65535);
                   if($buffer !== '' && $buffer !== false)
                   {
                       $receive_buffer_array[$socket_id] .= $buffer;
                       if($receive_buffer_array[$socket_id][strlen($receive_buffer_array[$socket_id])-1] === "\n")
                       {
                           unset($client_array[$socket_id]);
                       }
                   }
                   elseif(feof($client))
                   {
                       unset($client_array[$socket_id]);
                   }
               }
           }
           if(microtime(true) - $time_start > $timeout)
           {
               break;
           }
        }
        $format_buffer_array = array();
        foreach($receive_buffer_array as  $socket_id=>$buffer)
        {
           $local_ip = ip2long($client_address_map[$socket_id][0]);
           $local_port = $client_address_map[$socket_id][1];
           $format_buffer_array[$local_ip][$local_port] = $buffer;
        }
        return $format_buffer_array;
   }
   
   /**
    * 关闭某个客户端
    * @param int $client_id
    * @param string $message
    */
   public static function closeClient($client_id)
   {
       if($client_id === Context::$client_id)
       {
           return self::closeCurrentClient();
       }
       // 不是发给当前用户则使用存储中的地址
       else
       {
           $address_data = Context::clientIdToAddress($client_id);
           $address = long2ip($address_data['local_ip']).":{$address_data['local_port']}";
           return self::kickAddress($address, $address_data['connection_id']);
       }
   }
   
   /**
    * 将client_id与uid绑定
    * @param int $client_id
    * @param int/string $uid
    */
   public static function bindUid($client_id, $uid)
   {
       return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_BIND_UID, '', $uid);
   }
   
   /**
    * 将client_id与uid绑定
    * @param int $client_id
    * @param int/string $uid
    */
   public static function unbindUid($client_id, $uid)
   {
       return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_UNBIND_UID, '', $uid);
   }
   
   /**
    * 将client_id加入组
    * @param int $client_id
    * @param int/string $group
    */
   public static function joinGroup($client_id, $group)
   {
       return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_JOIN_GROUP, '', $group);
   }
   
   /**
    * 将client_id离开组
    * @param int $client_id
    * @param int/string $group
    */
   public static function leaveGroup($client_id, $group)
   {
       return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_LEAVE_GROUP, '', $group);
   }
   
   /**
    * 向所有uid发送
    * @param int/string/array $uid
    * @param unknown_type $message
    */
   public static function sendToUid($uid, $message)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_SEND_TO_UID;
       $gateway_data['body'] = $message;
       
       if(!is_array($uid))
       {
          $uid = array($uid);
       }
        
       $gateway_data['ext_data'] = json_encode($uid);
       
       return self::sendToAllGateway($gateway_data);
   }
   
   /**
    * 向group发送
    * @param int/string/array $group
    * @param string $message
    */
   public static function sendToGroup($group, $message)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_SEND_TO_GROUP;
       $gateway_data['body'] = $message;
        
       if(!is_array($group))
       {
           $group = array($group);
       }
   
       $gateway_data['ext_data'] = json_encode($group);
        
       return self::sendToAllGateway($gateway_data);
   }
   
   /**
    * 更新session,框架自动调用，开发者不要调用
    * @param int $client_id
    * @param string $session_str
    */
   public static function updateSocketSession($client_id, $session_str)
   {
       return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_UPDATE_SESSION, '', $session_str);
   }
   
   /**
    * 更新session
    * @param int $client_id
    * @param array $session
    */
   public static function updateSession($client_id, array $session)
   {
       self::updateSocketSession($client_id, Context::sessionEncode($session));
   }
   
   /**
    * 想某个用户网关发送命令和消息
    * @param int $client_id
    * @param int $cmd
    * @param string $message
    * @return boolean
    */
   protected static function sendCmdAndMessageToClient($client_id, $cmd , $message, $ext_data = '')
   {
       // 如果是发给当前用户则直接获取上下文中的地址
       if($client_id === Context::$client_id || $client_id === null)
       {
           $address = long2ip(Context::$local_ip).':'.Context::$local_port;
           $connection_id = Context::$connection_id;
       }
       else
       {
           $address_data = Context::clientIdToAddress($client_id);
           $address = long2ip($address_data['local_ip']).":{$address_data['local_port']}";
           $connection_id = $address_data['connection_id'];
       }
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = $cmd;
       $gateway_data['connection_id'] = $connection_id;
       $gateway_data['body'] = $message;
       if(!empty($ext_data))
       {
           $gateway_data['ext_data'] = $ext_data;
       }
       
       return self::sendToGateway($address, $gateway_data);
   }
   
   /**
    * 发送数据并返回
    * @param int $address
    * @param string $message
    * @return boolean
    */
   protected static function sendAndRecv($address , $data)
   {
        $buffer = GatewayProtocol::encode($data);
        $buffer = self::$secretKey ? self::generateAuthBuffer() . $buffer : $buffer;
        $client = stream_socket_client("tcp://$address", $errno, $errmsg);
        if(!$client)
        {
           throw new \Exception("can not connect to tcp://$address $errmsg");
        }
        if(strlen($buffer) === stream_socket_sendto($client, $buffer))
        {
           $timeout = 1;
           // 阻塞读
           stream_set_blocking($client, 1);
           // 1秒超时
           stream_set_timeout($client, 1);
           $all_buffer = '';
           $time_start = microtime(true);
           while(1)
           {
               $buf = stream_socket_recvfrom($client, 655350);
               if($buf !== '' && $buf !== false)
               {
                   $all_buffer .= $buf;
               }
               else
               {
                   if(feof($client))
                   {
                       throw new \Exception("connection close tcp://$address");
                   }
                   continue;
               }
               // 回复的数据都是以\n结尾
               if(($all_buffer && $all_buffer[strlen($all_buffer)-1] === "\n") || microtime(true) - $time_start > $timeout)
               {
                   break;
               }
           }
           // 返回结果
           return json_decode(rtrim($all_buffer), true);
        }
        else
        {
           throw new \Exception("sendAndRecv($address, \$bufer) fail ! Can not send data!", 502);
        }
   }
   
   /**
    * 发送数据到网关
    * @param string $address
    * @param string $buffer
    */
   protected static function sendToGateway($address, $gateway_data)
   {
        // 有$businessWorker说明是workerman环境，使用$businessWorker发送数据
        if(self::$businessWorker)
        {
           if(!isset(self::$businessWorker->gatewayConnections[$address]))
           {
               return false;
           }
           return self::$businessWorker->gatewayConnections[$address]->send($gateway_data);
        }
        // 非workerman环境
        $gateway_buffer = GatewayProtocol::encode($gateway_data);
        $gateway_buffer = self::$secretKey ? self::generateAuthBuffer() . $gateway_buffer : $gateway_buffer;
        $client = stream_socket_client("tcp://$address", $errno, $errmsg);
        return strlen($gateway_buffer) == stream_socket_sendto($client, $gateway_buffer);
   }
   
   /**
    * 向所有gateway发送数据
    * @param string $gateway_data
    */
   protected static function sendToAllGateway($gateway_data)
   {
       // 如果有businessWorker实例，说明运行在workerman环境中，通过businessWorker中的长连接发送数据
       if(self::$businessWorker)
       {
           foreach(self::$businessWorker->gatewayConnections as $gateway_connection)
           {
               $gateway_connection->send($gateway_data);
           }
       }
       // 运行在其它环境中，通过注册中心得到gateway地址
       else
       {
           $all_addresses = self::getAllGatewayAddressesFromRegister();
           if(!$all_addresses)
           {
               throw new \Exception('Gateway::getAllGatewayAddressesFromRegister() with registerAddress:' . self::$registerAddress . '  return ' . var_export($all_addresses, true));
           }
           foreach($all_addresses as $address)
           {
               self::sendToGateway($address, $gateway_data);
           }
       }
   }
   
   /**
    * 踢掉某个网关的socket
    * @param string $local_ip
    * @param int $local_port
    * @param int $client_id
    * @param string $message
    * @param int $client_id
    */
   protected  static function kickAddress($address, $connection_id)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_KICK;
       $gateway_data['connection_id'] = $connection_id;
       return self::sendToGateway($address, $gateway_data);
   }
   
   /**
    * 设置gateway实例
    * @param Bootstrap/Gateway $gateway_instance
    */
   public static function setBusinessWorker($business_worker_instance)
   {
       self::$businessWorker = $business_worker_instance;
   }

   /**
    * 获取通过注册中心获取所有gateway通讯地址
    * @return array
    */
   protected static function getAllGatewayAddressesFromRegister()
   {
       $client = stream_socket_client('tcp://'.self::$registerAddress, $errno, $errmsg, 1);
       if(!$client)
       {
           throw new \Exception('Can not connect to tcp://' . self::$registerAddress . ' ' .$errmsg);
       }
       fwrite($client, '{"event":"worker_connect","secret_key":"' . self::$secretKey . '"}' . "\n");
       stream_set_timeout($client, 1);
       $ret = fgets($client, 65535);
       if(!$ret || !$data = json_decode(trim($ret), true))
       {
           throw new \Exception('getAllGatewayAddressesFromRegister fail. tcp://' . self::$registerAddress . ' return '.var_export($ret, true));
       }
       return $data['addresses'];
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
        self::$local_ip = self::$local_port  = self::$client_ip = self::$client_port = self::$client_id  = self::$connection_id = null;
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
 * Gateway与Worker间通讯的二进制协议
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
    const CMD_ON_CONNECTION = 1;
    
    // 发给worker的，客户端有消息
    const CMD_ON_MESSAGE = 3;
    
    // 发给worker上的关闭链接事件
    const CMD_ON_CLOSE = 4;
    
    // 发给gateway的向单个用户发送数据
    const CMD_SEND_TO_ONE = 5;
    
    // 发给gateway的向所有用户发送数据
    const CMD_SEND_TO_ALL = 6;
    
    // 发给gateway的踢出用户
    const CMD_KICK = 7;
    
    // 发给gateway，通知用户session更改
    const CMD_UPDATE_SESSION = 9;
    
    // 获取在线状态
    const CMD_GET_ALL_CLIENT_INFO = 10;
    
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
    const CMD_GET_CLINET_INFO_BY_GROUP = 23;
    
    // 获取组成员数
    const CMD_GET_CLIENT_COUNT_BY_GROUP = 24;
    
    // worker连接gateway事件
    const CMD_WORKER_CONNECT = 200;
    
    // GatewayClient连接gateway事件
    const CMD_GATEWAY_CLIENT_CONNECT = 202;
    
    // 包体是标量
    const FLAG_BODY_IS_SCALAR = 0x01;
    
    /**
     * 包头长度
     * @var integer
     */
    const HEAD_LEN = 28;
    
    public static $empty = array(
        'cmd' => 0,
        'local_ip' => 0,
        'local_port' => 0,
        'client_ip' => 0,
        'client_port' => 0,
        'connection_id' => 0,
        'flag' => 0,
        'gateway_port' => 0,
        'ext_data' => '',
        'body' => '',
    );
     
    /**
     * 返回包长度
     * @param string $buffer
     * @return int return current package length
     */
    public static function input($buffer)
    {
        if(strlen($buffer) < self::HEAD_LEN)
        {
            return 0;
        }
        
        $data = unpack("Npack_len", $buffer);
        return $data['pack_len'];
    }
    
    /**
     * 获取整个包的buffer
     * @param array $data
     * @return string
     */
    public static function encode($data)
    {
        $flag = (int)is_scalar($data['body']);
        if(!$flag)
        {
            $data['body'] = serialize($data['body']);
        }
        $ext_len = strlen($data['ext_data']);
        $package_len = self::HEAD_LEN + $ext_len + strlen($data['body']);
        return pack("NCNnNnNCnN", $package_len,
                       $data['cmd'], $data['local_ip'],
                       $data['local_port'], $data['client_ip'],
                       $data['client_port'], $data['connection_id'], 
                       $flag, $data['gateway_port'], 
                       $ext_len) . $data['ext_data'] . $data['body'];
    }
    
    /**
     * 从二进制数据转换为数组
     * @param string $buffer
     * @return array
     */    
    public static function decode($buffer)
    {
        $data = unpack("Npack_len/Ccmd/Nlocal_ip/nlocal_port/Nclient_ip/nclient_port/Nconnection_id/Cflag/ngateway_port/Next_len", $buffer);
        $data['local_ip'] = $data['local_ip'];
        $data['client_ip'] = $data['client_ip'];
        if($data['ext_len'] > 0)
        {
            $data['ext_data'] = substr($buffer, self::HEAD_LEN, $data['ext_len']);
            if($data['flag'] & self::FLAG_BODY_IS_SCALAR)
            {
                $data['body'] = substr($buffer, self::HEAD_LEN + $data['ext_len']);
            }
            else
            {
                $data['body'] = unserialize(substr($buffer, self::HEAD_LEN + $data['ext_len']));
            }
        }
        else
        {
            $data['ext_data'] = '';
            if($data['flag'] & self::FLAG_BODY_IS_SCALAR)
            {
                $data['body'] = substr($buffer, self::HEAD_LEN);
            }
            else
            {
                $data['body'] = unserialize(substr($buffer, self::HEAD_LEN));
            }
        }
        return $data;
    }
}



