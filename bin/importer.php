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
        $this->backup =& plugin_load('helper', 'gitbacked_backup');
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
                        $logline = rtrim(fgets($fh), "\r\n");
                        if ($logline) {
                            $lastlogline = $logline;
                            $lastline = $this->packHistoryLine($logline, $id, "attic");
                            fwrite( $stream, $lastline );
                        }
                    }
                    fclose($fh);
                    if ($lastline) { // last line not empty
                        $logline = explode("\t", $lastlogline);
                        $lastdate = intval($logline[0]);
                        $lastaction = $logline[2];
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
                        $logline = $this->packHistoryLine($logline, $id, "pages");
                        fwrite( $stream, $logline );
                    }
                    // page is latest revision, replace attic, which might not exist
                    else if ($lastline && ($datadate == $lastdate)) {
                        fseek( $stream, -strlen($lastline), SEEK_CUR );  // back to previous line
                        $logline = $this->packHistoryLine($lastlogline, $id, "pages");
                        fwrite( $stream, $logline );
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
                        $logline = $this->packHistoryLine($logline, $id, "pages");
                        fwrite( $stream, $logline );
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
                        $logline = rtrim(fgets($fh), "\r\n");
                        if ($logline) {
                            $lastlogline = $logline;
                            $lastline = $this->packHistoryLine($logline, $id, "media_attic");
                            fwrite( $stream, $lastline );
                        }
                    }
                    fclose($fh);
                    if ($lastline) { // last line not empty
                        $logline = explode("\t", $lastlogline);
                        $lastdate = intval($logline[0]);
                        $lastaction = $logline[2];
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
                        $logline = $this->packHistoryLine($logline, $id, "media");
                        fwrite( $stream, $logline );
                    }
                    // media is latest revision, replace attic, which might not exist
                    else if ($lastline && ($datadate == $lastdate)) {
                        fseek( $stream, -strlen($lastline), SEEK_CUR );  // back to previous line
                        $logline = $this->packHistoryLine($lastlogline, $id, "media");
                        fwrite( $stream, $logline );
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
                        $logline = $this->packHistoryLine($logline, $id, "media");
                        fwrite( $stream, $logline );
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

            // reset commands
            $commands = array();

            // read info from a line
            list( $logline, $data_id, $data_type, $data_extra) = $this->unpackHistoryLine($line);
            list( $date, $ip, $type, $id, $user, $summary, $extra ) = $logline;

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
                    $item = wikiFN($data_id, '', false);
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
                            array($data_id, $summary, $user),
                            $this->getConf('commitPageMsgDel')
                        );
                    }
                    else {
                        $datafile = ($data_type=='pages') ? $item : wikiFN($data_id, $date, false);
                        // history entry exist, data missing?
                        // or hidden if found in the backup directory
                        if (!is_file($datafile)) {
                            $datafile = $this->backup->wikiFN($id, $date, false);
                            if (is_file($datafile)) $commands[] = "hide data";
                        }
                        if (is_file($datafile)) {
                            file_put_contents($file, io_readFile($datafile, false));
                            $repo->git('add', array(
                                '' => $file
                                ));
                        }
                        $message = str_replace(
                            array('%page%', '%summary%', '%user%'),
                            array($data_id, $summary, $user),
                            $this->getConf('commitPageMsg')
                        );
                    }
                    break;
                case "media":
                case "media_attic":
                    $item = mediaFN($data_id, '');
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
                            array($data_id, $user),
                            $this->getConf('commitMediaMsgDel')
                        );
                    }
                    else {
                        $datafile = ($data_type=='media') ? $item : mediaFN($data_id, $date);
                        // history entry exist, data missing?
                        // or hidden if found in the backup directory
                        if (!is_file($datafile)) {
                            $datafile = $this->backup->mediaFN($id, $date);
                            if (is_file($datafile)) $commands[] = "hide data";
                        }
                        if (is_file($datafile)) {
                            copy($datafile, $file);
                            $repo->git('add', array(
                                '' => $file
                                ));
                        }
                        $message = str_replace(
                            array('%media%', '%user%'),
                            array($data_id, $user),
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

    /**
     * Packs information into a single history line
     *
     * @param  mixed   the original dokuwiki logline, array or string, with or without a linefeed
     * @param  string  the resource id to access
     * @param  string  the resource type
     * @param  string  additional information such as hidden
     * @return string  an entry line, with linefeed
     */
    private function packHistoryLine($logline, $data_id="", $data_type="", $data_extra="") {
        if (is_array($logline)) $logline = implode("\t", $logline);
        $logline = rtrim($logline, "\r\n");
        $packed = array($logline, $data_id, $data_type, $data_extra);
        return implode("\t", $packed)."\n";
    }

    /**
     * Unpacks information from a single history line
     *
     * @param  string  a line packed by packHistoryLine, with or without a linefeed
     * @return array   array(array $logline, string $data_id, string $data_type, string $data_extra)
     */
    private function unpackHistoryLine($packed) {
        $packed = rtrim($packed, "\r\n");
        $unpacked = explode("\t", $packed);
        $data_extra = array_pop($unpacked);
        $data_type = array_pop($unpacked);
        $data_id = array_pop($unpacked);
        $logline = $unpacked;
        return array($logline, $data_id, $data_type, $data_extra);
    }

}
