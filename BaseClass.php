<?php
namespace duzun\CallbackCache;

/**
 * Cache decorator to wrap a callback.
 *
 * In concurenct scenarios, only one thread invokes the callback and
 * writes its return value to cache, while other threads wait, then read from cache.
 *
 * If cache item is expired, prefer to return expired value,
 * while refreshing it in background.
 *
 * @version 1.0.0
 * @author Dumitru Uzun (https://DUzun.Me)
 */

abstract class BaseClass {

    public const SRC_CACHE = '_cache_';

    /**
     * Base folder for relative filenames
     * @var string
     */
    public static $base_dir = '';

    /**
     * Default file extension
     * @var string
     */
    public static $ext;

    /**
     * If TRUE, when there is a stale cache item and other thread is refreshing it,
     * prefer the stale item to waiting for fresh one.
     * The default is better for performance, but might yeld unexpected/stale contents
     * from cache.
     *
     * @var boolean
     */
    public static $prefer_stale = true;

    /**
     * If > 0, after Funcs::request_time(true) > $request_timeout, always prefer cached version of resources
     * @var int
     */
    public static $request_timeout;

    public static $fn_request_time = Funcs::class . '::request_time';

    // -------------------------------------------------------------------------
    protected $_ttl = 600; // sec
    protected $_lock_to = 1e3; // msec
    protected $_source;
    protected $_filename;
    protected $_dir;
    protected $_prefix;
    protected $_data;
    // protected $_args;

    // -------------------------------------------------------------------------
    /**
     * Read cache contents and meta.
     *
     * @param  string $chf    cache filename
     * @param  int &$mtime modification time is returned through this variable
     * @return array [$data, $meta, $mtime] or NULL if cache entry is missing
     */
    abstract protected static function _readFile($chf, &$mtime=NULL);

    /**
     * Write contents and meta to cache.
     *
     * @param  mixed $data Data to be cached
     * @param  array $meta Meta associated with $data
     * @param  string $chf cache filename
     * @return bool|int  number of written bytes or false on error
     */
    abstract protected static function _writeFile($data, $meta, $chf);

    /**
     * Remove contents and meta from cache.
     *
     * @param  string $chf cache filename
     * @param  int $mtime modification time; if FALSE, consider file already removed
     * @return bool  true on success or false on error
     */
    abstract protected static function _removeFile($chf, $mtime=NULL);


    // -------------------------------------------------------------------------
    /**
     * Constructor wraps a callable with cache functionality.
     *
     * @param callable $source  A callable that returns some data to be cached.
     * @param int|null $ttl     Time-To-Live in seconds
     * @param string   $path    Filesystem path where to store cache files and lock files (see flock())
     * @param int|null $lock_to Lock timeout in milliseconds. If a file is locked for more than $lock_to, blocking ends.
     */
    public function __construct($source=NULL, int $ttl=NULL, string $path='', int $lock_to=NULL) {
        $path = static::norm_path($path);

        if ( substr($path, -1) == DIRECTORY_SEPARATOR ) {
            $this->_dir = $path;
            $prefix = static::source2prefix($source) and
            $this->_prefix = $prefix;
        }
        else {
            if ( $ext = static::$ext and strrchr($path, '.') != $ext ) {
                $path .= $ext;
            }
            $this->_filename = $path;
        }

        isset($ttl)     and $this->_ttl     = $ttl;
        isset($lock_to) and $this->_lock_to = $lock_to;
        isset($source)  and $this->_source  = $source;
    }

    // public function __destruct() {
    //     // Pre-compute items about to expire in BG
    //     if ( $args = $this->_args ) foreach($args as $fn => $a) {
    //         if ( ($this->_data[$fn][1]['src'] ?? NULL) == self::SRC_CACHE ) {

    //         }
    //     }
    // }

    public function __invoke(...$args) {
        $ret = $this->apply($args);
        return $ret ? $ret[0] : $ret; // ignore $meta and $mtime
    }

    // -------------------------------------------------------------------------
    public function getItem(...$args) {
        $ret = $this->getByArgs($args);
        return $ret ? $ret[0] : $ret; // ignore $meta and $mtime
    }

    public function getAge(...$args) {
        $mtime = Funcs::ncfmt($this->_getFilename($args)); // cache modification time
        return time()-$mtime;         // cache age
    }

