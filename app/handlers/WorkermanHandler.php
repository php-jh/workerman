<?php
/**
 * Created by PhpStorm.
 * User: ${蒋华}
 * Date: 2019/1/21
 * Time: 17:27
 */

namespace App\handlers;

use Workerman\Lib\Timer;

// 心跳间隔10秒
define('HEARTBEAT_TIME', 10);

class WorkermanHandler
{
    // 处理客户端连接
    public function onConnect($connection)
    {
        echo "new connection from ip " . $connection->getRemoteIp() . "\n";
    }

    // 处理客户端消息
    public function onMessage($connection, $data)
    {
        var_dump($connection->id);
//        // 向客户端发送hello $data
        $val = json_decode($data,true);
        $user_id = !empty($val['user_id'])?$val['user_id']:0;
        if(empty($user_id)){
            $result = json_encode(array('code'=>401,'msg'=>'缺少user_id'));
            $connection->send('Hello, your send message is: ' . $result);
        }
        $cline_id = !empty($val['cline_id'])?$val['cline_id']:$connection->id;
//        $result_cline_id = \DB::table('cline')->select('user_id')->where('cline_id',$cline_id)->first();

        \DB::table('cline')->insert([
            'user_id'=>$user_id,
            'cline_id'=>$cline_id,
            'data'=>json_encode($connection)
        ]);

//        $result = \DB::table('api_token')->select('id')->where('answer_token',$token)->first();
//        if(empty($result)){
//            $result = json_encode(array('code'=>400,'msg'=>'异地登录'));
//        }else{
//            $result = json_encode(array('code'=>200,'msg'=>'异地登录'));
//        }
        $connection->send('Hello, your send message is: ' . (string)$cline_id);

    }

    // 处理客户端断开
    public function onClose($connection)
    {
        $connection->send('断开连接了',$connection);
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
                    echo "Client ip {$connection->getRemoteIp()} timeout!!!\n";
                    $connection->close();
                }
            }
        });
    }
}