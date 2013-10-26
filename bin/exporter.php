#!/usr/bin/php
<?php
if ('cli' != php_sapi_name()) die();

ini_set('memory_limit','128M');
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../../../').'/');
require_once DOKU_INC.'inc/init.php';
require_once DOKU_INC.'inc/cliopts.php';

// handle options
$short_opts = 'hr';
$long_opts  = array('help', 'run', 'git-dir=', 'work-dir=', 'branch=', 'no-meta', 'quiet');

$OPTS = Doku_Cli_Opts::getOptions(__FILE__, $short_opts, $long_opts);

if ( $OPTS->isError() ) {
    fwrite( STDERR, $OPTS->getMessage() . "\n");
    exit(1);
}

// handle '--help' option
if ( $OPTS->has('h') or $OPTS->has('help') or empty($OPTS->options) ) {
    usage();
    exit(0);
}

$exporter = new git_exporter();

// handle '--git-dir' option
if ( $OPTS->has('git-dir') ) {
    $exporter->git_dir = getSuppliedArgument($OPTS, null, 'git-dir');
}

// handle '--work-dir' option
if ( $OPTS->has('work-dir') ) {
    $exporter->work_dir = getSuppliedArgument($OPTS, null, 'work-dir');
}

// handle '--branch' option
if ( $OPTS->has('branch') ) {
    $exporter->git_branch = getSuppliedArgument($OPTS, null, 'branch');
}

// handle '--no-meta' option
if ( $OPTS->has('no-meta') ) {
    $importer->no_meta = true;
}

// handle '--quiet' option
if ( $OPTS->has('quiet') ) {
    $exporter->quiet = true;
}

// handle '--run' option
if ( $OPTS->has('r') or $OPTS->has('run') ) {
    $exporter->export();
}

function usage() {
    print <<<'EOF'
    Usage: exporter.php [options]

    Exports data from git repo to DokuWiki.

    OPTIONS
        -h, --help     show this help and exit
        -r, --run      run exporter
        --git-dir      defines the git repo path (overwrites $conf['repoPath'])
        --work-dir     defines the base path git ought to export (overwrites $conf['repoWorkDir'])
        --branch       defines the git branch to import (overwrites $conf['gitBranch'])
        --no-meta      do not clear and re-export extra meta files to wiki (other than .changes, .meta, .indexed)
        --quiet        do not output message during processing

EOF;
}

function getSuppliedArgument($OPTS, $short, $long) {
    $arg = $OPTS->get($short);
    if ( is_null($arg) ) {
        $arg = $OPTS->get($long);
    }
    return $arg;
}

class git_exporter {

    function __construct() {
        global $conf;
        $this->temp_dir = $conf['tmpdir'].'/gitbacked/exporter';
        io_mkdir_p($this->temp_dir);
        $this->backup =& plugin_load('helper', 'gitbacked_backup');
        $this->histmeta =& plugin_load('helper', 'gitbacked_histmeta');
    }

    function export() {
        global $conf, $lang;
        print 'start export'."\n";

        // acquire a lock, or exit
        $lockfile = $this->temp_dir.'.lock';
        $lock = fopen($lockfile, 'w+');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            print 'another instance of exporter is running, exit'."\n";
            exit(1);
        }

        // init git repo
        $repo =& plugin_load('helper', 'gitbacked_git');
        $repo->setGitRepo($this->git_dir, $this->work_dir, $this->git_branch);

        // clear original data
        print 'clear old data...'."\n";
        $this->clearDir($conf['datadir'], '\.txt$');
        $this->clearDir($conf['olddir'], '\.txt(?:\.(?:gz|bz2))?$');
        $this->clearDir($this->backup->conf('olddir'), '\.txt(?:\.(?:gz|bz2))?$');
        $this->clearDir($conf['mediadir'], '');
        $this->clearDir($conf['mediaolddir'], '');
        $this->clearDir($this->backup->conf('mediaolddir'), '');
        // clear original meta
        if ($this->no_meta) {
            $this->clearDir($conf['metadir'], '^(?!_).*\.(?:changes|indexed|meta)$');
            $this->clearDir($this->backup->conf('metadir'), '^(?!_).*\.(?:changes|indexed|meta)$');
            $this->clearDir($conf['mediametadir'], '^(?!_).*\.(?:changes|indexed|meta)$');
            $this->clearDir($this->backup->conf('mediametadir'), '^(?!_).*\.(?:changes|indexed|meta)$');
        }
        else {
            $this->clearDir($conf['metadir'], '^(?!_).*$');
            $this->clearDir($this->backup->conf('metadir'), '^(?!_).*$');
            $this->clearDir($conf['mediametadir'], '^(?!_).*$');
            $this->clearDir($this->backup->conf('mediametadir'), '^(?!_).*$');
        }
        @unlink($conf['metadir'].'/_dokuwiki.changes');
        @unlink($conf['metadir'].'/_dokuwiki.changes.trimmed');
        @unlink($conf['metadir'].'/_media.changes');
        @unlink($conf['metadir'].'/_media.changes.trimmed');

