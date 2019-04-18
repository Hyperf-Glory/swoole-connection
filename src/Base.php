<?php

namespace INocturneSwoole\Connection;

use Swoole\Coroutine\Channel;

class Base extends Context
{
    /**
     * 协程执行异常
     *
     * @param \Closure $function
     * Author: HePing
     * Email:  847050412@qq.com
     * Date:  2019-03-02
     * Time: 15:47
     */
    protected static function go(\Closure $function)
    {

        if (-1 !== \Swoole\Coroutine::getuid()) {
            $pool = self::$pool[\Swoole\Coroutine::getuid()] ?? false;
        } else {
            $pool = false;
        }
        go(function () use ($function, $pool)
        {
            try {
                if ($pool) {
                    self::$pool[\Swoole\Coroutine::getuid()] = $pool;
                }
                $function();
                if ($pool) {
                    unset(self::$pool[\Swoole\Coroutine::getuid()]);
                }
            } catch (SMConException $SMConException) {

            } catch (MySQLException $mySQLException) {

            }
        });
    }

    /**
     * @param \Swoole\Coroutine\Channel $channel
     * @param int                       $timeout
     *
     * @return bool|mixed
     */
    protected static function CoPop(Channel $channel, int $timeout = 0)
    {
        if (version_compare(swoole_version(), '4.3.0', '>=')) {
            return $channel->pop($timeout);
        } else {
            {
                if (0 == $timeout) {
                    return $channel->pop($timeout);
                } else {
                    return false;
                }
            }
        }
    }

}