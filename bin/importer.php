#!/usr/bin/php
<?php
if ('cli' != php_sapi_name()) die();

ini_set('memory_limit','128M');
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../../../').'/');
require_once DOKU_INC.'inc/init.php';
require_once DOKU_INC.'inc/cliopts.php';

// handle options
$short_opts = 'hr';
$long_opts  = array('help', 'run', 'git-dir=', 'branch=');

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

$importer = new git_importer();

// handle '--git-dir' option
if ( $OPTS->has('git-dir') ) {
    $importer->git_dir = getSuppliedArgument($OPTS, null, 'git-dir');
}

// handle '--branch' option
if ( $OPTS->has('branch') ) {
    $importer->git_branch = getSuppliedArgument($OPTS, null, 'branch');
}

// handle '--run' option
if ( $OPTS->has('r') or $OPTS->has('run') ) {
    $importer->import();
}

function usage() {
    print <<<'EOF'
    Usage: importer.php [options]

    Imports DokuWiki data into git repo.

    OPTIONS
        -h, --help     show this help and exit
        -r, --run      run importer
        --git-dir      defines the git repo path (overwrites $conf['repoPath'])
        --branch       defines the git branch to import (overwrites $conf['gitBranch'])

EOF;
}

function getSuppliedArgument($OPTS, $short, $long) {
    $arg = $OPTS->get($short);
    if ( is_null($arg) ) {
        $arg = $OPTS->get($long);
    }
    return $arg;
}

class git_importer {

    function __construct() {
        global $conf;
        $this->temp_dir = $conf['tmpdir'].'/gitbacked/importer';
        io_mkdir_p($this->temp_dir);
    }

    function import() {
        global $conf, $lang;
        print 'start import'.DOKU_LF;

        // acquire a lock, or exit
        $lock = $this->temp_dir.'/lock';
        if (!@mkdir($lock, $conf['dmode'], true)) {
            print 'another instance of importer is running, exit'.DOKU_LF;
            exit(1);
        }

        // init git repo
        $repo =& plugin_load('helper', 'gitbacked_git');
        $repo->setGitRepo($this->git_dir, $this->temp_dir, $this->git_branch);

        // collect history

        // search and record all page-ids and media-ids
        $pagelist = $this->temp_dir.'/pagelist.txt';
        $this->getPageList($pagelist);
        $medialist = $this->temp_dir.'/medialist.txt';
        $this->getMediaList($medialist);

        // make history list
        // format: <logline> <tab> <data-type> <tab> <data-file>
        $historylist = $this->temp_dir.'/historylist.txt';
        $stream = fopen($historylist, "wb");
        $this->processPageList($pagelist, $stream);
        $this->processMediaList($medialist, $stream);
        fclose($stream);

        // sort history list, using shell utility to prevent memory issue
        $historylisttemp = $this->temp_dir.'/historylist.txt.tmp';
        rename($historylist, $historylisttemp);
        passthru(sprintf(
            'sort -n %s >%s',
            escapeshellarg($historylisttemp),
            escapeshellarg($historylist)
        ));

        // import from history list
        $this->importHistory($repo, $historylist);

        // unlock, clean, and done
        @rmdir($lock);
        passthru(sprintf(
            'rm -rf %s',
            escapeshellarg($this->temp_dir)
        ));
        print 'done.'.DOKU_LF;
    }

    private function getPageList($listfile) {
        global $conf;
        // sort cannot output to the same file, write to a temp file first
        $tmpfile = $listfile.'.tmp';
        $lh = fopen($tmpfile, 'wb');
        // meta
        $data = array();
        search($data, $conf['metadir'], 'search_universal', array(
            'listfiles' => true,
            'skipacl' => true,
            'filematch' => '.*\.changes'
            ));
        foreach($data as $item) {
            $id = substr($item['id'], 0, -8);  // strip '.changes'
            fwrite( $lh, $id."\n" );
        }
        // pages
        $data = array();
        search($data, $conf['datadir'], 'search_universal', array(
            'listfiles' => true,
            'skipacl' => true,
            'pagesonly' => true
            ));
        foreach($data as $item) {
            $id = $item['id'];  // no additional ext here
            fwrite( $lh, $id."\n" );
        }
        fclose($lh);
        // sort and unique the history, using shell to prevent memory issue
        passthru(sprintf(
            'sort %s | uniq >%s',
            escapeshellarg($tmpfile),
            escapeshellarg($listfile)
        ));
    }

