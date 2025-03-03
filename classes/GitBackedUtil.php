<?php

namespace woolfg\dokuwiki\plugin\gitbacked;

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

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

// ------------------------------------------------------------------------

/**
 * GitBacked Utility Class
 *
 * This class provides common utility functions.
 *
 * @class  GitBackedUtil
 */
class GitBackedUtil
{
    /**
     * GitBacked temp directory
     *
     * @var string
     */
    protected static $temp_dir = '';

    /**
     * Checks, if a given path name is an absolute path or not.
     * This function behaves like absolute path determination in dokuwiki init.php fullpath() method.
     * The relevant code has been copied from there.
     *
     * @access  public
     * @param   string $path    a file path name
     * @return  bool
     */
    public static function isAbsolutePath($path)
    {
        $ret = false;

        $iswin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' || !empty($GLOBALS['DOKU_UNITTEST_ASSUME_WINDOWS']));
        // check for the (indestructable) root of the path - keeps windows stuff intact
        if ($path[0] == '/') {
            $ret = true;
        } elseif ($iswin) {
            // match drive letter and UNC paths
            if (preg_match('!^([a-zA-z]:)(.*)!', $path, $match)) {
                $ret = true;
            } elseif (preg_match('!^(\\\\\\\\[^\\\\/]+\\\\[^\\\\/]+[\\\\/])(.*)!', $path, $match)) {
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
    public static function getEffectivePath($path)
    {
        $ret = $path;

        if (self::isAbsolutePath($ret)) {
            return $ret;
        }
        if (defined('DOKU_FARM')) {
            $ret = DOKU_CONF . '../' . $ret;
        } else {
            $ret = DOKU_INC . $ret;
        }
        return $ret;
    }

    /**
     * Returns the temp dir for GitBacked plugin.
     * It ensures that the temp dir will be created , if not yet existing.
     *
     * @access  public
     * @return  string          the gitbacked temp directory name
     */
    public static function getTempDir()
    {
        if (empty(self::$temp_dir)) {
            global $conf;
            self::$temp_dir = $conf['tmpdir'] . '/gitbacked';
            io_mkdir_p(self::$temp_dir);
        }
        return self::$temp_dir;
    }

    /**
     * Creates a temp file and writes the $message to it.
     * It ensures that the temp dir will be created , if not yet existing.
     *
     * @access  public
     * @param   string $message the text message
     * @return  string          the temp filename created
     */
    public static function createMessageFile($message)
    {
        $tmpfname = tempnam(self::getTempDir(), 'gitMessage_');
        $handle = fopen($tmpfname, "w");
        if (!empty($message)) {
            fwrite($handle, $message);
        }
        fclose($handle);
        return $tmpfname;
    }

    /**
     * Determine closest git repository path for a given path as absolute PHP realpath().
     * This search starts in $path - if $path does not contain .git,
     * it will iterate the directories upwards as long as it finds a directory
     * containing a .git folder.
     *
     * @access  public
     * @return  string  the next git repo root dir as absolute PHP realpath()
     *                  or empty string, if no git repo found.
     */
    public static function getClosestAbsoluteRepoPath($path)
    {
        $descriptorspec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        // Using --git-dir rather than --absolute-git-dir for a wider git versions compatibility
        //$command = Git::getBin()." rev-parse --absolute-git-dir";
        $command = Git::getBin() . " rev-parse --git-dir";
        //dbglog("GitBacked - Command: ".$command);
        $resource = proc_open($command, $descriptorspec, $pipes, $path);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $status = trim(proc_close($resource));
        if ($status == 0) {
            $repo_git_dir = trim($stdout);
            //dbglog("GitBacked - $command: '".$repo_git_dir."'");
            if (!empty($repo_git_dir)) {
                if (strcmp($repo_git_dir, ".git") === 0) {
                    // convert to absolute path based on this command execution directory
                    $repo_git_dir = $path . '/' . $repo_git_dir;
                }
                $repo_path = dirname(realpath($repo_git_dir));
                $doku_inc_path = dirname(realpath(DOKU_INC));
                if (strlen($repo_path) < strlen($doku_inc_path)) {
                    // This code should never be reached!
                    // If we get here, then we are beyond DOKU_INC - so this not supposed to be for us!
                    return '';
                }
                //dbglog('GitBacked - $repo_path: '.$repo_path);
                if (file_exists($repo_path . "/.git") && is_dir($repo_path . "/.git")) {
                    return $repo_path;
                }
            }
        }
        return '';
    }
}
/* End of file */
