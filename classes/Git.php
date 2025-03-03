<?php

namespace woolfg\dokuwiki\plugin\gitbacked;

/*
 * Git.php
 *
 * A PHP git library
 *
 * @package    Git.php
 * @version    0.1.4
 * @author     James Brumond
 * @copyright  Copyright 2013 James Brumond
 * @repo       http://github.com/kbjr/Git.php
 */

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
if (__FILE__ == $_SERVER['SCRIPT_FILENAME']) die('Bad load order');

// ------------------------------------------------------------------------

/**
 * Git Interface Class
 *
 * This class enables the creating, reading, and manipulation
 * of git repositories.
 *
 * @class  Git
 */
class Git
{
    /**
     * Git executable location
     *
     * @var string
     */
    protected static $bin = '/usr/bin/git';

    /**
     * Sets git executable path
     *
     * @param string $path executable location
     */
    public static function setBin($path)
    {
        self::$bin = $path;
    }

    /**
     * Gets git executable path
     */
    public static function getBin()
    {
        return self::$bin;
    }

    /**
     * Sets up library for use in a default Windows environment
     */
    public static function windowsMode()
    {
        self::setBin('git');
    }

    /**
     * Create a new git repository
     *
     * Accepts a creation path, and, optionally, a source path
     *
     * @access  public
     * @param   string  repository path
     * @param   string  directory to source
     * @param   \action_plugin_gitbacked_editcommit plugin
     * @return  GitRepo
     */
    public static function &create($repo_path, $source = null, \action_plugin_gitbacked_editcommit $plugin = null)
    {
        return GitRepo::createNew($repo_path, $source, $plugin);
    }

    /**
     * Open an existing git repository
     *
     * Accepts a repository path
     *
     * @access  public
     * @param   string  repository path
     * @param   \action_plugin_gitbacked_editcommit plugin
     * @return  GitRepo
     */
    public static function open($repo_path, \action_plugin_gitbacked_editcommit $plugin = null)
    {
        return new GitRepo($repo_path, $plugin);
    }

    /**
     * Clones a remote repo into a directory and then returns a GitRepo object
     * for the newly created local repo
     *
     * Accepts a creation path and a remote to clone from
     *
     * @access  public
     * @param   string  repository path
     * @param   string  remote source
     * @param   string  reference path
     * @param   \action_plugin_gitbacked_editcommit plugin
     * @return  GitRepo
     **/
    public static function &cloneRemote(
        $repo_path,
        $remote,
        $reference = null,
        \action_plugin_gitbacked_editcommit $plugin = null
    ) {
        return GitRepo::createNew($repo_path, $plugin, $remote, true, $reference);
    }

    /**
     * Checks if a variable is an instance of GitRepo
     *
     * Accepts a variable
     *
     * @access  public
     * @param   mixed   variable
     * @return  bool
     */
    public static function isRepo($var)
    {
        return ($var instanceof GitRepo);
    }
}

/* End of file */
