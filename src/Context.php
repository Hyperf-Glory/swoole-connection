<?php

namespace INocturneSwoole\Connection;

use Swoole\Coroutine;

/**
 * Class Context
 * @desc    保存上下文
 * @package INocturne\Swoole\Connection
 */
class Context
{
    protected static $pool = [];

    /**
     * @param   string $key
     *
     * @return null
     */
    public static function get(string $key)
    {
        $cid = Coroutine::getuid();
        if ($cid < 0) {
            return null;
        }
        if (isset(self::$pool[$cid][$key])) {
            return self::$pool[$cid][$key];
        }
        return null;
    }

    /**
     * @param string $key
     * @param        $item
     */
    public static function put(string $key, $item)
    {
        $cid = Coroutine::getuid();
        if ($cid > 0) {
            self::$pool[$cid][$key] = $item;
        }
    }

    /**
     * @param string|null $key
     */
    public static function delete(string $key = null)
    {
        $cid = Coroutine::getuid();
        if ($cid > 0) {
            if ($key) {
                unset(self::$pool[$cid][$key]);
            } else {
                unset(self::$pool[$cid]);
            }
        }
    }
}