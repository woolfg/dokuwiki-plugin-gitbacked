<?php

namespace woolfg\dokuwiki\plugin\gitbacked;

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
if (__FILE__ == $_SERVER['SCRIPT_FILENAME']) die('Bad load order');

/**
 * Git Repository Interface Class
 *
 * This class enables the creating, reading, and manipulation
 * of a git repository
 *
 * @class  GitRepo
 */
class GitRepo
{
    // This regex will filter a probable password from any string containing a Git URL.
    // Limitation: it will work for the first git URL occurrence in a string.
    // Used https://regex101.com/ for evaluating!
    public const REGEX_GIT_URL_FILTER_PWD = "/^(.*)((http:)|(https:))([^:]+)(:[^@]*)?(.*)/im";
    public const REGEX_GIT_URL_FILTER_PWD_REPLACE_PATTERN = "$1$2$5$7";

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
    public static function &createNew(
        $repo_path,
        \action_plugin_gitbacked_editcommit $plugin = null,
        $source = null,
        $remote_source = false,
        $reference = null
    ) {
        if (is_dir($repo_path) && file_exists($repo_path . "/.git") && is_dir($repo_path . "/.git")) {
            throw new \Exception(self::handleCreateNewError(
                $repo_path,
                $reference,
                '"' . $repo_path . '" is already a git repository',
                $plugin
            ));
        } else {
            $repo = new self($repo_path, $plugin, true, false);
            if (is_string($source)) {
                if ($remote_source) {
                    if (!is_dir($reference) || !is_dir($reference . '/.git')) {
                        throw new \Exception(self::handleCreateNewError(
                            $repo_path,
                            $reference,
                            '"' . $reference . '" is not a git repository. Cannot use as reference.',
                            $plugin
                        ));
                    } elseif (strlen($reference)) {
                        $reference = realpath($reference);
                        $reference = "--reference $reference";
                    }
                    $repo->cloneRemote($source, $reference);
                } else {
                    $repo->cloneFrom($source);
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
    public function __construct(
        $repo_path = null,
        \action_plugin_gitbacked_editcommit $plugin = null,
        $create_new = false,
        $_init = true
    ) {
        $this->plugin = $plugin;
        if (is_string($repo_path)) {
            $this->setRepoPath($repo_path, $create_new, $_init);
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
    public function setRepoPath($repo_path, $create_new = false, $_init = true)
    {
        if (is_string($repo_path)) {
            if ($new_path = realpath($repo_path)) {
                $repo_path = $new_path;
                if (is_dir($repo_path)) {
                    // Is this a work tree?
                    if (file_exists($repo_path . "/.git") && is_dir($repo_path . "/.git")) {
                        $this->repo_path = $repo_path;
                        $this->bare = false;
                        // Is this a bare repo?
                    } elseif (is_file($repo_path . "/config")) {
                        $parse_ini = parse_ini_file($repo_path . "/config");
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
                        } else {
                            throw new \Exception($this->handleRepoPathError(
                                $repo_path,
                                '"' . $repo_path . '" is not a git repository'
                            ));
                        }
                    }
                } else {
                    throw new \Exception($this->handleRepoPathError(
                        $repo_path,
                        '"' . $repo_path . '" is not a directory'
                    ));
                }
            } else {
                if ($create_new) {
                    if ($parent = realpath(dirname($repo_path))) {
                        mkdir($repo_path);
                        $this->repo_path = $repo_path;
                        if ($_init) $this->run('init');
                    } else {
                        throw new \Exception($this->handleRepoPathError(
                            $repo_path,
                            'cannot create repository in non-existent directory'
                        ));
                    }
                } else {
                    throw new \Exception($this->handleRepoPathError(
                        $repo_path,
                        '"' . $repo_path . '" does not exist'
                    ));
                }
            }
        }
    }

    /**
     * Get the path to the git repo directory (eg. the ".git" directory)
     *
     * @access public
     * @return string
     */
    public function gitDirectoryPath()
    {
        return ($this->bare) ? $this->repo_path : $this->repo_path . "/.git";
    }

    /**
     * Tests if git is installed
     *
     * @access  public
     * @return  bool
     */
    public function testGit()
    {
        $descriptorspec = array(
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $pipes = array();
        $resource = proc_open(Git::getBin(), $descriptorspec, $pipes);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $status = trim(proc_close($resource));
        return ($status != 127);
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
    protected function runCommand($command)
    {
        //dbglog("Git->runCommand(command=[".$command."])");
        $descriptorspec = array(
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $pipes = array();
        $cwd = $this->repo_path;
        //dbglog("GitBacked - cwd: [".$cwd."]");
        /* Provide any $this->envopts via putenv
         * and call proc_open with env=null to inherit the rest
         * of env variables from the original process of the system.
         * Note: Variables set by putenv live for a
         * single PHP request run only. These variables
         * are visible "locally". They are NOT listed by getenv(),
         * but they are visible to the process forked by proc_open().
         */
        foreach ($this->envopts as $k => $v) {
            putenv(sprintf("%s=%s", $k, $v));
        }
        $resource = proc_open($command, $descriptorspec, $pipes, $cwd, null);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $status = trim(proc_close($resource));
        //dbglog("GitBacked: runCommand status: ".$status);
        if ($status) {
            //dbglog("GitBacked - stderr: [".$stderr."]");
            // Remove a probable password from the Git URL, if the URL is contained in the error message
            $error_message = preg_replace(
                $this::REGEX_GIT_URL_FILTER_PWD,
                $this::REGEX_GIT_URL_FILTER_PWD_REPLACE_PATTERN,
                $stderr
            );
            //dbglog("GitBacked - error_message: [".$error_message."]");
            throw new \Exception($this->handleCommandError(
                $this->repo_path,
                $cwd,
                $command,
                $status,
                $error_message
            ));
        } else {
            $this->handleCommandSuccess($this->repo_path, $cwd, $command);
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
    public function run($command)
    {
        return $this->runCommand(Git::getBin() . " " . $command);
    }

    /**
     * Handles error on create_new
     *
     * @access  protected
     * @param   string  repository path
     * @param   string  error message
     * @return  string  error message
     */
    protected static function handleCreateNewError($repo_path, $reference, $error_message, $plugin)
    {
        if ($plugin instanceof \action_plugin_gitbacked_editcommit) {
            $plugin->notifyCreateNewError($repo_path, $reference, $error_message);
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
    protected function handleRepoPathError($repo_path, $error_message)
    {
        if ($this->plugin instanceof \action_plugin_gitbacked_editcommit) {
            $this->plugin->notifyRepoPathError($repo_path, $error_message);
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
    protected function handleCommandError($repo_path, $cwd, $command, $status, $error_message)
    {
        if ($this->plugin instanceof \action_plugin_gitbacked_editcommit) {
            $this->plugin->notifyCommandError($repo_path, $cwd, $command, $status, $error_message);
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
    protected function handleCommandSuccess($repo_path, $cwd, $command)
    {
        if ($this->plugin instanceof \action_plugin_gitbacked_editcommit) {
            $this->plugin->notifyCommandSuccess($repo_path, $cwd, $command);
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
    public function status($html = false)
    {
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
    public function add($files = "*")
    {
        if (is_array($files)) {
            $files = '"' . implode('" "', $files) . '"';
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
    public function rm($files = "*", $cached = false)
    {
        if (is_array($files)) {
            $files = '"' . implode('" "', $files) . '"';
        }
        return $this->run("rm " . ($cached ? '--cached ' : '') . $files);
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
    public function commit($message = "", $commit_all = true)
    {
        $flags = $commit_all ? '-av' : '-v';
        $msgfile = GitBackedUtil::createMessageFile($message);
        try {
            return $this->run("commit --allow-empty " . $flags . " --file=" . $msgfile);
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
    public function cloneTo($target)
    {
        return $this->run("clone --local " . $this->repo_path . " $target");
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
    public function cloneFrom($source)
    {
        return $this->run("clone --local $source " . $this->repo_path);
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
    public function cloneRemote($source, $reference)
    {
        return $this->run("clone $reference $source " . $this->repo_path);
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
    public function clean($dirs = false, $force = false)
    {
        return $this->run("clean" . (($force) ? " -f" : "") . (($dirs) ? " -d" : ""));
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
    public function createBranch($branch)
    {
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
    public function deleteBranch($branch, $force = false)
    {
        return $this->run("branch " . (($force) ? '-D' : '-d') . " $branch");
    }

    /**
     * Runs a `git branch` call
     *
     * @access  public
     * @param   bool    keep asterisk mark on active branch
     * @return  array
     */
    public function listBranches($keep_asterisk = false)
    {
        $branchArray = explode("\n", $this->run("branch"));
        foreach ($branchArray as $i => &$branch) {
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
    public function listRemoteBranches()
    {
        $branchArray = explode("\n", $this->run("branch -r"));
        foreach ($branchArray as $i => &$branch) {
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
    public function activeBranch($keep_asterisk = false)
    {
        $branchArray = $this->listBranches(true);
        $activeBranch = preg_grep("/^\*/", $branchArray);
        reset($activeBranch);
        if ($keep_asterisk) {
            return current($activeBranch);
        } else {
            return str_replace("* ", "", current($activeBranch));
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
    public function checkout($branch)
    {
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
    public function merge($branch)
    {
        return $this->run("merge $branch --no-ff");
    }


    /**
     * Runs a git fetch on the current branch
     *
     * @access  public
     * @return  string
     */
    public function fetch()
    {
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
    public function addTag($tag, $message = null)
    {
        if ($message === null) {
            $message = $tag;
        }
        $msgfile = GitBackedUtil::createMessageFile($message);
        try {
            return $this->run("tag -a $tag --file=" . $msgfile);
        } finally {
            unlink($msgfile);
        }
    }

    /**
     * List all the available repository tags.
     *
     * Optionally, accept a shell wildcard pattern and return only tags matching it.
     *
     * @access  public
     * @param   string $pattern Shell wildcard pattern to match tags against.
     * @return  array           Available repository tags.
     */
    public function listTags($pattern = null)
    {
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
    public function push($remote, $branch)
    {
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
    public function pull($remote, $branch)
    {
        return $this->run("pull $remote $branch");
    }

    /**
     * List log entries.
     *
     * @param strgin $format
     * @return string
     */
    public function log($format = null)
    {
        if ($format === null) {
            return $this->run('log');
        } else {
            return $this->run('log --pretty=format:"' . $format . '"');
        }
    }

    /**
     * Sets the project description.
     *
     * @param string $new
     */
    public function setDescription($new)
    {
        $path = $this->gitDirectoryPath();
        file_put_contents($path . "/description", $new);
    }

    /**
     * Gets the project description.
     *
     * @return string
     */
    public function getDescription()
    {
        $path = $this->gitDirectoryPath();
        return file_get_contents($path . "/description");
    }

    /**
     * Sets custom environment options for calling Git
     *
     * @param string key
     * @param string value
     */
    public function setenv($key, $value)
    {
        $this->envopts[$key] = $value;
    }
}

/* End of file */
