<?php
use \Workerman\Worker;
use \Workerman\WebServer;
use \Workerman\Connection\TcpConnection;
use \Workerman\Connection\AsyncTcpConnection;
use \Workerman\Lib\Timer;

// 自动加载类
require_once __DIR__ . '/vendor/autoload.php';

// 本地监听地址
define('LOCAL_ADDR_80', '0.0.0.0:80');
define('LOCAL_ADDR_443', '0.0.0.0:443');
// 落地机地址
define('REMOTE_ADDR_80', '127.0.0.1:80');
define('REMOTE_ADDR_443', '127.0.0.1:443');
// 数据库拉取频率
define('DATABASE_RATE', 60);
define('DATABASE_HOST', '127.0.0.1');
define('DATABASE_PORT', 3306);
define('DATABASE_USER', 'root');
define('DATABASE_PASSWORD', 'tienon');
define('DATABASE_DBNAME', 'stream-unblock-trans');


$task = new Worker();
$task->name = "task";
$task->onWorkerStart = function($task) {
    Timer::add(DATABASE_RATE, function() {
        $db = new Workerman\MySQL\Connection(DATABASE_HOST, DATABASE_PORT, DATABASE_USER, DATABASE_PASSWORD, DATABASE_DBNAME);
        $array = $db->column("SELECT `server` FROM `user`");
        $temparray = Array();
        foreach ($array as $ip) {
            // ddns
            if(!filter_var($ip, FILTER_VALIDATE_IP)) {
                $ip = gethostbyname($ip);
                if (!empty($ip)) {
                    $temparray[] = $ip;
                }
            }
        }
        $array = array_merge($array, $temparray);
        file_put_contents("allowip", json_encode($array, JSON_PRETTY_PRINT)) ;
    }, Array(), true, true);
};

// --------------------------------80-----------------------------------

$worker_80 = new Worker('tcp://' . LOCAL_ADDR_80);
$worker_80->count = 1;
$worker_80->allowip = Array();
$worker_80->name = "worker_80";

$worker_80->onWorkerStart = function($worker_80) {

    // 进程启动后设置一个每秒运行一次的定时器
    Timer::add(1, function()use($worker_80){
        $time_now = time();
        foreach($worker_80->connections as $connection) {
            // 有可能该connection还没收到过消息，则lastMessageTime设置为当前时间
            if (empty($connection->lastMessageTime)) {
                $connection->lastMessageTime = $time_now;
                continue;
            }
            // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
            if ($time_now - $connection->lastMessageTime > 1800) {
                $connection->close();
            }
        }
    });

    // 60秒数据库拉一次数据
    Timer::add(DATABASE_RATE-5, function()use($worker_80){
        if (file_exists("allowip")) {
            $allowip = file_get_contents("allowip");
            if (!empty($allowip)) {
                $allowip = json_decode($allowip, true);
                if (is_array($allowip)) {
                    $worker_80->allowip = $allowip;
                }
            }
        }
    }, Array(), true, true);
};

$worker_80->onConnect = function($clientconn) use ($worker_80) {
    $remoteip = $clientconn->getRemoteIp();
    $access = false;
    if (in_array($remoteip, $worker_80->allowip)) {
        $access = true;
    }
    if ($access == false) {
        $clientconn->close();
        return;
    }
};

