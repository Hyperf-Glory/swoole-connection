<?php
namespace INocturneSwoole\Connection;

use Swoole\Coroutine;
use Swoole\Coroutine\Redis;

class RedisPool
{
    protected static $init = false;
    protected static $spareConns = [];
    protected static $busyConns = [];
    protected static $connsConfig;
    protected static $connsNameMap = [];
    protected static $pendingFetchCount = [];
    protected static $resumeFetchCount = [];

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
            self::$spareConns[$name]        = [];
            self::$busyConns[$name]         = [];
            self::$pendingFetchCount[$name] = [];
            self::$resumeFetchCount[$name]  = 0;
            if ($config['maxSpareConns'] <= 0 || $config['maxConns'] <= 0) {
                throw new RedisException("Invalid maxSpareConns or maxConns in {$name}");
            }
        }
        self::$init = true;
    }

    /**
     * @param \Swoole\Coroutine\Redis $conn
     *
     * @throws RedisException
     */
    public static function recycle(Redis $conn)
    {
        if (!self::$init) {
            throw new RedisException('Should call RedisPool::init.');
        }
        $id       = spl_object_hash($conn);
        $connName = self::$connsNameMap[$id];
        if (isset(self::$busyConns[$connName][$id])) {
            unset(self::$busyConns[$connName][$id]);
        } else {
            throw new RedisException('Unknow Redis connection.');
        }
        $connsPool = &self::$spareConns[$connName];
        if ($conn->connected) {
            if (count($connsPool) >= self::$connsConfig[$connName]['maxSpareConns']) {
                $conn->close();
            } else {
                $connsPool[] = $conn;
                if (count(self::$pendingFetchCount[$connName]) > 0) {
                    self::$resumeFetchCount[$connName]++;
                    Coroutine::resume(array_shift(self::$pendingFetchCount[$connName]));
                }
                return;
            }
        }
        unset(self::$connsNameMap[$id]);
    }

    /**
     * @param $connName
     *
     * @return bool|mixed|\Swoole\Coroutine\Redis
     * @throws RedisException
     */
    public static function fetch($connName) : ?Redis
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
                $id                              = spl_object_hash($conn);
                self::$busyConns[$connName][$id] = $conn;
            }
            defer(function () use ($conn)
            {
                self::recycle($conn);
            });
            return $conn;
        }
        if (count(self::$busyConns[$connName]) + count($connsPool) == self::$connsConfig[$connName]['maxConns']) {
            $cid                                      = Coroutine::getuid();
            self::$pendingFetchCount[$connName][$cid] = $cid;
            if (Coroutine::suspend($cid) == false) {
                unset(self::$pendingFetchCount[$connName][$cid]);
                throw new RedisException('Reach max connections! Conn\'t pending fetch!');
            }
            self::$resumeFetchCount[$connName]--;
            if (!empty($connsPool)) {
                $conn = array_pop($connsPool);
                if (!$conn->connected) {
                    $conn = self::reconnect($conn, $connName);
                } else {
                    self::$busyConns[$connName][spl_object_hash($conn)] = $conn;
                }
                defer(function () use ($conn)
                {
                    self::recycle($conn);
                });
                return $conn;
            } else {
                return false;//should not happen
            }
        }
        $conn                            = new Redis();
        $id                              = spl_object_hash($conn);
        self::$connsNameMap[$id]         = $connName;
        self::$busyConns[$connName][$id] = $conn;
        if ($conn->connect(
                self::$connsConfig[$connName]['serverInfo']['host'],
                self::$connsConfig[$connName]['serverInfo']['port']
            ) == false) {
            unset(self::$busyConns[$connName][$id]);
            unset(self::$connsNameMap[$id]);
            throw new RedisException('Conn\'t connect to Redis server: ' . json_encode(self::$connsConfig[$connName]['serverInfo']));
        }
        defer(function () use ($conn)
        {
            self::recycle($conn);
        });
        return $conn;
    }

    /**
     * 断线重链
     *
     * @param $conn
     * @param $connName
     *
     * @return \Swoole\Coroutine\Redis
     */
    public static function reconnect($conn, $connName) : ?Redis
    {
        if (!$conn->connected) {
            $old_id = spl_object_hash($conn);
            unset(self::$busyConns[$connName][$old_id]);
            unset(self::$connsNameMap[$old_id]);
            $conn = new Redis();
            $conn->connect(self::$connsConfig[$connName]['serverInfo']);
            $id                              = spl_object_hash($conn);
            self::$connsNameMap[$id]         = $connName;
            self::$busyConns[$connName][$id] = $conn;
            return $conn;
        }
        return $conn;
    }
}