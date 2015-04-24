<?php
require_once __DIR__.'/Config/Store.php';

/**
 * Gateway/Worker推送客户端
 * @author walkor <walkor@workerman.net>
 */
class Gateway
{
    /**
     * gateway实例
     * @var object
     */
    protected static  $businessWorker = null;
    
   /**
    * 向所有客户端(或者client_id_array指定的客户端)广播消息
    * @param string $message 向客户端发送的消息（可以是二进制数据）
    * @param array $client_id_array 客户端id数组
    */
   public static function sendToAll($message, $client_id_array = null)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_SEND_TO_ALL;
       $gateway_data['body'] = $message;
       
       if($client_id_array)
       {
           $params = array_merge(array('N*'), $client_id_array);
           $gateway_data['ext_data'] = call_user_func_array('pack', $params);
       }
       elseif(empty($client_id_array) && is_array($client_id_array))
       {
           return;
       }
       
       // 如果有businessWorker实例，说明运行在workerman环境中，通过businessWorker中的长连接发送数据
       if(self::$businessWorker)
       {
           foreach(self::$businessWorker->gatewayConnections as $gateway_connection)
           {
               $gateway_connection->send($gateway_data);
           }
       }
       // 运行在其它环境中，使用udp向worker发送数据
       else
       {
           $all_addresses = Store::instance('gateway')->get('GLOBAL_GATEWAY_ADDRESS');
           foreach($all_addresses as $address)
           {
               self::sendToGateway($address, $gateway_data);
           }
       }
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
       $address = Store::instance('gateway')->get('client_id-'.$client_id);
       if(!$address)
       {
           return 0;
       }
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_IS_ONLINE;
       $gateway_data['client_id'] = $client_id;
       return self::sendUdpAndRecv($address, $gateway_data);
   }
   
   /**
    * 获取在线状态，目前返回一个在线client_id数组
    * @return array
    */
   public static function getOnlineStatus()
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_GET_ONLINE_STATUS;
       $gateway_buffer = GatewayProtocol::encode($gateway_data);
       
       $all_addresses = Store::instance('gateway')->get('GLOBAL_GATEWAY_ADDRESS');
       $client_array = $status_data = array();
       // 批量向所有gateway进程发送CMD_GET_ONLINE_STATUS命令
       foreach($all_addresses as $address)
       {
           $client = stream_socket_client("udp://$address", $errno, $errmsg);
           if(strlen($gateway_buffer) === stream_socket_sendto($client, $gateway_buffer))
           {
               $client_id = (int) $client;
               $client_array[$client_id] = $client;
           }
       }
       // 超时1秒
       $time_out = 1;
       $time_start = microtime(true);
       // 批量接收请求
       while(count($client_array) > 0)
       {
           $write = $except = array();
           $read = $client_array;
           if(@stream_select($read, $write, $except, $time_out))
           {
               foreach($read as $client)
               {
                   // udp
                   $data = json_decode(fread($client, 65535), true);
                   if($data)
                   {
                       $status_data = array_merge($status_data, $data);
                   }
                   unset($client_array[$client]);
               }
           }
           if(microtime(true) - $time_start > $time_out)
           {
               break;
           }
       }
       return $status_data;
   }
   
   /**
    * 关闭某个客户端
    * @param int $client_id
    * @param string $message
    */
   public static function closeClient($client_id)
   {
       $address = Store::instance('gateway')->get('client_id-'.$client_id);
       if(!$address)
       {
           return false;
       }
       return self::kickAddress($address, $client_id);
   }
   
   /**
    * 更新session,框架自动调用，开发者不要调用
    * @param int $client_id
    * @param string $session_str
    */
   public static function updateSocketSession($client_id, $session_str)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_UPDATE_SESSION;
       $gateway_data['client_id'] = $client_id;
       $gateway_data['ext_data'] = $session_str;
       return self::sendToGateway(Context::$local_ip . ':' . Context::$local_port, $gateway_data);
   }
   
   /**
    * 想某个用户网关发送命令和消息
    * @param int $client_id
    * @param int $cmd
    * @param string $message
    * @return boolean
    */
   protected static function sendCmdAndMessageToClient($client_id, $cmd , $message)
   {
       // 如果是发给当前用户则直接获取上下文中的地址
       if($client_id === Context::$client_id || $client_id === null)
       {
           $address = Context::$local_ip.':'.Context::$local_port;
       }
       else
       {
           $address = Store::instance('gateway')->get('client_id-'.$client_id);
           if(!$address)
           {
               return false;
           }
       }
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = $cmd;
       $gateway_data['client_id'] = $client_id ? $client_id : Context::$client_id;
       $gateway_data['body'] = $message;
       
       return self::sendToGateway($address, $gateway_data);
   }
   
   /**
    * 发送udp数据并返回
    * @param int $address
    * @param string $message
    * @return boolean
    */
   protected static function sendUdpAndRecv($address , $data)
   {
       $buffer = GatewayProtocol::encode($data);
       // 非workerman环境，使用udp发送数据
       $client = stream_socket_client("udp://$address", $errno, $errmsg);
       if(strlen($buffer) == stream_socket_sendto($client, $buffer))
       {
           // 阻塞读
           stream_set_blocking($client, 1);
           // 1秒超时
           stream_set_timeout($client, 1);
           // 读udp数据
           $data = fread($client, 655350);
           // 返回结果
           return json_decode($data, true);
       }
       else
       {
           throw new \Exception("sendUdpAndRecv($address, \$bufer) fail ! Can not send UDP data!", 502);
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
       // 非workerman环境，使用udp发送数据
       $gateway_buffer = GatewayProtocol::encode($gateway_data);
       $client = stream_socket_client("udp://$address", $errno, $errmsg);
       return strlen($gateway_buffer) == stream_socket_sendto($client, $gateway_buffer);
   }
   
   /**
    * 踢掉某个网关的socket
    * @param string $local_ip
    * @param int $local_port
    * @param int $client_id
    * @param string $message
    * @param int $client_id
    */
   protected  static function kickAddress($address, $client_id)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_KICK;
       $gateway_data['client_id'] = $client_id;
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
 
}

