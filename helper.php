<?php
// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class helper_plugin_gitbacked extends DokuWiki_Plugin {

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
     */
    function setGitRepo($repo_path=null, $work_tree=null, $create_new = true) {
        // set repo path
        $this->repo_path = !empty($repo_path) ? $repo_path : DOKU_INC.$this->getConf('repoPath');
        if (!is_dir($this->repo_path.'/.git')) {
            if ($create_new) {
                $this->run_command(escapeshellarg($this->git_path).' init '.escapeshellarg($this->repo_path));
            }
            else {
                throw new Exception('"'.$this->repo_path.'" is not a git repository');
            }
        }
        $this->repo_path = realpath($this->repo_path);

        // set work tree
        $this->work_tree = !empty($work_tree) ? $work_tree : DOKU_INC.$this->getConf('repoWorkDir');
        io_mkdir_p($this->work_tree);
        $this->work_tree = realpath($this->work_tree);

        // set git_bin
        $this->git_bin = escapeshellarg($this->git_path).
            ' --git-dir '.escapeshellarg($this->repo_path.'/.git').
            ' --work-tree '.escapeshellarg($this->work_tree);
    }

    function run_command($command) {

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

    function run_git_command($command) {
        $command = $this->git_bin." ".$command;
        print $command."\n";  // for debug
        return $this->run_command($command);
    }

    function git($command, $params=array()) {
        foreach ($params as $opt => $value) {
            $command .= (strlen($opt) == 1) ? ' -'.$opt : ' --'.$opt;
            if (!is_null($value)) $command .= ' '.escapeshellarg($value);
        }
        return $this->run_git_command($command);
    }
}
