<?php
namespace tcp;

use config\Config;
use config\Error;
use lib\Logger;
use lib\Cache;

class Server
{

    public static function onStart($server)
    {
        $content = json_encode($server);
        Logger::getInstance()->write($content, 'server');
        $run_log = "
                     _       __    __    _____,
                    | |      \ \  / /   / ____|
 __      __      __ | |       \ \/ /   | (___
 \ \    /  \    / / | |        \ \/     \___ \
  \ \  / /\ \  / /  | |        /\ \         ) |
   \ \/ /  \ \/ /   | |___/|  / /\ \    ____/ /
    \__/    \__/    |______| /_/  \_\  |_____/
            
";
        fwrite(STDOUT, $run_log . "\n");
        $serverInfo = implode('|', [
            $server->master_pid,
            $server->manager_pid,
            $server->port,
            $server->setting['worker_num'],
            $server->setting['max_request'],
            $server->setting['pid_file']
        
        ]);
        fwrite(STDOUT, 'SERVER_START|' . date("Y-m-d H:i:s") . '|' . $serverInfo . "\n");
    }

    public static function onConnect($server, $fd)
    {
        $connection = $server->connection_info($fd);
        if (! in_array($connection['remote_ip'], Config::ipAllow)) {
            /* ipAllow 表示本地请求，对本地请求不做登录超时限制限制 */
            Cache::getInstance()->hSet(Config::caches['connections'], $fd, json_encode(array_merge($connection, [
                'login_id' => 0,
                'device_id' => 0
            ])));
            
            // 记录连接情况
            $clientInfo = date('Y-m-d H:i:s') . '|' . implode('|', $connection) . '|' . $fd;
            fwrite(STDOUT, 'CLIENT_CONNECT|' . $clientInfo . "\n");
            
            // 定时器，loginTimeout 后自动断开
            swoole_timer_after(Config::loginTimeout, [
                Timer::class,
                'onLoginTimeout'
            ], [
                'server' => $server,
                'fd' => $fd,
                'connection' => $connection
            ]);
        }
    }

    public static function onReceive($server, $fd, $from_id, $data)
    {
        $client = $server->connection_info($fd);
        
        if (in_array($client['remote_ip'], Config::ipAllow)) {
            $commands = Config::orderMap;
            if ($data == 1) {
                return true;
            }
            $headers = json_decode($data, true);
            if (! $headers) {
                $server->close($fd);
            }
            $data = $headers['data'];
            $server->send($fd, $commands[$headers['command']]);
            return call_user_func_array([
                new Work(),
                $commands[$headers['command']]
            ], [
                $server,
                $fd,
                $from_id,
                $data,
                $headers,
                $client
            ]);
        } else {
            $commands = Config::serverMap;
            $bytes = (new Byte())->unpack($data);
            $headers = $bytes->headers;
            
            $length = strlen($data);
            $decArr = unpack('C' . $length, $data);
            
            $tmpdata = implode(',', $decArr);
            Logger::getInstance()->write('RequestFrom:' . $client['remote_ip'] . ':' . $client['remote_port'] . ':' . $tmpdata, 'client');
            
            if (count($decArr) < 9) {
                $response = (new Byte())->setSn($headers['sn'] ++)
                    ->response(Error::packageStructureError)
                    ->pack();
                
                $responseRst = $server->send($fd, $response);
                $logStr = 'PackageStructureError|' . Error::packageStructureError . '|' . $fd . '|' . $client['remote_ip'] . '|' . $client['remote_port'] . '|' . $tmpdata . '|' . intval($responseRst);
                Logger::getInstance()->write($logStr, 'error');
                $server->close($fd);
                return $responseRst;
            }
            
            // 验证请求头
            foreach ($headers as $header) {
                if (strlen($header) === 0) {
                    $response = (new Byte())->setSn($headers['sn'] ++)
                        ->response(Error::packageStructureError)
                        ->pack();
                    
                    $responseRst = $server->send($fd, $response);
                    $logStr = 'PackageStructureError|' . Error::packageStructureError . '|' . $fd . '|' . $client['remote_ip'] . '|' . $client['remote_port'] . '|' . $tmpdata . '|' . intval($responseRst);
                    Logger::getInstance()->write($logStr, 'error');
                    $server->close($fd);
                    return $responseRst;
                }
            }
            
            // 验证校验和
            if (! $headers['is_checked']) {
                $response = (new Byte())->setSn($headers['sn'] ++)
                    ->response(Error::packageCheckSumError)
                    ->pack();
                $responseRst = $server->send($fd, $response);
                $logStr = 'packageCheckSumError|' . Error::PackageCheckSumError . '|' . $fd . '|' . $client['remote_ip'] . '|' . $client['remote_port'] . '|' . $tmpdata . '|' . intval($responseRst);
                Logger::getInstance()->write($logStr, 'error');
                
                $server->close($fd);
                return false;
            }
            
            // 验证命令
            if (in_array($headers['command'], array_keys(Config::serverMap))) {
                $rst = call_user_func_array([
                    new Work(),
                    Config::serverMap[$headers['command']]
                ], [
                    $server,
                    $fd,
                    $from_id,
                    $data,
                    $headers,
                    $client
                ]);
                
                // 记录请求数据
                $requestArr = $headers;
                $requestArr['data'] = $bytes->getRequestData();
                $logArr = array_merge($client, $requestArr);
                Cache::getInstance()->publish(Config::broadcastChannels['request'], json_encode($logArr));
                if ($headers['command'] != 0x02) {
                    Logger::getInstance()->write(json_encode($logArr) . '|' . intval($rst), 'device_request');
                }
                return $rst;
            } else {
                if ($headers['flags'] == 1) {
                    return true;
                } else {
                    $responseData = (new Byte())->setSn($headers['sn'] ++)
                        ->response(Error::invalidCommand)
                        ->pack();
                    $responseRst = $server->send($fd, $responseData);
                    $logStr = 'InvalidCommand|' . Error::InvalidCommand . '|' . $fd . '|' . $client['remote_ip'] . '|' . $client['remote_port'] . '|' . $tmpdata . '|' . intval($responseRst);
                    Logger::getInstance()->write($logStr, 'error');
                    $closeRst = $server->close($fd);
                    return false;
                }
            }
        }
    }

