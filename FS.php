<?php
namespace duzun\CallbackCache;
/**
 * FileSystem cache implementation with parallel requests support (locks).
 *
 * @version 1.1.0
 * @author Dumitru Uzun (https://DUzun.Me)
 */


class FS extends BaseClass {
    public static $ext = '.dat';
    // public static $base_dir = CACHE_DIR;

    protected static $_snappy;
    protected static $_gzip;

    protected static function _readFile($chf, &$mtime=NULL) {
        $mtime = Funcs::ncfmt($chf);         // cache modification time
        if ( $mtime ) {
            $cnt = flock_get_contents($chf);

            // $cnt is .gz
            if ( $isGZ = strncmp($cnt, "\x1F\x8B", 2) == 0 ) {
                if ( !self::hasGZ() ) return false;
                $cnt = gzdecode($cnt);
                if ( $cnt === false ) return false;
            }
            else {
                $type = serjstype($cnt);
                if ( false === $type && self::hasSnappy() ) {
                    $_cnt = snappy_uncompress($cnt);
                    if ( $_cnt !== false ) {
                        $cnt = $_cnt;
                        $type = serjstype($cnt);
                    }
                }
            }

            $cnt = unjsonize($cnt, $type);
            if ( $cnt === false ) return false; // wrong format

            list($meta, $data) = $cnt;
                // payload, mtime, meta
            return [$data, $meta, $mtime];
        }
    }

    protected static function _writeFile($data, $meta, $chf) {
        $cnt = serialize([$meta, $data]);
        if ( strlen($cnt) > 128 && self::hasSnappy() ) {
            $_cnt = snappy_compress($cnt) and $cnt = $_cnt;
        }
        return flock_put_contents($chf, $cnt);
    }

    static protected function _removeFile($chf, $mtime=NULL) {
        if ( $mtime === false ) return true;
        if ( $ret = unlink(chf) ) {
            clearstatcache(true, chf);
        }
        return $ret;
    }

    public static function hasGZ($recheck=false): bool {
        isset(self::$_gzip) and !$recheck or self::$_gzip = \function_exists('gzdecode') ? '.gz' : false;
        return self::$_gzip;
    }

    public static function hasSnappy($recheck=false): bool {
        isset(self::$_snappy) and !$recheck or self::$_snappy = function_exists('snappy_compress') && function_exists('snappy_uncompress') ? '.sz' : false;
        return self::$_snappy;
    }
}
