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
        $server = new \Swoole\Http\Server("0.0.0.0", 9502, SWOOLE_BASE);
        $server->set([
            'worker_num' => 4,
            'daemonize'  => false
        ]);
        $server->on('Request', function ($request, $response)
        {

            \INocturneSwoole\Connection\RedisPool::init([
                'test' => [
                    'serverInfo'    => ['host' => 'services_redis-db_1', 'port' => 6379,"timeout"=>2],
                    'maxSpareConns' => 5,
                    'maxConns'      => 10,
                    'maxSpareExp' => 3600
                ],
            ]);
            $swoole_mysql = \INocturneSwoole\Connection\RedisPool::fetch('test');
            $swoole_mysql->set('test','1111');
//            var_dump($swoole_mysql->get('test'));
//            var_dump($swoole_mysql);
            $response->end('Test End');
        });
        $server->start();
    }
}
var_dump((new Redis())->run());