    public function getMeta(...$args) {
        $ret = $this->getByArgs($args);
        return $ret ? $ret[1] : $ret; // ignore $meta and $mtime
    }

    public function refreshItem(...$args) {
        $ret = $this->refresh(...$args);
        return $ret ? $ret[0] : $ret; // ignore $meta and $mtime
    }

    public function get(...$args) {
        return $this->getByArgs($args);
    }

    public function refresh(...$args) {
        return $ret = $this->apply($args, 0);
    }

    public function delete(...$args) {
        $fn = $this->_getFilename($args);
        unset($this->_data[$fn]);
        return static::_removeFile($fn, file_exists($fn));
    }

    public function call(...$args) {
        return $this->apply($args);
    }

    public function apply(array $args, $ttl=NULL) {
        isset($ttl) or $ttl = $this->_ttl;
        $ret = $this->getByArgs($args, $ttl);
        if ( $ret ) return $ret;

        $fn = $this->_getFilename($args);
        $ret = $this->_data[$fn] ?? NULL;
        $mtime = $ret ? $ret[2] : false;

        // LOCK_EX
        $lockfile = $fn . '.lock';
        $clk = empty($this->_source) ? false : Funcs::file_lock($lockfile, LOCK_EX | LOCK_NB, function($fh) use ($args, $fn, $mtime, $ret) {
            if ( $mtime && !is_writable($fn) ) {
                throw new \Exception(__FUNCTION__ . ": File '$fn' is not writable");
            }

            $_ret = $this->_readSource($args);

            // No data from source
            if ( !$_ret ) return $_ret;

            // 304: Cached data is still good
            if ( $ret
                && $ret[0] === $_ret[0]
                && @$ret[1]['args'] === @$_ret[1]['args']
                && touch($fn) )
            {
                return $_ret;
            }

            list($data, $meta) = $_ret;

            // Delete from cache
            if ( is_null($data) ) {
                if ( static::_removeFile($fn, !!$mtime) ) {
                    $mtime = false;
                }
            }
            // Save to cache
            else {
                $meta['src'] = self::SRC_CACHE;
                static::_writeFile($data, $meta, $fn);
            }

            return $_ret;
        }, 16); // cache lock

        // $this->_args[$fn] = $args;

        if ( $clk !== false ) {
            return $this->_data[$fn] = $clk;
        }

        // No LOCK_EX:

        // Use expired cache while generating new contents from $source
        if ( static::$prefer_stale ) {
            if ( $ret ) return $ret;
            if ( $ret = static::_readFile($fn) ) {
                return $this->_data[$fn] = $ret;
            }
        }

        // No cache data available, not even expired, so wait for unlock
        $fh = Funcs::file_lock($lockfile, LOCK_SH, NULL, $this->_lock_to);
        if ( $fh ) {
            flock($fh, LOCK_UN) && fclose($fh);

            if ( $ret = static::_readFile($fn) ) {
                return $this->_data[$fn] = $ret;
            }
        }

        return $this->_data[$fn] = $this->_readSource($args);
    }

    public function getByArgs(array $args = [], $ttl=NULL) {
        $fn = $this->_getFilename($args);

        isset($ttl) or $ttl = $this->_ttl;

        // In (process) mem cache
        if ( $ttl && isset($this->_data[$fn]) ) {
            $ret = $this->_data[$fn];
            $mtime = @$ret[2];
        }
        else {
            // $mtime = Funcs::ncfmt($fn);         // cache modification time
            $ret = static::_readFile($fn, $mtime);
        }
        $age = time()-$mtime; // cache age
        $isFresh = $mtime && $age < $ttl; // is cache fresh?

        // if ( $ret ) {
        //     if ( empty($ret[1]['args']) != empty($args)
        //          || @$ret[1]['args'] !== $args )
        //     {
        //         $isFresh = false;
        //         $ret = NULL;
        //     }
        // }

        // If we have a fresh cache item, or a stale one, but request_time is greated than our target, return from cache
        if ( $ret ) {
            $this->_data[$fn] = $ret;

            if ( $isFresh || self::$request_timeout && static::request_time(true) > self::$request_timeout ) {
                return $ret;
            }
        }
    }

    // -------------------------------------------------------------------------
    public function getPath() {
        return $this->_filename ?: $this->_dir;
    }

