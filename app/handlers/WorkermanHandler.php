<?php
/**
 * Created by PhpStorm.
 * User: ${蒋华}
 * Date: 2019/1/21
 * Time: 17:27
 */

namespace App\handlers;

use Illuminate\Support\Facades\Redis;
use Workerman\Lib\Timer;

// 心跳间隔10秒
define('HEARTBEAT_TIME', 10);

class WorkermanHandler
{
    // 处理客户端连接
    public function onConnect()
    {

    }

    // 处理客户端消息
    public function onMessage($connection, $data)
    {
       // 向客户端发送hello $data
        $val = json_decode($data,true);
        $user_id = !empty($val['user_id'])?$val['user_id']:0;
        if(empty($user_id)){
            $result = json_encode(array('code'=>401,'msg'=>'缺少user_id'));
            $connection->send('Hello, your send message is: ' . $result);
        }
        $type = !empty($val['type'])?$val['type']:'login';
        switch ($type){
            case 'login':
                Redis::hmset('webscoket_client_id:',$user_id,$connection->id);

                Redis::hmset('webscoket_user_id:',$connection->id,$user_id);

                foreach($connection->worker->connections as $connection)
                {
                    $connection->send($connection->id.'登录3user_id'.$user_id);
                }
                break;
            case 'message':
                $message = !empty($val['name'])?$val['name']:'等待消息';
                foreach($connection->worker->connections as $connection)
                {
                    $connection->send($user_id.'发送消息'.$message);
                }
                break;
            default:
                echo '无效';
        }

    }

    // 处理客户端断开
    public function onClose($connection)
    {
        echo '断开连接了'.$connection->id;
    }

    public function onWorkerStart($worker)
    {
        Timer::add(1, function () use ($worker) {
            $time_now = time();
            foreach ($worker->connections as $connection) {
                // 有可能该connection还没收到过消息，则lastMessageTime设置为当前时间
                if (empty($connection->lastMessageTime)) {
                    $connection->lastMessageTime = $time_now;
                    continue;
                }
                // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
                if ($time_now - $connection->lastMessageTime > HEARTBEAT_TIME) {
//                    $connection->send($connection->id.'发送消息时间:'.time());
                    $connection->close();
                }
            }
        });
    }
}