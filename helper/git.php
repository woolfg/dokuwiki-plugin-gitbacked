<?php
// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class helper_plugin_gitbacked_git extends DokuWiki_Plugin {

    function __construct() {
        global $conf;
        $this->setGitPath($this->getConf('gitPath'));   
        $this->repo_base = $this->getConf('repoBase');
        $this->data_base = realpath(DOKU_INC.$this->getConf('dataBase'));
        $this->temp_dir = $conf['tmpdir'].'/gitbacked';
    }

    /**
     * Returns the git command path
     * can be used to check whether a git repo is defined
     */
    function getGitRepo() {
        return $this->git_bin;
    }

    /**
     * Redefines the git bin path
     */
    function setGitPath($git_path) {
        $this->git_path = $git_path;
    }

    /**
     * Gets the corresponding inner path of a given file or directory
     */
    function getInnerPath($path) {
        $cut = strlen($this->data_base)+1;  // plus trailing '/'
        if (substr($path, 0, $cut) == $this->data_base.'/') {
            $pathShort = substr($path, $cut);
        }
        else {  // if failed to strip data_base, use base name (since last '/') instead
            $pathinfo = pathinfo($path);
            $pathShort = $pathinfo['basename'];
        }
        $innerPath = $this->work_tree.'/'.$this->repo_base.'/'.$pathShort;
        return $innerPath;
    }

    /**
     * Gets the corresponding file id of a given inner path of a file
     *
     * returns false if:
     *  1. innerPath is not under the specified dokuPath
     *  2. innerPath doesn't have the extension $ext
     *  3. repo_base or data_base is mis-configured
     */
    function getRealId($innerPath, $dokuPath, $ext=null) {
        // check whether ext matches
        if (!empty($ext)) {
            $ext_exist = true;
            $ext_cut = strlen($ext);
        }
        if ($ext_exist && !(substr($innerPath, -$ext_cut) == $ext)) return false;
        // get short inner path
        $cut = strlen($this->repo_base)+1;  // plus trailing '/'
        if (substr($innerPath, 0, $cut) == $this->repo_base.'/') {
            $innerPathShort = substr($innerPath, $cut);
        }
        else return false;
        // get short doku path
        $cut = strlen($this->data_base)+1;  // plus trailing '/'
        if (substr($dokuPath, 0, $cut) == $this->data_base.'/') {
            $dokuPathShort = substr($dokuPath, $cut);
        }
        else return false;
        // check whether short inner path has short doku path in leading
        $cut = strlen($dokuPathShort)+1;  // plus trailing '/'
        if (substr($innerPathShort, 0, $cut) == $dokuPathShort.'/') {
            $id = substr($innerPathShort, $cut);
            if ($ext_exist) $id = substr($id, 0, -$ext_cut);
            return $id;
        }
        return false;
    }

    /**
     * Set the repository path for other git-related commands
     *
     * @param  string   git repository path, use conf by default (relative to dokuwiki dir)
     * @param  string   git working tree, use conf by default (relative to dokuwiki dir)
     * @param  string   switch to the git branch if given, use conf by default
     * @param  string   extra git params if given, use conf by default
     * @param  bool     if true, create a git repo if not exist
     * @param  string   if true, create an .htaccess for the git repo dir if not exist
     */
    function setGitRepo($repo_path=null, $work_tree=null, $branch=null, $extra_params=null, $create_new=true, $protect=true) {
        // set repo path
        $this->repo_path = !empty($repo_path) ? $repo_path : DOKU_INC.$this->getConf('repoPath');
        if (is_dir($this->repo_path.'/.git')) {  // if a non-bare repo existed, use it
            $this->repo_path = $this->repo_path.'/.git'; 
        }
        if (!is_dir($this->repo_path)) {
            if ($create_new) {
                $this->cmd(escapeshellarg($this->git_path).' init --bare '.escapeshellarg($this->repo_path));
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
        $this->work_tree = !empty($work_tree) ? $work_tree : $this->repo_path;
        io_mkdir_p($this->work_tree);
        $this->work_tree = realpath($this->work_tree);

        // set extra params
        $this->extra_params = !empty($extra_params) ? $extra_params : $this->getConf('addParams');

        // set git_bin
        $this->git_bin = escapeshellarg($this->git_path).
            ' --git-dir '.escapeshellarg($this->repo_path).
            ' --work-tree '.escapeshellarg($this->work_tree);
        if (!empty($this->extra_params)) $this->git_bin .= ' '.$this->extra_params;

        // switch branch
        if (!$branch) $branch = $this->getConf('gitBranch');
        if ($branch) {
            $list = $this->list_branches(true);
            if (array_search("* $branch", $list) === false) {
                // not exist --> make an orphan
                if (array_search($branch, $list) === false) {
                    $this->git('checkout --force --orphan '.escapeshellarg($branch));
                    $this->git('rm -rf --cached --ignore-unmatch -- .');
                }
                // exist, not active --> checkout
                else {
                    $this->git('checkout --force '.escapeshellarg($branch));
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
        if ($status) {
            print "on system command: $command"."\n";
            throw new Exception($stderr);
        }

        return $stdout;
    }

    function git($command) {
        $command = $this->git_bin." ".$command;
        return $this->cmd($command);
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

    function active_branch($keep_asterisk = false) {
        $branchArray = $this->list_branches(true);
        $active_branch = preg_grep("/^\*/", $branchArray);
        reset($active_branch);
        if ($keep_asterisk)
            return current($active_branch);
        else
            return str_replace("* ", "", current($active_branch));
    }

    function addFile($file=null, $innerPath=null, $removeIfEmpty=true) {
        if (empty($innerPath)) $innerPath = $this->getInnerPath($file);
        if (is_file($file)) {
            if ($file != $innerPath) {
                io_mkdir_p(dirname($innerPath));
                // try acquiring a hard link if available to save the cost
                if (!@link($file, $innerPath)) copy($file, $innerPath);
            }
            $this->git('add -- '.escapeshellarg($innerPath));
        }
        else if ($removeIfEmpty) {
            io_mkdir_p(dirname($innerPath));
            $this->git('rm --ignore-unmatch -- '.escapeshellarg($innerPath));
        }
    }

    function addFileInput($file=null, $innerPath=null, $content) {
        if (empty($innerPath)) $innerPath = $this->getInnerPath($file);
        io_mkdir_p(dirname($innerPath));
        file_put_contents($innerPath, $content);
        $this->git('add -- '.escapeshellarg($innerPath));
    }

    // can also pass a directory
    function removeFile($path=null, $innerPath=null) {
        if (empty($innerPath)) $innerPath = $this->getInnerPath($path);
        io_mkdir_p(dirname($innerPath));
        $this->git('rm -rf --cached --ignore-unmatch -- '.escapeshellarg($innerPath));
    }

    function lock() {
        $this->lockfile = $this->temp_dir.'/commit.lock';
        io_mkdir_p(dirname($this->lockfile));
        $this->lock = fopen($this->lockfile, 'wb');
        if (flock($this->lock, LOCK_EX)) {
            return true;
        }
        throw new Exception();
        return false;
    }

    function unlock() {
        if ($this->lock) {
            if (flock($this->lock, LOCK_UN)) {
                return true;
            }
        }
        return false;
    }

    function clearDir($dir) {
        $dh = @opendir($dir);
        if($dh) {
            while(($file = readdir($dh)) !== false){
                if ($file=='.'||$file=='..') continue;
                $subfile = $dir.'/'.$file;
                if (is_file($subfile)) unlink($subfile);
                else $this->clearDir($subfile);
            }
            closedir($dh);
            rmdir($dir);
            return true;
        }
        return false;
    }
}