    private function getMediaList($listfile) {
        global $conf;
        // sort cannot output to the same file, write to a temp file first
        $tmpfile = $listfile.'.tmp';
        $lh = fopen($tmpfile, 'wb');
        // media_meta
        $data = array();
        search($data, $conf['mediametadir'], 'search_universal', array(
            'listfiles' => true,
            'skipacl' => true,
            'filematch' => '.*\.changes'
            ));
        foreach($data as $item) {
            $id = substr($item['id'], 0, -8);  // strip '.changes'
            fwrite( $lh, $id."\n" );
        }
        // media
        $data = array();
        search($data, $conf['mediadir'], 'search_universal', array(
            'listfiles' => true,
            'skipacl' => true
            ));
        foreach($data as $item) {
            $id = $item['id'];  // no additional ext here
            fwrite( $lh, $id."\n" );
        }
        fclose($lh);
        // sort and unique the history, using shell command to prevent memory issue
        passthru(sprintf(
            'sort %s | uniq >%s',
            escapeshellarg($tmpfile),
            escapeshellarg($listfile)
        ));
    }

    private function processPageList($listfile, $stream) {
        global $lang;
        $lh = fopen($listfile, 'rb');
        while (!feof($lh)) {
            $id = rtrim(fgets($lh), "\r\n");
            if ($id) {
                $datafile = wikiFN($id, '', false);
                $metafile = metaFN($id, '.changes');
                $lastline = null;
                if (is_file($metafile)) {
                    $fh = fopen($metafile, "rb");
                    while (!feof($fh)) {
                        $line = rtrim(fgets($fh), "\r\n");
                        if ($line) {
                            $lastline = $line = $line."\t".$id."\t"."attic"."\n";
                            fwrite( $stream, $line );
                        }
                    }
                    fclose($fh);
                    if ($lastline) { // last line not empty
                        $lastlinearray = explode("\t", $lastline);
                        $lastdate = intval($lastlinearray[0]);
                        $lastaction = $lastlinearray[2];
                    }
                }
                if (is_file($datafile)) {
                    $datadate = filemtime($datafile);
                    // there's a newer external edit on the page
                    // fake a logline for later process
                    if (!$lastline || ($datadate > $lastdate)) {
                        $logline = array(
                            'date'  => $datadate,
                            'ip'    => '127.0.0.1',
                            'type'  => DOKU_CHANGE_TYPE_EDIT,
                            'id'    => $id,
                            'user'  => '',
                            'sum'   => $lang['external_edit'],
                            'extra' => ''
                        );
                        $line = implode("\t", $logline);
                        $line = $line."\t".$id."\t"."pages"."\n";
                        fwrite( $stream, $line );
                    }
                    // page is latest revision, replace attic, which might not exist
                    else if ($lastline && ($datadate == $lastdate)) {
                        fseek( $stream, -strlen($lastline), SEEK_CUR );  // back to previous line
                        $lastlinearray[8] = "pages"."\n";  // modify the data-type field
                        $line = implode("\t", $lastlinearray);  // already has linefeed, don't append
                        fwrite( $stream, $line );
                    }
                }
                else {
                    // page is deleted externally after the latest wiki-edit
                    // fake a logline with action type "delete" for later process
                    // exact time is impossible to determine, pretend $lastdate + 1 (to sort after)
                    if ($lastline && ($lastaction != "D")) {
                        $logline = array(
                            'date'  => $lastdate + 1,
                            'ip'    => '127.0.0.1',
                            'type'  => DOKU_CHANGE_TYPE_DELETE,
                            'id'    => $id,
                            'user'  => '',
                            'sum'   => $lang['external_edit'],
                            'extra' => ''
                        );
                        $line = implode("\t", $logline);
                        $line = $line."\t".$id."\t"."pages"."\n";
                        fwrite( $stream, $line );
                    }
                }
            }
        }
        fclose($lh);
    }

    private function processMediaList($listfile, $stream) {
        global $lang;
        $lh = fopen($listfile, 'rb');
        while (!feof($lh)) {
            $id = rtrim(fgets($lh), "\r\n");
            if ($id) {
                $datafile = mediaFN($id);
                $metafile = mediaMetaFN($id, '.changes');
                $lastline = null;
                if (is_file($metafile)) {
                    $fh = fopen($metafile, "rb");
                    while (!feof($fh)) {
                        $line = rtrim(fgets($fh), "\r\n");
                        if ($line) {
                            $lastline = $line = $line."\t".$id."\t"."media_attic"."\n";
                            fwrite( $stream, $line );
                        }
                    }
                    fclose($fh);
                    if ($lastline) { // last line not empty
                        $lastlinearray = explode("\t", $lastline);
                        $lastdate = intval($lastlinearray[0]);
                        $lastaction = $lastlinearray[2];
                    }
                }
                if (is_file($datafile)) {
                    $datadate = filemtime($datafile);
                    // there's a newer external edit on the media
                    // fake a logline for later process
                    if (!$lastline || ($datadate > $lastdate)) {
                        $logline = array(  // fake a logline
                            'date'  => $datadate,
                            'ip'    => '127.0.0.1',
                            'type'  => DOKU_CHANGE_TYPE_EDIT,
                            'id'    => $id,
                            'user'  => '',
                            'sum'   => $lang['external_edit'],
                            'extra' => ''
                        );
                        $line = implode("\t", $logline);
                        $line = $line."\t".$id."\t"."media"."\n";
                        fwrite( $stream, $line );
                    }
                    // media is latest revision, replace attic, which might not exist
                    else if ($lastline && ($datadate == $lastdate)) {
                        fseek( $stream, -strlen($lastline), SEEK_CUR );  // back to previous line
                        $lastlinearray[8] = "media"."\n";  // modify the data-type field
                        $line = implode("\t", $lastlinearray);  // already has linefeed, don't append
                        fwrite( $stream, $line );
                    }
                }
                else {
                    // media is deleted externally after the latest wiki-edit
                    // fake a logline with action type "delete" for later process
                    // exact time is impossible to determine, pretend $lastdate + 1 (to sort after)
                    if ($lastline && ($lastaction != "D")) {
                        $logline = array(
                            'date'  => $lastdate + 1,
                            'ip'    => '127.0.0.1',
                            'type'  => DOKU_CHANGE_TYPE_DELETE,
                            'id'    => $id,
                            'user'  => '',
                            'sum'   => $lang['external_edit'],
                            'extra' => ''
                        );
                        $line = implode("\t", $logline);
                        $line = $line."\t".$id."\t"."media"."\n";
                        fwrite( $stream, $line );
                    }
                }
            }
        }
        fclose($lh);
    }

