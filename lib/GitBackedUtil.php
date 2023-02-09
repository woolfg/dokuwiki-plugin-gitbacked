<?php

/*
 * GitBackedUtil.php
 *
 * PHP common utility function lib
 *
 * @package    GitBackedUtil.php
 * @version    1.0
 * @author     Markus Hoffrogge
 * @copyright  Copyright 2023 Markus Hoffrogge
 * @repo       https://github.com/woolfg/dokuwiki-plugin-gitbacked
 */

if (__FILE__ == $_SERVER['SCRIPT_FILENAME']) die('Bad load order');

// ------------------------------------------------------------------------

/**
 * GitBacked Utility Class
 *
 * This class provides common utility functions.
 *
 * @class  GitBackedUtil
 */
class GitBackedUtil {

    /**
     * Checks, if a given path name is an absolute path or not.
     * This function behaves like absolute path determination in dokuwiki init.php fullpath() method.
     * The relevant code has been copied from there.
     *
     * @access  public
     * @param   string $path    a file path name
     * @return  bool
     */
    public static function isAbsolutePath($path) {
        $ret = false;

        $iswin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' || !empty($GLOBALS['DOKU_UNITTEST_ASSUME_WINDOWS']));
        // check for the (indestructable) root of the path - keeps windows stuff intact
        if($path[0] == '/') {
            $ret = true;
        } else if($iswin) {
            // match drive letter and UNC paths
            if(preg_match('!^([a-zA-z]:)(.*)!',$path,$match)) {
                $ret = true;
            } else if(preg_match('!^(\\\\\\\\[^\\\\/]+\\\\[^\\\\/]+[\\\\/])(.*)!',$path,$match)) {
                $ret = true;
            }
        }

        return $ret;
    }
    
    /**
     * Returns the $path as is, if it is an absolute path.
     * Otherwise it will prepend the base path appropriate
     * to the type of DW instance.
     *
     * @access  public
     * @param   string $path    a file path name
     * @return  string          an appropriate absolute path 
     */
    public static function getEffectivePath($path) {
        $ret = $path;
        
        if (self::isAbsolutePath($ret)) {
            return $ret;
        }
        if (defined('DOKU_FARM')) {
            $ret = DOKU_CONF.'../'.$ret;
        } else {
            $ret = DOKU_INC.$ret;
        }
        return $ret;
    }

}
/* End of file */