$worker_80->onMessage = function($clientconn, $clientbuffer) use ($worker_80)
{
    $remoteconn = new AsyncTcpConnection('tcp://' . REMOTE_ADDR_80);
    
    $remoteconn->onConnect = function($remoteconn)use($clientconn, $clientbuffer)
    {
        $remoteconn->send($clientbuffer);
    };
    $remoteconn->onMessage = function($remoteconn, $remotebuffer)use($clientconn, $worker_80)
    {
        $clientconn->send($remotebuffer);
    };
    $remoteconn->onClose = function($remoteconn)use($clientconn)
    {
        $clientconn->destroy();
    };
    $remoteconn->onBufferFull = function($remoteconn)use($clientconn)
    {
        $clientconn->pauseRecv();
    };
    $remoteconn->onBufferDrain = function($remoteconn)use($clientconn)
    {
        $clientconn->resumeRecv();
    };
    $remoteconn->connect();

    // 客户端消息
    $clientconn->onMessage = function($clientconn, $clientbuffer)use($remoteconn)
    {
        // 给clientconn临时设置一个lastMessageTime属性，用来记录上次收到消息的时间
        $clientconn->lastMessageTime = time();
        $remoteconn->send($clientbuffer);
    };
    $clientconn->onClose = function($clientconn)use($remoteconn)
    {
        $remoteconn->destroy();
    };
    $clientconn->onBufferFull = function($clientconn)use($remoteconn)
    {
        $remoteconn->pauseRecv();
    };
    $clientconn->onBufferDrain = function($clientconn)use($remoteconn)
    {
        $remoteconn->resumeRecv();
    };
};

// ---------------------------------443--------------------------------------

$worker_443 = new Worker('tcp://' . LOCAL_ADDR_443);
$worker_443->count = 1;
$worker_443->allowip = Array();
$worker_443->name = "worker_443";

$worker_443->onWorkerStart = function($worker_443) {

    // 进程启动后设置一个每秒运行一次的定时器
    Timer::add(1, function()use($worker_443){
        $time_now = time();
        foreach($worker_443->connections as $connection) {
            // 有可能该connection还没收到过消息，则lastMessageTime设置为当前时间
            if (empty($connection->lastMessageTime)) {
                $connection->lastMessageTime = $time_now;
                continue;
            }
            // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
            if ($time_now - $connection->lastMessageTime > 1800) {
                $connection->close();
            }
        }
    });

    // 60秒数据库拉一次数据
    Timer::add(DATABASE_RATE-5, function()use($worker_443){
        if (file_exists("allowip")) {
            $allowip = file_get_contents("allowip");
            if (!empty($allowip)) {
                $allowip = json_decode($allowip, true);
                if (is_array($allowip)) {
                    $worker_443->allowip = $allowip;
                }
            }
        }
    }, Array(), true, true);
};

$worker_443->onConnect = function($clientconn) use ($worker_443) {
    $remoteip = $clientconn->getRemoteIp();
    $access = false;
    if (in_array($remoteip, $worker_443->allowip)) {
        $access = true;
    }
    if ($access == false) {
        $clientconn->close();
        return;
    }
};

$worker_443->onMessage = function($clientconn, $clientbuffer) use ($worker_443)
{
    $remoteconn = new AsyncTcpConnection('tcp://' . REMOTE_ADDR_443);
    
    $remoteconn->onConnect = function($remoteconn)use($clientconn, $clientbuffer)
    {
        $remoteconn->send($clientbuffer);
    };
    $remoteconn->onMessage = function($remoteconn, $remotebuffer)use($clientconn, $worker_443)
    {
        $clientconn->send($remotebuffer);
    };
    $remoteconn->onClose = function($remoteconn)use($clientconn)
    {
        $clientconn->destroy();
    };
    $remoteconn->onBufferFull = function($remoteconn)use($clientconn)
    {
        $clientconn->pauseRecv();
    };
    $remoteconn->onBufferDrain = function($remoteconn)use($clientconn)
    {
        $clientconn->resumeRecv();
    };
    $remoteconn->connect();

    // 客户端消息
    $clientconn->onMessage = function($clientconn, $clientbuffer)use($remoteconn)
    {
        // 给clientconn临时设置一个lastMessageTime属性，用来记录上次收到消息的时间
        $clientconn->lastMessageTime = time();
        $remoteconn->send($clientbuffer);
    };
    $clientconn->onClose = function($clientconn)use($remoteconn)
    {
        $remoteconn->destroy();
    };
    $clientconn->onBufferFull = function($clientconn)use($remoteconn)
    {
        $remoteconn->pauseRecv();
    };
    $clientconn->onBufferDrain = function($clientconn)use($remoteconn)
    {
        $remoteconn->resumeRecv();
    };
};

Worker::runAll();
