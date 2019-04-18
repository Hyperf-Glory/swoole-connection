<?php
/**
 * Created by PhpStorm.
 * User: HePing
 * Date: 2019-03-02
 * Time: 19:10
 */
namespace INocturneSwoole\Connection\Tests;

use Swoole\Coroutine;

require '../vendor/autoload.php';
class Redis
{
    public function run()
    {
        $server = new \Swoole\Http\Server("127.0.0.1", 9502, SWOOLE_BASE);
        $server->set([
            'worker_num' => 1,
            'daemonize'  => false
        ]);
        $server->on('Request', function ($request, $response)
        {
            echo 'request1:cid:' . Coroutine::getuid() . PHP_EOL;
            \INocturneSwoole\Connection\RedisPool::init([
                'test' => [
                    'serverInfo'    => ['host' => '127.0.0.1', 'port' => 6379],
                    'maxSpareConns' => 5,
                    'maxConns'      => 10
                ],
            ]);
            $swoole_mysql = \INocturneSwoole\Connection\RedisPool::fetch('test');
            $swoole_mysql->on('message', function ($redis, array $message)
            {

            });

            $response->end('Test End');
        });
        $server->start();
    }
}
var_dump((new Redis())->run());