    private function importHistory($repo, $historyfile) {
        // basic settings
        $base = DOKU_INC.$this->getConf('repoWorkDir');
        $base_cut = strlen($base) - 1;

        // read history entries line by line and process them
        $hh = fopen($historyfile, "rb");
        while (!feof($hh)) {
            $line = rtrim(fgets($hh), "\r\n");
            if (!$line) continue;

            // read info from a line
            $logline = explode("\t", $line);
            $data_type = array_pop($logline);
            $id = array_pop($logline);
            $date = $logline[0];
            $ip = $logline[1];
            $type = $logline[2];
            $user = $logline[4];
            $summary = $logline[5];

            // Do not record a log for external edits
            // since they are not edited via the wiki system
            // 
            // TODO: Improve external edit detection
            //       Currently the protocol is not to produce false negative.
            //       False positive only occurs on anonymous edits on the localhost server,
            //       which should be very rare.
            //
            //   $ip:      false positive if edited on a localhost server
            //   $user:    false positive if it's edited by an anonymous user
            //   $summary: false positive if someone writes a summary identical to "external edit"
            //             false negative if edited under a different language pack
            $external_edit = ($ip == '127.0.0.1' && !$user);

            // add data to commit
            switch ($data_type) {
                case "pages":
                case "attic":
                    $item = wikiFN($id, '', false);
                    $file = $this->temp_dir.'/'.substr( $item, $base_cut );
                    io_mkdir_p(dirname($file));
                    if ($type == 'D') {
                        $repo->git('rm', array(
                            'cached' => null,
                            'ignore-unmatch' => null,
                            '' => $file
                            ));
                        $message = str_replace(
                            array('%page%', '%summary%', '%user%'),
                            array($id, $summary, $user),
                            $this->getConf('commitPageMsgDel')
                        );
                    }
                    else {
                        $datafile = ($data_type=='pages') ? $item : wikiFN($id, $date, false);
                        if (is_file($datafile)) {
                            file_put_contents($file, io_readFile($datafile, false));
                            $repo->git('add', array(
                                '' => $file
                                ));
                        }
                        $message = str_replace(
                            array('%page%', '%summary%', '%user%'),
                            array($id, $summary, $user),
                            $this->getConf('commitPageMsg')
                        );
                    }
                    break;
                case "media":
                case "media_attic":
                    $item = mediaFN($id, '');
                    $file = $this->temp_dir.'/'.substr( $item, $base_cut );
                    io_mkdir_p(dirname($file));
                    if ($type == 'D') {
                        $repo->git('rm', array(
                            'cached' => null,
                            'ignore-unmatch' => null,
                            '' => $file
                            ));
                        $message = str_replace(
                            array('%media%', '%user%'),
                            array($id, $user),
                            $this->getConf('commitMediaMsgDel')
                        );
                    }
                    else {
                        $datafile = ($data_type=='media') ? $item : mediaFN($id, $date);
                        if (is_file($datafile)) {
                            copy($datafile, $file);
                            $repo->git('add', array(
                                '' => $file
                                ));
                        }
                        $message = str_replace(
                            array('%media%', '%user%'),
                            array($id, $user),
                            $this->getConf('commitMediaMsg')
                        );
                    }
                    break;
            }

            // now commit
            $histmeta =& plugin_load('helper', 'gitbacked_histmeta');
            if ($external_edit) $logline = "";
            $commit_message = $histmeta->pack($message, $logline, $commands);
            $commit_date = $date;
            $repo->git('commit', array(
                'allow-empty' => null,
                'm' => $commit_message,
                'date' => $commit_date
                ));
        }
        fclose($hh);
    }

    // this script is not a true plugin, fake this method for convenience
    private function getConf($setting, $notset=false) {
        $my =& plugin_load('helper', 'gitbacked_git');
        return $my->getConf($setting, $notset);
    }

}
