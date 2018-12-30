<?php
namespace duzun\CallbackCache;
/**
 * PHP (OPCached) cache implementation with parallel requests support (locks).
 *
 * @version 1.1.0
 * @author Dumitru Uzun (https://DUzun.Me)
 */

use Symfony\Component\VarExporter\VarExporter;

class PHP extends BaseClass {
    public static $ext = '.php';
    // public static $base_dir = CACHE_DIR;

    protected static $_hsOPC;

    protected static function _readFile($chf, &$mtime=NULL) {
        $mtime = Funcs::ncfmt($chf);         // cache modification time
        if ( $mtime ) {
            if ( $cnt = self::include_php($chf, ['count', 'mtime', 'exe', 'charset', 'func', 'args', 'src']) ) {
                list($vars, $cnt) = $cnt;
                list($meta, $data) = $cnt;
                    // payload, mtime, meta
                return [$data, $meta, $mtime];
            }
        }
    }

    protected static function _writeFile($data, $meta, $chf) {
        if ( class_exists(VarExporter::class) ) {
            $data = VarExporter::export([$meta, $data]);
        }
        else {
            $data = var_export([$data, $meta], true);
        }
        $data = '<'."?php\nreturn " . $data . ';';
        $ret = Funcs::flock_put_contents($chf, $data);
        if ( $ret !== false && self::hasOPC() ) {
            @opcache_invalidate($chf, true);
            @opcache_compile_file($chf);
        }
        return $ret;
    }

    protected static function _removeFile($chf, $mtime=NULL) {
        if ( $mtime === false ) return true;
        if ( $ret = unlink($chf) ) {
            $hasOPC = self::hasOPC();
            $hasOPC and @opcache_invalidate($chf, true);
            clearstatcache(true, $chf);
        }
        return $ret;
    }

    /**
     * Check OPCache support.
     *
     * @param  boolean $recheck If true, ignore cached value.
     * @return boolean
     */
    public static function hasOPC($recheck=false): bool {
        isset(self::$_hsOPC) and !$recheck or self::$_hsOPC = \function_exists('opcache_invalidate') && ini_get('opcache.enable') && ('cli' !== \PHP_SAPI || ini_get('opcache.enable_cli'));
        return self::$_hsOPC;
    }

    // -------------------------------------------------------------------------
    /**
     * Include a PHP file with LOCK_SH and optionaly
     * read some variables declared in the file
     *
     * @param  string     $__filename__ Filename
     * @param  array|null $__varnames__ List of variable names declared in $__filename__
     * @return array [variables, return] of $__filename__
     */
    public static function include_php(string $__filename__, array $__varnames__=NULL) {
        try {
            $__meta__ = [];

            if ( $__fhandler__ = fopen($__filename__, 'r') and $__locked__ = flock($__fhandler__, LOCK_SH) ) {
                $contents = include $__filename__;
                $__locked__ and $__locked__ = !flock($__fhandler__, LOCK_UN); // unlock ASAP

                // $__meta__ += compact('charset', 'count');
                $__varnames__ and $__meta__ += compact($__varnames__);
                if(!empty($__meta__['charset'])) $__meta__['charset'] = strtolower($__meta__['charset']);

                if(is_array($contents)) {
                    isset($__meta__['count']) or $__meta__['count'] = count($contents);
                };
            }
            else {
                $contents = false;
            }
        }
        catch(\Throwable $e) {
            $__meta__ = false; // Syntax Error
        }
        finally {
            if ( $__fhandler__ ) {
                $__locked__ and flock($__fhandler__, LOCK_UN);
                fclose($__fhandler__);
            }
        }

        return [$__meta__, $contents];
    }

    // -------------------------------------------------------------------------

}
