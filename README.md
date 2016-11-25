# GatewayClient

GatewayWorker1.0请使用[1.0版本的GatewayClient](https://github.com/walkor/GatewayClient/releases/tag/v1.0)

GatewayWorker2.0.1-2.0.4请使用[2.0.4版本的GatewayClient](https://github.com/walkor/GatewayClient/releases/tag/2.0.4)

GatewayWorker2.0.5-2.0.6版本请使用[2.0.6版本的GatewayClient](https://github.com/walkor/GatewayClient/releases/tag/2.0.6)

GatewayWorker2.0.7及以上版本请使用 [2.0.7版本的GatewayClient](https://github.com/walkor/GatewayClient/releases/tag/v2.0.7)

GatewayWorker3.0.0及以上版本请使用 [3.0.0版本的GatewayClient](https://github.com/walkor/GatewayClient/releases/tag/v3.0.0)<br>
注意：GatewayClient3.0.0以后支持composer并加了命名空间```GatewayClient``` <br>

## 安装（composer安装适用于3.0.0及以上版本）
```
composer require workerman/gatewayclient
```

## 使用
```php
// GatewayClient 3.0.0版本以后加了命名空间
use GatewayClient\Gateway;

// 设置服务注册地址，用来指定与哪个GatewayWorker（集群）通讯。
Gateway::$registerAddress = 'x.x.x.x:xx';

// GatewayClient支持GatewayWorker中的所有接口(Gateway::closeCurrentClient Gateway::sendToCurrentClient除外)
Gateway::sendToAll($data);
Gateway::sendToClient($client_id, $data);
Gateway::closeClient($client_id);
Gateway::isOnline($client_id);
Gateway::bindUid($client_id, $uid);
Gateway::isUidOnline($uid);
Gateway::getClientIdByUid($client_id);
Gateway::unbindUid($client_id, $uid);
Gateway::sendToUid($uid, $dat);
Gateway::joinGroup($client_id, $group);
Gateway::sendToGroup($group, $data);
Gateway::leaveGroup($client_id, $group);
Gateway::getClientCountByGroup($group);
Gateway::getClientSessionsByGroup($group);
Gateway::getAllClientCount();
Gateway::getAllClientSessions();
Gateway::setSession($client_id, $session);
Gateway::updateSession($client_id, $session);
Gateway::getSession($client_id);
```