        // get history list
        print 'retrieving history list...'."\n";
        $historyfile = $this->temp_dir.'/history.txt';
        $repo->git('log --format=%H | tac >'.escapeshellarg($historyfile));

        // read each entry and export
        print 'exporting data...'."\n";
        $base_workdir = realpath($repo->work_tree);
        $base_workdir_cut = strlen($base_workdir);
        $base_pages = realpath($conf['datadir']);
        $base_pages = substr($base_pages, $base_workdir_cut+1);  // trim '/'
        $base_pages_cut = strlen($base_pages);
        $base_media = realpath($conf['mediadir']);
        $base_media = substr($base_media, $base_workdir_cut+1);  // trim '/'
        $base_media_cut = strlen($base_media);
        $base_meta = realpath($conf['metadir']);
        $base_meta = substr($base_meta, $base_workdir_cut+1);  // trim '/'
        $base_meta_cut = strlen($base_meta);
        $base_mediameta = realpath($conf['mediametadir']);
        $base_mediameta = substr($base_mediameta, $base_workdir_cut+1);  // trim '/'
        $base_mediameta_cut = strlen($base_mediameta);

        $hh = fopen($historyfile, 'rb');
        while (!feof($hh)) {
            $rev = rtrim(fgets($hh), "\r\n");
            if (!$rev) continue;

            // read information from the commit message
            $external_commit = false;
            $log = $repo->git('log -n 1 --pretty=%s%n%n%b '.escapeshellarg($rev));
            list($message, $logline, $info, $commands) = $this->histmeta->unpack($log);
            if (empty($info)) {
                $external_commit = true;
                $info = array("", "");
            }
            list($data_id, $data_type) = $info;
            if (empty($logline)) {
                $external_commit = true;
                $logline = array(
                    'date'  => rtrim($repo->git('log -n 1 --pretty=%at '.escapeshellarg($rev)), "\n"),
                    'ip'    => '127.0.0.1',
                    'type'  => DOKU_CHANGE_TYPE_EDIT,
                    'id'    => $data_id,
                    'user'  => '',
                    'sum'   => str_replace("\n", " ", rtrim($message, "\n")),
                    'extra' => 'gitbacked_export'
                );
                $logline = array_values($logline);
            }
            list($date, $ip, $type, $id, $user, $summary, $extra) = $logline;
            if (empty($commands)) {
                $commands = array();
            }

            // add meta entry for particular $data_id
            if (!$external_commit) {
                switch ($data_type) {
                    case "page":
                        $content = implode("\t", $logline)."\n";
                        $meta_file = (array_search("hide change", $commands) === false) ? metaFN($data_id, '.changes') : $this->backup->metaFN($data_id, '.changes') ;
                        io_mkdir_p(dirname($meta_file));
                        file_put_contents($meta_file, $content, FILE_APPEND);
                        touch($meta_file, $date);
                        $global_meta_file = $conf['metadir'].'/_dokuwiki.changes';
                        file_put_contents($global_meta_file, $content, FILE_APPEND);
                        touch($global_meta_file, $date);
                        break;
                    case "media":
                        $content = implode("\t", $logline)."\n";
                        $meta_file = (array_search("hide change", $commands) === false) ? mediaMetaFN($data_id, '.changes') : $this->backup->mediaMetaFN($data_id, '.changes') ;
                        io_mkdir_p(dirname($meta_file));
                        file_put_contents($meta_file, $content, FILE_APPEND);
                        touch($meta_file, $date);
                        $global_meta_file = $conf['metadir'].'/_media.changes';
                        file_put_contents($global_meta_file, $content, FILE_APPEND);
                        touch($global_meta_file, $date);
                        break;
                    default:
                        break;
                }
            }

            // per file action
            $files = $repo->git('log -n 1 --name-status --pretty=format: '.escapeshellarg($rev));
            $files = explode("\n", $files);
            foreach ($files as $file) {
                list($action, $file) = explode("\t", $file);
                // pages
                if (substr($file, 0, $base_pages_cut+1) == $base_pages.'/') {
                    $file_path_sub = substr($file, $base_pages_cut+1);  // trim '/'
                    $data_id = substr($file_path_sub, 0, -4);  // trim '.txt'
                    if ($action == 'D') {
                        // page
                        $page_file = wikiFN($data_id, null, false);
                        unlink($page_file);
                        // attic -> copy last attic
                        $attic_file = (array_search("hide data", $commands) === false) ? wikiFN($data_id, $date, false) : $this->backup->wikiFN($data_id, $date, false);
                        $last = $this->getLastAttic($data_id);
                        copy($last, $attic_file);
                        touch($attic_file, $date);
                    }
                    else {
                        // page
                        $page_file = wikiFN($data_id, null, false);
                        $content = $repo->git('show '.escapeshellarg($rev).':'.escapeshellarg($file));
                        io_writeWikiPage($page_file, $content, $data_id, $date);
                        touch($page_file, $date);
                        // attic
                        $attic_file = (array_search("hide data", $commands) === false) ? wikiFN($data_id, $date, false) : $this->backup->wikiFN($data_id, $date, false);
                        io_writeWikiPage($attic_file, $content, $data_id, $date);  // content same as above
                        touch($attic_file, $date);
                    }
                    // if external commit, add meta entry for all edited pages
                    if ($external_commit) {
                        $logline[3] = $data_id;  // replace $data_id
                        $content = implode("\t", $logline)."\n";
                        $meta_file = (array_search("hide change", $commands) === false) ? metaFN($data_id, '.changes') : $this->backup->metaFN($data_id, '.changes') ;
                        io_mkdir_p(dirname($meta_file));
                        file_put_contents($meta_file, $content, FILE_APPEND);
                        touch($meta_file, $date);
                        $global_meta_file = $conf['metadir'].'/_dokuwiki.changes';
                        file_put_contents($global_meta_file, $content, FILE_APPEND);
                        touch($global_meta_file, $date);
                    }
                }
                // media
                elseif (substr($file, 0, $base_media_cut+1) == $base_media.'/') {
                    $file_path_sub = substr($file, $base_pages_cut+1);  // trim '/'
                    $data_id = $file_path_sub;
                    if ($action == 'D') {
                        // media
                        $media_file = mediaFN($data_id);
                        unlink($media_file);
                        // attic -> none for deleted media
                    }
                    else {
                        // media
                        $media_file = mediaFN($data_id);
                        $content = $repo->git('show '.escapeshellarg($rev).':'.escapeshellarg($file));
                        io_mkdir_p(dirname($media_file));
                        file_put_contents($media_file, $content);
                        touch($media_file, $date);
                        // attic
                        $attic_file = (array_search("hide data", $commands) === false) ? mediaFN($data_id, $date) : $this->backup->mediaFN($data_id, $date);
                        io_mkdir_p(dirname($attic_file));
                        file_put_contents($attic_file, $content);  // content same as above
                        touch($attic_file, $date);
                    }
                    // if external commit, add meta entry for all edited media
                    if ($external_commit) {
                        $logline[3] = $data_id;  // replace $data_id
                        $content = implode("\t", $logline)."\n";
                        $meta_file = (array_search("hide change", $commands) === false) ? mediaMetaFN($data_id, '.changes') : $this->backup->mediaMetaFN($data_id, '.changes');
                        io_mkdir_p(dirname($meta_file));
                        file_put_contents($meta_file, $content, FILE_APPEND);
                        touch($meta_file, $date);
                        $global_meta_file = $conf['metadir'].'/_media.changes';
                        file_put_contents($global_meta_file, $content, FILE_APPEND);
                        touch($global_meta_file, $date);
                    }
                }
                // meta
                elseif (substr($file, 0, $base_meta_cut+1) == $base_meta.'/') {
                    if (!$this->no_meta) {
                        $file_path_sub = substr($file, $base_meta_cut+1);  // trim '/'
                        $data_id = $file_path_sub;
                        $meta_file = metaFN($data_id, '');
                        io_mkdir_p(dirname($meta_file));
                        $content = $repo->git('show '.escapeshellarg($rev).':'.escapeshellarg($file));
                        file_put_contents($meta_file, $content);
                        touch($meta_file, $date);
                    }
                }
                // media meta
                elseif (substr($file, 0, $base_mediameta_cut+1) == $base_mediameta.'/') {
                    if (!$this->no_meta) {
                        $file_path_sub = substr($file, $base_mediameta_cut+1);  // trim '/'
                        $data_id = $file_path_sub;
                        $mediameta_file = mediaMetaFN($data_id, '');
                        io_mkdir_p(dirname($mediameta_file));
                        $content = $repo->git('show '.escapeshellarg($rev).':'.escapeshellarg($file));
                        file_put_contents($mediameta_file, $content);
                        touch($mediameta_file, $date);
                    }
                }
            }
        }
        fclose($hh);

