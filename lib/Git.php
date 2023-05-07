<?php

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
class Git {

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
	public static function set_bin($path) {
		self::$bin = $path;
	}

	/**
	 * Gets git executable path
	 */
	public static function get_bin() {
		return self::$bin;
	}

	/**
	 * Sets up library for use in a default Windows environment
	 */
	public static function windows_mode() {
		self::set_bin('git');
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
	public static function &create($repo_path, $source = null, \action_plugin_gitbacked_editcommit $plugin = null) {
		return GitRepo::create_new($repo_path, $source, $plugin);
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
	public static function open($repo_path, \action_plugin_gitbacked_editcommit $plugin = null) {
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
	public static function &clone_remote($repo_path, $remote, $reference = null, \action_plugin_gitbacked_editcommit $plugin = null) {
		return GitRepo::create_new($repo_path, $plugin, $remote, true, $reference);
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
	public static function is_repo($var) {
		return ($var instanceof GitRepo);
	}

}

// ------------------------------------------------------------------------

/**
 * Git Repository Interface Class
 *
 * This class enables the creating, reading, and manipulation
 * of a git repository
 *
 * @class  GitRepo
 */
class GitRepo {

	// This regex will filter a probable password from any string containing a Git URL.
	// Limitation: it will work for the first git URL occurrence in a string.
	// Used https://regex101.com/ for evaluating!
	const REGEX_GIT_URL_FILTER_PWD = "/^(.*)((http:)|(https:))([^:]+)(:[^@]*)?(.*)/im";
	const REGEX_GIT_URL_FILTER_PWD_REPLACE_PATTERN = "$1$2$5$7";
	
	protected $repo_path = null;
	protected $bare = false;
	protected $envopts = array();
	// Fix for PHP <=7.3 compatibility: Type declarations for properties work since PHP >= 7.4 only.
	// protected ?\action_plugin_gitbacked_editcommit $plugin = null;
	protected $plugin = null;

	/**
	 * Create a new git repository
	 *
	 * Accepts a creation path, and, optionally, a source path
	 *
	 * @access  public
	 * @param   string  repository path
	 * @param   \action_plugin_gitbacked_editcommit plugin
	 * @param   string  directory to source
	 * @param   string  reference path
	 * @return  GitRepo  or null in case of an error
	 */
	public static function &create_new($repo_path, \action_plugin_gitbacked_editcommit $plugin = null, $source = null, $remote_source = false, $reference = null) {
		if (is_dir($repo_path) && file_exists($repo_path."/.git") && is_dir($repo_path."/.git")) {
			throw new Exception(self::handle_create_new_error($repo_path, $reference, '"'.$repo_path.'" is already a git repository', $plugin));
		} else {
			$repo = new self($repo_path, $plugin, true, false);
			if (is_string($source)) {
				if ($remote_source) {
					if (!is_dir($reference) || !is_dir($reference.'/.git')) {
						throw new Exception(self::handle_create_new_error($repo_path, $reference, '"'.$reference.'" is not a git repository. Cannot use as reference.', $plugin));
					} else if (strlen($reference)) {
						$reference = realpath($reference);
						$reference = "--reference $reference";
					}
					$repo->clone_remote($source, $reference);
				} else {
					$repo->clone_from($source);
				}
			} else {
				$repo->run('init');
			}
			return $repo;
		}
	}

	/**
	 * Constructor
	 *
	 * Accepts a repository path
	 *
	 * @access  public
	 * @param   string  repository path
	 * @param   \action_plugin_gitbacked_editcommit plugin
	 * @param   bool    create if not exists?
	 * @return  void
	 */
	public function __construct($repo_path = null, \action_plugin_gitbacked_editcommit $plugin = null, $create_new = false, $_init = true) {
		$this->plugin = $plugin;
		if (is_string($repo_path)) {
			$this->set_repo_path($repo_path, $create_new, $_init);
		}
	}

	/**
	 * Set the repository's path
	 *
	 * Accepts the repository path
	 *
	 * @access  public
	 * @param   string  repository path
	 * @param   bool    create if not exists?
	 * @param   bool    initialize new Git repo if not exists?
	 * @return  void
	 */
	public function set_repo_path($repo_path, $create_new = false, $_init = true) {
		if (is_string($repo_path)) {
			if ($new_path = realpath($repo_path)) {
				$repo_path = $new_path;
				if (is_dir($repo_path)) {
					$next_parent_repo_path = $this->absolute_git_dir($repo_path);
					if (!empty($next_parent_repo_path)) {
						$this->repo_path = $next_parent_repo_path;
						$this->bare = false;
					// Is this a work tree?
					} else if (file_exists($repo_path."/.git") && is_dir($repo_path."/.git")) {
						$this->repo_path = $repo_path;
						$this->bare = false;
					// Is this a bare repo?
					} else if (is_file($repo_path."/config")) {
					  $parse_ini = parse_ini_file($repo_path."/config");
						if ($parse_ini['bare']) {
							$this->repo_path = $repo_path;
							$this->bare = true;
						}
					} else {
						if ($create_new) {
							$this->repo_path = $repo_path;
							if ($_init) {
								$this->run('init');
							}
						} if (!$_init) {
							// If we do not have to init the repo, we just reflect that there is no repo path yet.
							// This may be the case for auto determining repos, if there is no repo related to the current resource going to be commited.
							$this->repo_path = '';
						} else {
							throw new Exception($this->handle_repo_path_error($repo_path, '"'.$repo_path.'" is not a git repository'));
						}
					}
				} else {
					throw new Exception($this->handle_repo_path_error($repo_path, '"'.$repo_path.'" is not a directory'));
				}
			} else {
				if ($create_new) {
					if ($parent = realpath(dirname($repo_path))) {
						mkdir($repo_path);
						$this->repo_path = $repo_path;
						if ($_init) $this->run('init');
					} else {
						throw new Exception($this->handle_repo_path_error($repo_path, 'cannot create repository in non-existent directory'));
					}
				} else {
					throw new Exception($this->handle_repo_path_error($repo_path, '"'.$repo_path.'" does not exist'));
				}
			}
		}
	}

	/**
	 * Get the path to the repo directory
	 * 
	 * @access public
	 * @return string
	 */
	public function get_repo_path() {
		return $this->repo_path;
	}

	/**
	 * Get the path to the git repo directory (eg. the ".git" directory)
	 * 
	 * @access public
	 * @return string
	 */
	public function git_directory_path() {
		return ($this->bare) ? $this->repo_path : $this->repo_path."/.git";
	}

	/**
	 * Tests if git is installed
	 *
	 * @access  public
	 * @return  bool
	 */
	public function test_git() {
		$descriptorspec = array(
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = array();
		$resource = proc_open(Git::get_bin(), $descriptorspec, $pipes);

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		foreach ($pipes as $pipe) {
			fclose($pipe);
		}

		$status = trim(proc_close($resource));
		return ($status != 127);
	}

	/**
	 * Determine closest parent git repository for a given path as absolute PHP realpath().
	 *
	 * @access  public
	 * @return  string  the next parent git repo root dir as absolute PHP realpath() or empty string, if no parent repo found
	 */
	public function absolute_git_dir($path) {
		$descriptorspec = array(
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = array();
		// Using --git-dir rather than --absolute-git-dir for a wider git versions compatibility
		//$command = Git::get_bin()." rev-parse --absolute-git-dir";
		$command = Git::get_bin()." rev-parse --git-dir";
		//dbglog("GitBacked - Command: ".$command);
		$resource = proc_open($command, $descriptorspec, $pipes, $path);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
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
					$repo_git_dir = $path.'/'.$repo_git_dir;
				}
				$repo_path = dirname(realpath($repo_git_dir));
				//dbglog('GitBacked - $repo_path: '.$repo_path);
				if (file_exists($repo_path."/.git") && is_dir($repo_path."/.git")) {
					return $repo_path;
				}
			}
		}
		return '';
	}

	/**
	 * Run a command in the git repository
	 *
	 * Accepts a shell command to run
	 *
	 * @access  protected
	 * @param   string  command to run
	 * @return  string  or null in case of an error
	 */
	protected function run_command($command) {
		//dbglog("Git->run_command: repo_path=[".$this->repo_path."])");
		if (empty($this->repo_path)) {
			throw new Exception($this->handle_repo_path_error($this->repo_path, "Failure on GitRepo->run_command(): Git command must not be run for an empty repo path"));
		}
		//dbglog("Git->run_command(command=[".$command."])");
		$descriptorspec = array(
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = array();
		/* Depending on the value of variables_order, $_ENV may be empty.
		 * In that case, we have to explicitly set the new variables with
		 * putenv, and call proc_open with env=null to inherit the reset
		 * of the system.
		 *
		 * This is kind of crappy because we cannot easily restore just those
		 * variables afterwards.
		 *
		 * If $_ENV is not empty, then we can just copy it and be done with it.
		 */
		if(count($_ENV) === 0) {
			$env = NULL;
			foreach($this->envopts as $k => $v) {
				putenv(sprintf("%s=%s",$k,$v));
			}
		} else {
			$env = array_merge($_ENV, $this->envopts);
		}
		$resource = proc_open($command, $descriptorspec, $pipes, $this->repo_path, $env);

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		foreach ($pipes as $pipe) {
			fclose($pipe);
		}

		$status = trim(proc_close($resource));
		//dbglog("GitBacked: run_command status: ".$status);
		if ($status) {
			//dbglog("GitBacked - stderr: [".$stderr."]");
			// Remove a probable password from the Git URL, if the URL is contained in the error message
			$error_message = preg_replace($this::REGEX_GIT_URL_FILTER_PWD, $this::REGEX_GIT_URL_FILTER_PWD_REPLACE_PATTERN, $stderr);
			//dbglog("GitBacked - error_message: [".$error_message."]");
			throw new Exception($this->handle_command_error($this->repo_path, $cwd, $command, $status, $error_message));
		} else {
			$this->handle_command_success($this->repo_path, $cwd, $command);
		}

		return $stdout;
	}

	/**
	 * Run a git command in the git repository
	 *
	 * Accepts a git command to run
	 *
	 * @access  public
	 * @param   string  command to run
	 * @return  string
	 */
	public function run($command) {
		return $this->run_command(Git::get_bin()." ".$command);
	}

	/**
	 * Handles error on create_new
	 *
	 * @access  protected
	 * @param   string  repository path
	 * @param   string  error message
	 * @return  string  error message
	 */
	protected static function handle_create_new_error($repo_path, $reference, $error_message, $plugin) {
		if ($plugin instanceof \action_plugin_gitbacked_editcommit) {
			$plugin->notify_create_new_error($repo_path, $reference, $error_message);
		}
		return $error_message;
	}

	/**
	 * Handles error on setting the repo path
	 *
	 * @access  protected
	 * @param   string  repository path
	 * @param   string  error message
	 * @return  string  error message
	 */
	protected function handle_repo_path_error($repo_path, $error_message) {
		if ($this->plugin instanceof \action_plugin_gitbacked_editcommit) {
			$this->plugin->notify_repo_path_error($repo_path, $error_message);
		}
		return $error_message;
	}

	/**
	 * Handles error on git command
	 *
	 * @access  protected
	 * @param   string  repository path
	 * @param   string  current working dir
	 * @param   string  command line
	 * @param   int     exit code of command (status)
	 * @param   string  error message
	 * @return  string  error message
	 */
	protected function handle_command_error($repo_path, $cwd, $command, $status, $error_message) {
		if ($this->plugin instanceof \action_plugin_gitbacked_editcommit) {
			$this->plugin->notify_command_error($repo_path, $cwd, $command, $status, $error_message);
		}
		return $error_message;
	}

	/**
	 * Handles success on git command
	 *
	 * @access  protected
	 * @param   string  repository path
	 * @param   string  current working dir
	 * @param   string  command line
	 * @return  void
	 */
	protected function handle_command_success($repo_path, $cwd, $command) {
		if ($this->plugin instanceof \action_plugin_gitbacked_editcommit) {
			$this->plugin->notify_command_success($repo_path, $cwd, $command);
		}
	}

	/**
	 * Runs a 'git status' call
	 *
	 * Accept a convert to HTML bool
	 *
	 * @access public
	 * @param bool  return string with <br />
	 * @return string
	 */
	public function status($html = false) {
		$msg = $this->run("status");
		if ($html == true) {
			$msg = str_replace("\n", "<br />", $msg);
		}
		return $msg;
	}

	/**
	 * Runs a `git add` call
	 *
	 * Accepts a list of files to add
	 *
	 * @access  public
	 * @param   mixed   files to add
	 * @return  string
	 */
	public function add($files = "*") {
		if (is_array($files)) {
			$files = '"'.implode('" "', $files).'"';
		}
		return $this->run("add $files -v");
	}

	/**
	 * Runs a `git rm` call
	 *
	 * Accepts a list of files to remove
	 *
	 * @access  public
	 * @param   mixed    files to remove
	 * @param   Boolean  use the --cached flag?
	 * @return  string
	 */
	public function rm($files = "*", $cached = false) {
		if (is_array($files)) {
			$files = '"'.implode('" "', $files).'"';
		}
		return $this->run("rm ".($cached ? '--cached ' : '').$files);
	}


	/**
	 * Runs a `git commit` call
	 *
	 * Accepts a commit message string
	 *
	 * @access  public
	 * @param   string  commit message
	 * @param   boolean  should all files be committed automatically (-a flag)
	 * @return  string
	 */
	public function commit($message = "", $commit_all = true) {
		$flags = $commit_all ? '-av' : '-v';
		$msgfile = GitBackedUtil::createMessageFile($message);
		try {
		  return $this->run("commit --allow-empty ".$flags." --file=".$msgfile);
		} finally {
		  unlink($msgfile);
		}
	}

	/**
	 * Runs a `git clone` call to clone the current repository
	 * into a different directory
	 *
	 * Accepts a target directory
	 *
	 * @access  public
	 * @param   string  target directory
	 * @return  string
	 */
	public function clone_to($target) {
		return $this->run("clone --local ".$this->repo_path." $target");
	}

	/**
	 * Runs a `git clone` call to clone a different repository
	 * into the current repository
	 *
	 * Accepts a source directory
	 *
	 * @access  public
	 * @param   string  source directory
	 * @return  string
	 */
	public function clone_from($source) {
		return $this->run("clone --local $source ".$this->repo_path);
	}

	/**
	 * Runs a `git clone` call to clone a remote repository
	 * into the current repository
	 *
	 * Accepts a source url
	 *
	 * @access  public
	 * @param   string  source url
	 * @param   string  reference path
	 * @return  string
	 */
	public function clone_remote($source, $reference) {
		return $this->run("clone $reference $source ".$this->repo_path);
	}

	/**
	 * Runs a `git clean` call
	 *
	 * Accepts a remove directories flag
	 *
	 * @access  public
	 * @param   bool    delete directories?
	 * @param   bool    force clean?
	 * @return  string
	 */
	public function clean($dirs = false, $force = false) {
		return $this->run("clean".(($force) ? " -f" : "").(($dirs) ? " -d" : ""));
	}

	/**
	 * Runs a `git branch` call
	 *
	 * Accepts a name for the branch
	 *
	 * @access  public
	 * @param   string  branch name
	 * @return  string
	 */
	public function create_branch($branch) {
		return $this->run("branch $branch");
	}

	/**
	 * Runs a `git branch -[d|D]` call
	 *
	 * Accepts a name for the branch
	 *
	 * @access  public
	 * @param   string  branch name
	 * @return  string
	 */
	public function delete_branch($branch, $force = false) {
		return $this->run("branch ".(($force) ? '-D' : '-d')." $branch");
	}

	/**
	 * Runs a `git branch` call
	 *
	 * @access  public
	 * @param   bool    keep asterisk mark on active branch
	 * @return  array
	 */
	public function list_branches($keep_asterisk = false) {
		$branchArray = explode("\n", $this->run("branch"));
		foreach($branchArray as $i => &$branch) {
			$branch = trim($branch);
			if (! $keep_asterisk) {
				$branch = str_replace("* ", "", $branch);
			}
			if ($branch == "") {
				unset($branchArray[$i]);
			}
		}
		return $branchArray;
	}

	/**
	 * Lists remote branches (using `git branch -r`).
	 *
	 * Also strips out the HEAD reference (e.g. "origin/HEAD -> origin/master").
	 *
	 * @access  public
	 * @return  array
	 */
	public function list_remote_branches() {
		$branchArray = explode("\n", $this->run("branch -r"));
		foreach($branchArray as $i => &$branch) {
			$branch = trim($branch);
			if ($branch == "" || strpos($branch, 'HEAD -> ') !== false) {
				unset($branchArray[$i]);
			}
		}
		return $branchArray;
	}

	/**
	 * Returns name of active branch
	 *
	 * @access  public
	 * @param   bool    keep asterisk mark on branch name
	 * @return  string
	 */
	public function active_branch($keep_asterisk = false) {
		$branchArray = $this->list_branches(true);
		$active_branch = preg_grep("/^\*/", $branchArray);
		reset($active_branch);
		if ($keep_asterisk) {
			return current($active_branch);
		} else {
			return str_replace("* ", "", current($active_branch));
		}
	}

	/**
	 * Runs a `git checkout` call
	 *
	 * Accepts a name for the branch
	 *
	 * @access  public
	 * @param   string  branch name
	 * @return  string
	 */
	public function checkout($branch) {
		return $this->run("checkout $branch");
	}


	/**
	 * Runs a `git merge` call
	 *
	 * Accepts a name for the branch to be merged
	 *
	 * @access  public
	 * @param   string $branch
	 * @return  string
	 */
	public function merge($branch) {
		return $this->run("merge $branch --no-ff");
	}


	/**
	 * Runs a git fetch on the current branch
	 *
	 * @access  public
	 * @return  string
	 */
	public function fetch() {
		return $this->run("fetch");
	}

	/**
	 * Add a new tag on the current position
	 *
	 * Accepts the name for the tag and the message
	 *
	 * @param string $tag
	 * @param string $message
	 * @return string
	 */
	public function add_tag($tag, $message = null) {
		if ($message === null) {
			$message = $tag;
		}
		$msgfile = GitBackedUtil::createMessageFile($message);
		try {
		  return $this->run("tag -a $tag --file=".$msgfile);
		} finally {
		  unlink($msgfile);
		}
	}

	/**
	 * List all the available repository tags.
	 *
	 * Optionally, accept a shell wildcard pattern and return only tags matching it.
	 *
	 * @access	public
	 * @param	string	$pattern	Shell wildcard pattern to match tags against.
	 * @return	array				Available repository tags.
	 */
	public function list_tags($pattern = null) {
		$tagArray = explode("\n", $this->run("tag -l $pattern"));
		foreach ($tagArray as $i => &$tag) {
			$tag = trim($tag);
			if ($tag == '') {
				unset($tagArray[$i]);
			}
		}

		return $tagArray;
	}

	/**
	 * Push specific branch to a remote
	 *
	 * Accepts the name of the remote and local branch
	 *
	 * @param string $remote
	 * @param string $branch
	 * @return string
	 */
	public function push($remote, $branch) {
		return $this->run("push --tags $remote $branch");
	}

	/**
	 * Pull specific branch from remote
	 *
	 * Accepts the name of the remote and local branch
	 *
	 * @param string $remote
	 * @param string $branch
	 * @return string
	 */
	public function pull($remote, $branch) {
		return $this->run("pull $remote $branch");
	}

	/**
	 * List log entries.
	 *
	 * @param strgin $format
	 * @return string
	 */
	public function log($format = null) {
		if ($format === null)
			return $this->run('log');
		else
			return $this->run('log --pretty=format:"' . $format . '"');
	}

	/**
	 * Sets the project description.
	 *
	 * @param string $new
	 */
	public function set_description($new) {
		$path = $this->git_directory_path();
		file_put_contents($path."/description", $new);
	}

	/**
	 * Gets the project description.
	 *
	 * @return string
	 */
	public function get_description() {
		$path = $this->git_directory_path();
		return file_get_contents($path."/description");
	}

	/**
	 * Sets custom environment options for calling Git
	 *
	 * @param string key
	 * @param string value
	 */
	public function setenv($key, $value) {
		$this->envopts[$key] = $value;
	}

}

/* End of file */
