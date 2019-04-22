<?php
/**
 * Created by PhpStorm.
 * User: HePing
 * Date: 2019-03-02
 * Time: 14:34
 */
namespace INocturneSwoole\Connection;

use Swoole\Coroutine;

/**
 * Class Context
 * @desc    保存上下文
 * @package INocturne\Swoole\Connection
 * Author: HePing
 * Email:  847050412@qq.com
 * Date:  2019-03-02
 * Time: 15:02
 */
class Context
{
    protected static $pool = [];

    /**
     * @param   string $key
     *
     * @return null
     * Author: HePing
     * Email:  847050412@qq.com
     * Date:  2019-03-02
     * Time: 14:42
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
     * Author: HePing
     * Email:  847050412@qq.com
     * Date:  2019-03-02
     * Time: 14:54
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
     * Author: HePing
     * Email:  847050412@qq.com
     * Date:  2019-03-02
     * Time: 15:02
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