/**
 * 上下文 包含当前用户uid， 内部通信local_ip local_port socket_id ，以及客户端client_ip client_port
 * @author walkor
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
     * 用户id
     * @var int
     */
    public static $client_id;

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
        self::$local_ip = self::$local_port  = self::$client_ip = self::$client_port = self::$client_id  = null;
    }
}

/**
 * Gateway与Worker间通讯的二进制协议
 *
 * struct GatewayProtocol
 * {
 *     unsigned int        pack_len,
 *     unsigned char     cmd,//命令字
 *     unsigned int        local_ip,
 *     unsigned short    local_port,
 *     unsigned int        client_ip,
 *     unsigned short    client_port,
 *     unsigned int        client_id,
 *     unsigned char      flag,
 *     unsigned int        ext_len,
 *     char[ext_len]        ext_data,
 *     char[pack_length-HEAD_LEN] body//包体
 * }
 *
 *
 * @author walkor <walkor@workerman.net>
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
    const CMD_GET_ONLINE_STATUS = 10;

    // 判断是否在线
    const CMD_IS_ONLINE = 11;

    // 包体是标量
    const FLAG_BODY_IS_SCALAR = 0x01;

    /**
     * 包头长度
     * @var integer
     */
    const HEAD_LEN = 26;

    public static $empty = array(
            'cmd' => 0,
            'local_ip' => '0.0.0.0',
            'local_port' => 0,
            'client_ip' => '0.0.0.0',
            'client_port' => 0,
            'client_id' => 0,
            'flag' => 0,
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
        return pack("NCNnNnNNC",  $package_len,
                $data['cmd'], ip2long($data['local_ip']),
                $data['local_port'], ip2long($data['client_ip']),
                $data['client_port'], $data['client_id'],
                $ext_len, $flag) . $data['ext_data'] . $data['body'];
    }

    /**
     * 从二进制数据转换为数组
     * @param string $buffer
     * @return array
     */
    public static function decode($buffer)
    {
        $data = unpack("Npack_len/Ccmd/Nlocal_ip/nlocal_port/Nclient_ip/nclient_port/Nclient_id/Next_len/Cflag", $buffer);
        $data['local_ip'] = long2ip($data['local_ip']);
        $data['client_ip'] = long2ip($data['client_ip']);
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

/**
 * 存储类
 * 这里用memcache实现
 * @author walkor <walkor@workerman.net>
 */
class Store
{
    /**
     * 实例数组
     * @var array
     */
    protected static $instance = array();

    /**
     * 获取实例
     * @param string $config_name
     * @throws \Exception
     */
    public static function instance($config_name)
    {
        // memcache 驱动
        if(\Config\Store::$driver == \Config\Store::DRIVER_MC)
        {
            if(!isset(\Config\Store::$$config_name))
            {
                throw new \Exception("\\Config\\Store::$config_name not set\n");
            }

            if(!isset(self::$instance[$config_name]))
            {
                if(extension_loaded('Memcached'))
                {
                    self::$instance[$config_name] = new \Memcached;
                }
                elseif(extension_loaded('Memcache'))
                {
                    self::$instance[$config_name] = new \Memcache;
                }
                else
                {
                    sleep(2);
                    exit("extension memcached is not installed\n");
                }
                foreach(\Config\Store::$$config_name as $address)
                {
                    list($ip, $port) = explode(':', $address);
                    self::$instance[$config_name] ->addServer($ip, $port);
                }
            }
            return self::$instance[$config_name];
        }
        // 文件驱动
        else
        {
            if(!isset(self::$instance[$config_name]))
            {
                self::$instance[$config_name] = new FileStore($config_name);
            }
            return self::$instance[$config_name];
        }
    }
}

/**
 *
 * 这里用php数组文件来存储数据，
 * 为了获取高性能需要用类似memcache的存储
 * @author walkor <walkor@workerman.net>
 *
 */

class FileStore
{
 // 为了避免频繁读取磁盘，增加了缓存机制
    protected $dataCache = array();
    // 上次缓存时间
    protected $lastCacheTime = 0;
    // 打开文件的句柄
    protected $dataFileHandle = null;
    
    /**
     * 构造函数
     * @param 配置名 $config_name
     */
    public function __construct($config_name)
    {
        if(!is_dir(\Config\Store::$storePath) && !@mkdir(\Config\Store::$storePath, 0777, true))
        {
            // 可能目录已经被其它进程创建
            clearstatcache();
            if(!is_dir(\Config\Store::$storePath))
            {
                // 避免狂刷日志
                sleep(1);
                throw new \Exception('cant not mkdir('.\Config\Store::$storePath.')');
            }
        }
        $this->dataFileHandle = fopen(__FILE__, 'r');
        if(!$this->dataFileHandle)
        {
            throw new \Exception("can not fopen dataFileHandle");
        }
    }
    
    /**
     * 设置
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @return number
     */
    public function set($key, $value, $ttl = 0)
    {
        return file_put_contents(\Config\Store::$storePath.'/'.$key, serialize($value), LOCK_EX);
    }
    
    /**
     * 读取
     * @param string $key
     * @param bool $use_cache
     * @return Ambigous <NULL, multitype:>
     */
    public function get($key, $use_cache = true)
    {
        $ret = @file_get_contents(\Config\Store::$storePath.'/'.$key);
        return $ret ? unserialize($ret) : null;
    }
   
    /**
     * 删除
     * @param string $key
     * @return number
     */
    public function delete($key)
    {
        return @unlink(\Config\Store::$storePath.'/'.$key);
    }
    
    /**
     * 自增
     * @param string $key
     * @return boolean|multitype:
     */
    public function increment($key)
    {
        flock($this->dataFileHandle, LOCK_EX);
        $val = $this->get($key);
        $val = !$val ? 1 : ++$val;
        file_put_contents(\Config\Store::$storePath.'/'.$key, serialize($val));
        flock($this->dataFileHandle, LOCK_UN);
        return $val;
    }
    
    /**
     * 清零销毁存储数据
     */
    public function destroy()
    {
        
    }
}