        // unlock, clean, and done
        print 'clean up...'."\n";
        passthru(sprintf(
            'rm -rf %s',
            escapeshellarg($this->temp_dir)
        ));
        print 'done.'."\n";
        flock($lock, LOCK_UN);
        @unlink($lockfile);
    }

    // this script is not a true plugin, fake this method for convenience
    private function getConf($setting, $notset=false) {
        $my =& plugin_load('helper', 'gitbacked_git');
        return $my->getConf($setting, $notset);
    }

    private function clearDir($dir, $regex, $removeThis=false) {
        $dh = @opendir($dir);
        if($dh) {
            while(($file = readdir($dh)) !== false){
                if ($file=='.'||$file=='..') continue;
                $subfile = $dir.'/'.$file;
                if (is_file($subfile)) {
                    if (preg_match("/$regex/", $file)) {
                        print "remove `$subfile'"."\n";
                        unlink($subfile);
                    }
                }
                else {
                    $this->clearDir($subfile, $regex, true);
                }
            }
            closedir($dh);
            if ($removeThis) @rmdir($dir);
            return true;
        }
        return false;
    }

    private function getLastAttic($data_id) {
        $dir = dirname( wikiFN($data_id, '1', false) );
        $dh = @opendir($dir);
        if($dh) {
            while(($file = readdir($dh)) !== false){
                if ($file{0}=='.') continue;
                $subfile = $dir.'/'.$file;
                if (is_file($subfile)) {
                    $date = intval(filemtime($subfile));
                    if ($date > $lastdate) {
                        $lastdate = $date;
                        $lastfile = $subfile;
                    }
                }
            }
            closedir($dh);
        }
        $dir = dirname( $this->backup->wikiFN($data_id, '1', false) );
        $dh = @opendir($dir);
        if($dh) {
            while(($file = readdir($dh)) !== false){
                if ($file{0}=='.') continue;
                $subfile = $dir.'/'.$file;
                if (is_file($subfile)) {
                    $date = intval(filemtime($subfile));
                    if ($date > $lastdate) {
                        $lastdate = $date;
                        $lastfile = $subfile;
                    }
                }
            }
            closedir($dh);
        }
        return $lastfile;
    }

    private function getLastMediaAttic($data_id) {
        $dir = dirname( mediaFN($data_id, '1') );
        $dh = @opendir($dir);
        if($dh) {
            while(($file = readdir($dh)) !== false){
                if ($file{0}=='.') continue;
                $subfile = $dir.'/'.$file;
                if (is_file($subfile)) {
                    $date = intval(filemtime($subfile));
                    if ($date > $lastdate) {
                        $lastdate = $date;
                        $lastfile = $subfile;
                    }
                }
            }
            closedir($dh);
        }
        $dir = dirname( mediaFN($data_id, '1') );
        $dh = @opendir($dir);
        if($dh) {
            while(($file = readdir($dh)) !== false){
                if ($file{0}=='.') continue;
                $subfile = $dir.'/'.$file;
                if (is_file($subfile)) {
                    $date = intval(filemtime($subfile));
                    if ($date > $lastdate) {
                        $lastdate = $date;
                        $lastfile = $subfile;
                    }
                }
            }
            closedir($dh);
        }
        return $lastfile;
    }
}
