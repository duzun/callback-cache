<?php
namespace duzun\CallbackCache;

Funcs::$init_time = microtime(true);

class Funcs {

	public static $init_time;

	// ---------------------------------------------------------------
	/**
	 * Lock with retries
	 *
	 * @param (resource)$fp - Open file pointer
	 * @param (int) $lock - Lock type
	 * @param (int) $timeout_ms - Timeout to wait for unlock in milliseconds
	 *
	 * @return true on success, false on fail
	 *
	 * @author Dumitru Uzun
	 *
	 */
	public static function do_flock($fp, $lock, $timeout_ms = 384) {
	    $l = flock($fp, $lock);
	    if (!$l && ($lock & LOCK_UN) != LOCK_UN) {
	        $st = microtime(true);
	        $m = min(1e3, $timeout_ms * 1e3);
	        $n = min(64e3, $timeout_ms * 1e3);
	        if ($m == $n) {
	            $m = ($n >> 1) + 1;
	        }

	        $timeout_ms = (float) $timeout_ms / 1000;
	        // If lock not obtained sleep for 0 - 64 milliseconds, to avoid collision and CPU load
	        do {
	            usleep($t = rand($m, $n));
	            $l = flock($fp, $lock);
	        } while (!$l && (microtime(true) - $st) < $timeout_ms);
	    }
	    return $l;
	}

	// ---------------------------------------------------------------
	/**
	 * Try to lock a file by it's path and return file handler or execute an action and unlock.
	 *
	 * @param  string        $fn     Filename
	 * @param  int           $lock   Lock type (LOCK_EX | LOCK_SH | LOCK_NB)
	 * @param  int           $wait   Timeout to wait for unlock in milliseconds
	 * @param  callable|null $onLock A callable($fh, $fn) to execute when lock is successfully aquired
	 * @return int|bool|mixed  Return of $onLock($fh, $fn), file handler when no $onLock, false when no lock
	 */
	public static function file_lock(string $fn, int $lock, $onLock=NULL, $wait=368) {
	    $t = @fopen($fn, 'c+') or
	    @mkdir(dirname($fn), 0777, true) and $t = fopen($fn, 'c+');
	    if( $t and $l = self::do_flock($t, $lock, $wait) ) {
	        if ( !$onLock ) return $t; // don't forget to flock($t, LOCK_UN) && fclose($t);
	        try {
	            return call_user_func($onLock, $fn, $t);
	        }
	        finally {
	            flock($t, LOCK_UN);
	            if ( ftell($t) === 0 ) unlink($fn); // remove empty lock-file
	            fclose($t);
	        }
	    }
	    $t and fclose($t);
	    return false; // Not locked
	}

	// -------------------------------------------------------------------------
	public static function flock_put_contents($fn, $cnt, $block = false) {
	    // return file_put_contents($fn, $cnt, $block & FILE_APPEND);
	    $ret = false;
	    if ($f = fopen($fn, 'c+')) {
	        $app = $block & FILE_APPEND and $block ^= $app;
	        if ($block ? self::do_flock($f, LOCK_EX) : flock($f, LOCK_EX | LOCK_NB)) {
	            // if (is_array($cnt) || is_object($cnt)) {
	            //     $cnt = jsonize($cnt);
	            // }

	            if ($app) {
	                fseek($f, 0, SEEK_END);
	            }

	            if (false !== ($ret = fwrite($f, $cnt))) {
	                fflush($f);
	                ftruncate($f, ftell($f));
	            }
	            flock($f, LOCK_UN);
	        }
	        fclose($f);
	    }
	    return $ret;
	}

	// -------------------------------------------------------------------------
	public static function flock_get_contents($fn, $block = false) {
	    // return file_get_contents($fn);
	    $ret = false;
	    if ($f = fopen($fn, 'r')) {
	        if (flock($f, LOCK_SH | ($block ? 0 : LOCK_NB))) {
	            $s = 1 << 14;
	            do$ret .= $r = fread($f, $s);while ($r !== false && !feof($f));
	            if ($ret == NULL && $r === false) {
	                $ret = $r;
	            }

	            // filesize result is cached
	            flock($f, LOCK_UN);
	        }
	        fclose($f);
	    }
	    return $ret;
	}

	// -------------------------------------------------------------------------
	/**
	 * Try to get the exact time when the request started or
	 * the duration since the request start.
	 *
	 * @param  int $duration If false, get the request start time,
	 *                       otherwize $duration is a factor.
	 * @return float time in seconds or duration multiplied by $duration.
	 */
	public static function request_time($duration=false) {
	    $rt = getenv('REQUEST_TIME_FLOAT') ?: $_SERVER['REQUEST_TIME_FLOAT'];
	    if ( !$rt and $rt = getenv('REQUEST_TIME') ?: $_SERVER['REQUEST_TIME'] ) {
	        if ( $init_time = self::$init_time ) {
	            if ( (int)$init_time == $rt ) {
	                $rt = $init_time;
	                $_SERVER['REQUEST_TIME_FLOAT'] = $rt; // not accurate, but kind of a cache
	            }
	        }
	    }
	    if ( !$rt ) {
	        $rt = self::$init_time;
	    }
	    return $duration ? (microtime(true) - $rt) * $duration : $rt;
	}

	// -------------------------------------------------------------------------
	/// NoCacheFileMTime($filename)
	public static function ncfmt($fn) {
	    // No need to support PHP < 5.3 any more!
	    // static $v;
	    // isset($v) or $v = version_compare(PHP_VERSION, '5.3.0') < 0;
	    // $v ? clearstatcache() : clearstatcache(true, $fn);
	    clearstatcache(true, $fn);
	    return @filemtime($fn);
	}

	// -------------------------------------------------------------------------
	public static function is_abs_path($path) {
	    $ds = array('\\' => 1, '/' => 2);
	    if (isset($ds[substr($path, 0, 1)]) || substr($path, 1, 1) == ':' && isset($ds[substr($path, 2, 1)])) {
	        return true;
	    }

	    if (($l = strpos($path, '://')) && $l < 32) {
	        return $l;
	    }

	    return false;
	}

	// -------------------------------------------------------------------------
	public static function secure_path($path, $sep = NULL, $sanitize = false) {
	    isset($sep) or $sep = '/';
	    $path = str_replace(chr(0), '', $path);
	    $path = str_replace('/' == $sep ? '\\' : '/', $sep, $path);

	    do {
	        $_path = $path;
            $path = str_replace([$sep . '..' . $sep, $sep . '.' . $sep], [$sep, $sep], $path);
	    } while($_path !== $path);

	    if ( strncmp($path, '..' . $sep, 3) == 0 ) {
	        $path = substr($path, 3);
	    }

	    if ( $sanitize ) {
	        $path = strtr($path, ":\r\n\t\b", '-    ');
	    }

	    return $path;
	}

	// -------------------------------------------------------------------------
	public static function charset() {
        if ( function_exists('mb_convert_encoding') ) {
            return strtolower(mb_internal_encoding());
        }
        if ( function_exists('iconv_get_encoding') ) {
            return strtolower(iconv_get_encoding('internal_encoding'));
        }
	}

	// -------------------------------------------------------------------------

}
