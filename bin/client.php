#!/usr/bin/php
<?php
/**
 * 连接服务器处理广播中的请求
 *
 * @author duxin
 */
set_time_limit(0);

define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_AUTH', '!@#qweASD2017');

define('TCP_HOST', '118.190.205.103');
define('TCP_PORT', 9888);

define('DAEMONIZE', true);
define('PID_FILE', 'wlxs_listener');
define('PID_NAME', 'wlxs_listener');
if (php_sapi_name() != "cli") {
    die("Only run in command line mode\n");
}

if (DAEMONIZE) {
    include '../lib/Daemon.php';
    lib\Daemon::run(PID_NAME, PID_FILE)->init($argc, $argv);
}

class Client
{

    private static $client;

    private static $instance;

    private $cli;

    public function __construct()
    {
        ;
    }

    public static function getInstance($reconnect = false)
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getClient()
    {
        if (! self::$client || ! self::$client->isConnected() || ! self::$client->send(1)) {
            self::$client = new \swoole_client(SWOOLE_TCP | SWOOLE_KEEP);
            self::$client->connect(TCP_HOST, TCP_PORT, - 1);
        }
        return self::$client;
    }

    public function run()
    {
        $redis = new \Redis();
        $redis->pconnect(REDIS_HOST, REDIS_PORT, 0);
        $redis->auth(REDIS_AUTH);
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, - 1);
        $client = self::getInstance();
        $redis->subscribe([
            'wlxs_clientChannel',
            'wlxs_serverChannel'
        ], function ($i, $channel, $message) use ($client) {
            switch ($channel) {
                case 'wlxs_clientChannel':
                    $client = $client->getClient();
                    $rst = $client->send($message);
                    break;
                case 'wlxs_serverChannel':
                    dealServer($message);
                    break;
                default:
                    break;
            }
        });
    }
}
Client::getInstance()->run();

/**
 * 处理客户端。API发来的请求
 *
 * @param Swoole $client            
 * @param String $message            
 */
function dealClient($client, $message)
{
    $client->send($message);
    $rst = $client->recv();
}
