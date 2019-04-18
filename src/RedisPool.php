<?php

namespace INocturneSwoole\Connection;

use Swoole\Coroutine;
use Swoole\Coroutine\Redis;

class RedisPool extends Base
{
    protected static $init = false;
    protected static $spareConns = [];
    protected static $busyConns = [];
    protected static $connsConfig;
    protected static $connsNameMap = [];
    protected static $pendingFetchCount = [];
    protected static $resumeFetchCount = [];
    protected static $yieldChannel = [];
    protected static $initConnCount = [];
    protected static $lastConnsTime = [];

    /**
     * @param array $connsConfig
     *
     * @throws RedisException
     */
    public static function init(array $connsConfig)
    {
        if (self::$init) {
            return;
        }
        self::$connsConfig = $connsConfig;
        foreach ($connsConfig as $name => $config) {
            self::$spareConns[$name] = [];
            self::$busyConns[$name] = [];
            self::$pendingFetchCount[$name] = 0;
            self::$resumeFetchCount[$name] = 0;
            self::$initConnCount[$name] = 0;
            if ($config['maxSpareConns'] <= 0 || $config['maxConns'] <= 0) {
                throw new RedisException("Invalid maxSpareConns or maxConns in {$name}");
            }
        }
        self::$init = true;
    }

    /**
     * 回收连接
     * @param Redis $conn
     * @param bool $busy
     */
    public static function recycle(Redis $conn, bool $busy = true)
    {
        self::go(function () use ($conn, $busy) {
            if (!self::$init) {
                throw new RedisException('Should call RedisPool::init.');
            }
            $id = spl_object_hash($conn);
            $connName = self::$connsNameMap[$id];

            if ($busy) {
                if (isset(self::$busyConns[$connName][$id])) {
                    unset(self::$busyConns[$connName][$id]);
                } else {
                    throw new RedisException('Unknow Redis connection.');
                }
            }

            $connsPool = &self::$spareConns[$connName];

            if (((count($connsPool) + self::$initConnCount[$connName]) >= self::$connsConfig[$connName]['maxSpareConns']) &&
                ((microtime(true) - self::$lastConnsTime[$id]) >= ((self::$connsConfig[$connName]['maxSpareExp']) ?? 0))
            ) {

                if ($conn->connected) {
                    $conn->close();
                }
                unset(self::$connsNameMap[$id]);
            } else {
                if (!$conn->connected) {
                    unset(self::$connsNameMap[$id]);
                    $conn = self::initConn($connName);
                    $id = spl_object_id($conn);
                }
                $connsPool[] = $conn;
                if (self::$pendingFetchCount[$connName] > 0) {
                    ++self::$resumeFetchCount[$connName];
                    self::$yieldChannel[$connName]->push($id);
                }
            }
        });
    }

    /**
     * @param $connName
     * @return Redis|null|false
     * @throws RedisException
     */
    public static function fetch($connName): ?Redis
    {
        if (!self::$init) {
            throw new RedisException('Should call RedisPool::init!');
        }
        if (!isset(self::$connsConfig[$connName])) {
            throw new RedisException("Invalid connName: {$connName}.");
        }
        $connsPool = &self::$spareConns[$connName];
        if (!empty($connsPool) && count($connsPool) > self::$resumeFetchCount[$connName]) {
            $conn = array_pop($connsPool);
            if (!$conn->connected) {
                $conn = self::reconnect($conn, $connName);
            } else {
                $id = spl_object_hash($conn);
                self::$busyConns[$connName][$id] = $conn;
                self::$lastConnsTime[$id] = microtime(true);
            }
            defer(function () use ($conn) {
                self::recycle($conn);
            });
            return $conn;
        }
        if (count(self::$busyConns[$connName]) + count($connsPool)
            +
            self::$pendingFetchCount[$connName] + self::$initConnCount[$connName]
            >=
            self::$connsConfig[$connName]['maxConns']) {
            if (!isset(self::$yieldChannel[$connName])) {
                self::$yieldChannel[$connName] = new Coroutine\Channel(1);
            }
            ++self::$pendingFetchCount[$connName];

            $conn = self::CoPop(self::$yieldChannel[$connName], self::$connsConfig[$connName]['serverInfo']['timeout']);

            if ($conn === false) {
                --self::$pendingFetchCount[$connName];
                throw new RedisException('max connections! Cann\'t pending fetch!');
            }

            --self::$resumeFetchCount[$connName];

            if (!empty($connsPool)) {
                $conn = array_pop($connsPool);
                if (!$conn->connected) {
                    $conn = self::reconnect($conn, $connName);
                    --self::$pendingFetchCount[$connName];
                } else {
                    $id = spl_object_id($conn);
                    self::$busyConns[$connName][spl_object_hash($conn)] = $conn;
                    self::$lastConnsTime[$id] = microtime(true);
                    --self::$pendingFetchCount[$connName];
                }
                defer(function () use ($conn) {
                    self::recycle($conn);
                });
                return $conn;
            } else {
                return false;//should not happen
            }
        }
        return self::initConn($connName);
    }

    /**
     * 初始化连接
     * @param string $connName
     * @return Redis
     * @throws RedisException
     */
    public static function initConn(string $connName)
    {
        ++self::$initConnCount[$connName];
        $conn = new Redis(self::$connsConfig[$connName]['options'] ?? []);
        $id = spl_object_hash($conn);
        self::$connsNameMap[$id] = $connName;
        self::$busyConns[$connName][$id] = $conn;
        if ($conn->connect(self::$connsConfig[$connName]['serverInfo']['host'], self::$connsConfig[$connName]['serverInfo']['port']) == false) {
            unset(self::$busyConns[$connName][$id]);
            unset(self::$connsNameMap[$id]);
            --self::$initConnCount[$connName];
            throw new RedisException('Conn\'t connect to Redis server: ' . json_encode(self::$connsConfig[$connName]['serverInfo']));
        }
        self::$lastConnsTime[$id] = microtime(true);
        --self::$initConnCount[$connName];
        defer(function () use ($conn) {
            self::recycle($conn);
        });
        return $conn;
    }

    /**
     * 断线重连
     * @param $conn
     * @param $connName
     * @return Redis|null
     * @throws RedisException
     */
    public static function reconnect($conn, $connName): ?Redis
    {
        if (!$conn->connected) {
            $old_id = spl_object_hash($conn);
            unset(self::$busyConns[$connName][$old_id]);
            unset(self::$connsNameMap[$old_id]);
            self::$lastConnsTime[$old_id] = 0;
            return self::initConn($connName);
        }
        return $conn;
    }
}