    // -------------------------------------------------------------------------
    public function clear($ttl=NULL) {
        isset($ttl) or $ttl = $this->_ttl;
        return self::clean($this->getPath(), $this->_prefix, $ttl);
    }

    // -------------------------------------------------------------------------
    public static function clean($filename, $prefix=NULL, $ttl=NULL) {
        $ext = static::$ext;
        $filename = static::norm_path($filename);

        if ( substr($filename, -1) == DIRECTORY_SEPARATOR ) {
            $dir = $filename;
            if ( is_string($prefix) ) {
                $prefix = strtr($prefix, ':\\', '__') . '_';
            }
            if ($h = opendir($dir)) try {
                $l = -strlen($ext);
                $p = $prefix ? strlen($prefix) : 0;
                $list = [];
                while ($f = readdir($h)) {
                    if ( $f == '.' || $f == '..' ) continue;
                    if ( $p && strncmp($f, $prefix, $p) == 0 ) continue;
                    if ( $l && substr($f, $l) != $ext ) continue;
                    $f = $dir . $f;
                    $mtime = @filemtime($f);
                    if ( $mtime !== false ) {
                        if ( $ttl && time() - $mtime < $ttl ) continue; // Ok, no need to delete un-expired cache entry
                        $list[$f] = static::_removeFile($f, $mtime);
                    }
                }
                return $list;
            }
            finally {
                closedir($h);
            }
        }
        else {
            if ( $ext && strrchr($filename, '.') != $ext ) {
                $filename .= $ext;
            }
            $mtime = @filemtime($filename);
            if ( $mtime !== false ) {
                if ( $ttl && time() - $mtime < $ttl ) return true; // Ok, no need to delete un-expired cache entry
                if ( !static::_removeFile($filename, $mtime) ) return false;
            } // else Ok, no cache entry

            return true; // Ok
        }
    }

    // -------------------------------------------------------------------------
    public static function norm_path($filename) {
        $sep = DIRECTORY_SEPARATOR;
        $filename = Funcs::secure_path($filename, $sep);

        if ( !isset(static::$base_dir) ) {
            static::$base_dir = sys_get_temp_dir() . $sep;
        }

        !static::$base_dir or
        Funcs::is_abs_path($filename) or
        $filename = str_replace($sep.'.'.$sep, $sep, static::$base_dir . $filename);

        return $filename;
    }

    public static function source2prefix($source) {
         // ['class', 'method'] -> 'class::method'
        if ( is_array($source) && count($source) && is_string($source[0]) && is_string($source[1]) ) {
            $source = implode('::', $source);
        }
        if ( is_string($source) ) {
            return strtr($source, ':\\', '__') . '_';
        }
    }

    // -------------------------------------------------------------------------
    public static function request_time($duration=true) {
        $fn = static::$fn_request_time ?: (Funcs::class . '::request_time');
        return $fn($duration);
    }

    // -------------------------------------------------------------------------
    protected function _getFilename($args=NULL) {
        if ( $chf = $this->_filename ) return $chf;
        $charset = Funcs::charset();
        if ( $charset == 'utf-8' ) $charset = NULL; // ignore default charset
        return $this->_dir . $this->_prefix . self::_args2hash($args) . ($charset?'_c'.$charset:'') . static::$ext;
    }

    protected static function _args2hash($args) {
        if ( !$args ) return '';
        $hasNonScalars = false;
        foreach($args as $a) {
            if ( !is_null($a) && !is_scalar($a) ) {
                $hasNonScalars = true;
                break;
            }
        }
        return $hasNonScalars ? 'h' . sha1(serialize($args)) : implode('-', $args);
    }

    protected function _readSource($args=[]) {
        $source = $this->_source;
        if ( !$source ) return NULL;

        $_t = microtime(true);
        $data = call_user_func_array($source, $args);
        $_exe = microtime(true) - $_t;

        if ( is_null($data) ) {
            return [NULL, NULL, false];
        }

        $meta = [
            'mtime'   => microtime(true),
            'exe'     => $_exe,
            'count'   => count($data),
            'charset' => Funcs::charset(), // just in case someone is not using utf8
            'src' => is_string($source) ? $source : '_source_'
        ];

        if ( is_string($source) ) {
            $meta['func'] = $source;
        }
        if ( $args ) {
            $meta['args'] = $args;
        }

        return [$data, $meta, time()];
    }
}