    public static function onClose($server, $fd)
    {
        $connection = $server->connection_info($fd);
        $client = Cache::getInstance()->hGet(Config::caches['connections'], $fd);
        if (! empty($client)) {
            $client = json_decode($client, true);
            if ($client['device_id']) {
                $device = Cache::getInstance()->hGet(Config::caches['clients'], $client['device_id']);
                if ($device) {
                    $device = json_decode($device);
                    Cache::getInstance()->hDel(Config::caches['clients'], $client['device_id']); // 清除设备
                    Cache::getInstance()->hDel(Config::caches['login_tags'], $device->device_id . '_' . $device->login_id); // 清除登录标签
                    Cache::getInstance()->hDel(Config::caches['login_count'], $device->device_id . '_' . $device->login_id); // 清除登录标签数量
                }
            }
            // 记录设备断开情况
            fwrite(STDOUT, 'DEVICE_DISCONNECT|' . implode('|', $client) . "\n");
        }
        Logger::getInstance()->write('Close:' . $fd, 'server');
        
        // 记录连接断开连接情况
        $clientInfo = date('Y-m-d H:i:s') . '|' . implode('|', $connection) . '|' . $fd;
        fwrite(STDOUT, 'CLIENT_DISCONNECT|' . $clientInfo . "\n");
        
        Cache::getInstance()->hDel(Config::caches['connections'], $fd); // 清除连接数
    }

    public static function onShutdown($server)
    {
        Cache::getInstance()->del(Config::caches['connections']);
        $content = 'Server has been shutdown [' . date("Y-m-d H:i:s") . "]\n";
        Logger::getInstance()->write($content, 'server');
        $pidFile = $server->setting['pid_file'];
        if (is_file($pidFile)) {
            unlink($pidFile);
        }
        $serverInfo = implode('|', [
            $server->master_pid,
            $server->manager_pid,
            $server->port,
            $server->setting['worker_num'],
            $server->setting['max_request'],
            $server->setting['pid_file']
        
        ]);
        // 记录服务器关闭信息
        fwrite(STDOUT, 'SERVER_STOP|' . date("Y-m-d H:i:s") . '|' . $serverInfo . "\n");
    }

    public static function onWorkerStart($server, $worker_id)
    {
        ;
    }

    public static function onWorkerStop($server, $worker_id)
    {
        ;
    }

    public static function onTimer($server, $interval)
    {
        ;
    }

    public static function onPacket($server, $data, $client_info)
    {
        ;
    }

    public static function onBufferFull($server, $fd)
    {
        ;
    }

    public static function onBufferEmpty($server, $fd)
    {
        ;
    }

    public static function onTask($server, $task_id, $src_worker_id, $data)
    {
        ;
    }

    public static function onFinish($server, $task_id, $data)
    {
        ;
    }

    public static function onWorkerError($server, $worker_id, $worker_pid, $exit_code, $signal)
    {
        ;
    }

    public static function onPipeMessage($server, $from_worker_id, $message)
    {
        ;
    }

    public static function onManagerStart($server)
    {
        ;
    }

    public static function onManagerStop($server)
    {
        ;
    }
}
