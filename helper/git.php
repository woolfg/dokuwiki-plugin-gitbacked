<?php
// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class helper_plugin_gitbacked_git extends DokuWiki_Plugin {

    function __construct() {
        $this->setGitPath($this->getConf('gitPath'));
    }

    /**
     * Redefines the git bin path
     */
    function setGitPath($git_path) {
        $this->git_path = $git_path;
    }

    /**
     * Set the repository path for other git-related commands
     *
     * @param  string   git repository path, use conf by default (relative to dokuwiki dir)
     * @param  string   git working tree, use conf by default (relative to dokuwiki dir)
     * @param  string   switch to the git branch if given
     * @param  bool     if true, create a git repo if not exist
     * @param  string   if true, create an .htaccess for the git repo dir if not exist
     */
    function setGitRepo($repo_path=null, $work_tree=null, $branch=null, $create_new=true, $protect=true) {
        // set repo path
        $this->repo_path = !empty($repo_path) ? $repo_path : DOKU_INC.$this->getConf('repoPath');
        if (!is_dir($this->repo_path.'/.git')) {
            if ($create_new) {
                $this->cmd(escapeshellarg($this->git_path).' init '.escapeshellarg($this->repo_path));
            }
            else {
                throw new Exception('"'.$this->repo_path.'" is not a git repository');
            }
        }
        $this->repo_path = realpath($this->repo_path);

        // protect the repo from http access
        // only create if it doesn't exist (modification afterwords is allowed)
        if ($protect) {
            $htaccess = $this->repo_path.'/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "order allow,deny\ndeny from all\n");
            }
        }

        // set work tree
        $this->work_tree = !empty($work_tree) ? $work_tree : DOKU_INC.$this->getConf('repoWorkDir');
        io_mkdir_p($this->work_tree);
        $this->work_tree = realpath($this->work_tree);

        // set git_bin
        $this->git_bin = escapeshellarg($this->git_path).
            ' --git-dir '.escapeshellarg($this->repo_path.'/.git').
            ' --work-tree '.escapeshellarg($this->work_tree);

        // switch branch
        if (!$branch) $branch = $this->getConf('gitBranch');
        if ($branch) {
            $list = $this->list_branches(true);
            if (array_search("* $branch", $list) === false) {
                // not exist --> make an orphan
                if (array_search($branch, $list) === false) {
                    $this->cmd_git('checkout --force --orphan '.escapeshellarg($branch));
                }
                // exist, not active --> checkout
                else {
                    $this->cmd_git('checkout --force '.escapeshellarg($branch));
                }
            }
        }
    }

    function cmd($command) {

        $descriptorspec = array(
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $pipes = array();
        $resource = proc_open($command, $descriptorspec, $pipes);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $status = trim(proc_close($resource));
        if ($status) throw new Exception($stderr);

        return $stdout;
    }

    function cmd_git($command) {
        $command = $this->git_bin." ".$command;
        print $command."\n";  // for debug
        return $this->cmd($command);
    }

    function git($command, $params=array()) {
        foreach ($params as $opt => $value) {
            $command .= (strlen($opt) == 1) ? ' -'.$opt : ' --'.$opt;
            if (!is_null($value)) $command .= ' '.escapeshellarg($value);
        }
        return $this->cmd_git($command);
    }

    function list_branches($keep_asterisk = false) {
        $branchArray = explode("\n", $this->git("branch"));
        foreach($branchArray as $i => &$branch) {
            $branch = trim($branch);
            if (! $keep_asterisk)
                $branch = str_replace("* ", "", $branch);
            if ($branch == "")
                unset($branchArray[$i]);
        }
        return $branchArray;
    }
}