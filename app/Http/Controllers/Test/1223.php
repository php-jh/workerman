<?php
include dirname(__FILE__). "/class/game.action.php";
include dirname(__FILE__) . "/class/base.action.php";
include dirname(__FILE__) . "/class/Until.php";
include dirname(__FILE__) . "/class/db.class.php";
include dirname(__FILE__) . "/class/cache.class.php";
include dirname(__FILE__) . "/class/GameQueue.php";
require_once dirname(__FILE__) . "/class/PCHController.php";
require_once dirname(__FILE__) . '/functions.php';
require_once dirname(__FILE__) . '/Root.php';
require_once dirname(__FILE__) . '/start.php';
require_once dirname(__FILE__) .'/dispatch/User.php';
require_once dirname(__FILE__) .'/dispatch/event.php';
require_once dirname(__FILE__) . '/class/initApp.php';
header("Content-type:text/html;charset=utf-8");
define("__ROOT__",dirname(__FILE__));

#设置启动环境
$Console                = new Console();
$ini                    = $Console->ini;
$setList			    = [
    'daemonize'         =>  $Console->getDamon(),
    'log_file'          =>  $Console->getLogFile(),
    'log_level'         => 3,
    'pid_file'          =>  $Console->pid_File,
    //     以守护进程执行
];

$ws 	    = new swoole_websocket_server("0.0.0.0", '8010',SWOOLE_BASE);
$ws->set($setList);
//TCP
$tcp 	    = $ws->listen("0.0.0.0", 9505, SWOOLE_SOCK_TCP);

$tcp->set([]);

$initApp        = new initApp();
$redis          = $initApp->initRedis($ini);
$user           = new User($redis);
$until         = Until::getInstance($redis);


$ws->on('start',function (swoole_websocket_server $serv) use ($ini, $redis,$until)  {
    $Event          = new event($serv);
    $serv->Event    = $Event;
    $serv->redis    = $redis;
    $serv->Untils   = $until;
    printWithColor("dispatch消息转发服务启动成功");
    #清理redis链接数据
    $rm[] = $serv->redis->keys("dispatchFdSet");
    foreach ($rm as $v)
    {
        $serv->redis->del($v);
    }
});

$ws->on('open', function (swoole_websocket_server $server, $request) use ($user,$redis,$until) {
    printWithColor("fd:{$request->fd}尝试连接");
    if (empty($request->server['query_string'])){
        printWithColor("缺少参数uid|token",'red');
        $server->disconnect($request->fd,4000,'缺少参数uid|token');
        return false;
    }
    $query      = $request->server['query_string'];
    $flag       = parse_str($query,$data);
    if (!isset($data['uid'])){
        printWithColor("缺少参数uid|token",'red');
        $server->disconnect($request->fd,4000,'缺少参数uid|token');
        return false;
    }
    if (empty($data['uid'])){
        return false;
    }
    $fd = $user->getFdFromUidHashMapByUid($data['uid']);
    printWithColor("用户id：{$data['uid']} ----- fd:{$fd}");
    if ($fd&&$server->isEstablished($fd)){
        $server->push($fd,json_encode(['data'=>[],'code'=>-99,'msg'=>'登录异常']));
        $server->disconnect($fd,1001,'异常登录');
        printWithColor("用户{$data['uid']}触发异常登录",'light_purple');
    }
    $user->setFdHashMapToUid($data['uid'],$request->fd);
});
//workerStart
$ws->on('workerStart', function(swoole_websocket_server $serv) use ($ini) {

});

$ws->on('message', function (swoole_websocket_server $server, $frame) use ($ws,$user,$until)
{
    $user_data = json_decode($frame->data,true);
    if (!empty($user_data['action']))
    {
        echo "收到指令:".$user_data['action'] ."\n";
        switch (true) {
            case $user_data['action'] == 'getRoomInfo' :
                //接收处理心跳包
                $server->Event->getRoomInfo($frame->fd);
                #记录一个用户fd列表 用于全局发送
                $user->setFdToSet($frame->fd);
                break;
            case $user_data['action'] == 'getConnectInfo' :
                $user->setFdToSet($frame->fd);
                #返回fd app 保存
//                $server->push($frame->fd,json_encode(['code'=>721,'fd'=>$frame->fd]));
                break;
            case $user_data['action'] == 'getNowPlay' :
                $data = $until->getNowPlay($user_data['uid']);
                $data = ['data'=>$data,'code'=>727];
                $server->push($frame->fd,json_encode($data));
                break;
            default:
                # code...
                break;
        }
    }else{
        printWithColor('不存在的指令','yellow');
    }
});

$ws->on('close', function (swoole_websocket_server $serv, $fd) use($user,$redis) {
    $uid = $user->getUidFromFdHashMapByFd($fd);
    if (!empty($uid)){
        printWithColor("用户uid:{$uid}失去了连接");
    }else{
        printWithColor("用户Fd:{$fd}失去了连接");
    }

    $user->delFdFromSet($fd);
    $user->delFdHashMap($fd);
});



//监听连接进入事件open
$tcp->on('connect', function ($serv, $fd) {
    echo "fd:".$fd;
});


//监听数据接收事件
$tcp->on('receive', function ($serv, $fd, $from_id, $user_data) use ($ws,$user) {
    $user_data = json_decode($user_data,true);
    if (!empty($user_data['action'])){
        echo "收到指令:".$user_data['action'] ."\n";
        switch (true) {
            case $user_data['action'] == 'proxy' :
                $fd_set = $user->getAllFdFromSet();
                if (empty($fd_set)){
                    color_b('fd列表为空');
                }
                if ($user_data['code']==725){
                    var_dump($user_data);
                }
                $data = json_encode($user_data['data']);
                foreach ($fd_set as $fd_val){
                    if (isset($ws->connection_info($fd_val)["websocket_status"])){
                        $ws->push($fd_val,$data);
                    }
                }
                break;
            case $user_data['action'] == 'offline' :
                $data = $user_data['data'];
                $webSocket_status = $ws->connection_info($data['fd'])["websocket_status"]??0;
                if ($webSocket_status == 3 ){
                    $ws->push($data['fd'],json_encode(['code'=>-99,'msg'=>'您的账号已在别处登录，如不是本人操作请尽快修改密码']));
                }
            default:
                break;
        }
    }else{
        echo "收到错误指令:".$user_data['action'] ."\n";
    }
});



$tcp->on('close',function ($serv,$fd) {
    echo "TcpClient:closed\n";
});

$ws->start();

