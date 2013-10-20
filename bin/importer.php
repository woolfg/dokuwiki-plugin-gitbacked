#!/usr/bin/php
<?php
if ('cli' != php_sapi_name()) die();

ini_set('memory_limit','128M');
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../../../').'/');
require_once DOKU_INC.'inc/init.php';
require_once DOKU_INC.'inc/cliopts.php';

// handle options
$short_opts = 'hr';
$long_opts  = array('help', 'run', 'git-dir=');

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

    function import() {
        global $conf, $lang;
        print 'start import'.DOKU_LF;

        // defines variables
        $temp_dir = $conf['tmpdir'].'/gitbacked/importer';
        io_mkdir_p($temp_dir);

        // acquire a lock, or exit
        $lock = $temp_dir.'/lock';
        if (!@mkdir($lock, $conf['dmode'], true)) {
            print 'another instance of importer is running, exit'.DOKU_LF;
            exit(1);
        }

        // init git repo
        $repo =& plugin_load('helper', 'gitbacked_git');
        $repo->setGitRepo(null, $temp_dir);

        // collect history
        $history = $temp_dir.'/history.txt';
        $hh = fopen($history, 'wb');
        // -- meta/*.changes
        $data = null;
        search($data, $conf['metadir'], 'search_universal', array(
            'listfiles' => true,
            'skipacl' => true,
            'filematch' => '.*\.changes'
            ));
        foreach($data as $item) {
            $id = substr($item['id'], 0, -8);  // strip ext
            $file = metaFN($id, '.changes');
            $fh = fopen($file, "rb");
            while (!feof($fh)) {
                $line = rtrim(fgets($fh), "\r\n");
                if ($line) fwrite( $hh, $line."\t".$id."\t"."meta"."\n" );
            }
            fclose($fh);
        }
        // -- media_meta/*.changes
        $data = null;
        search($data, $conf['mediametadir'], 'search_universal', array(
            'listfiles' => true,
            'skipacl' => true,
            'filematch' => '.*\.changes'
            ));
        foreach($data as $item) {
            $id = substr($item['id'], 0, -8);  // strip ext
            $file = mediaMetaFN($id, '.changes');
            $fh = fopen($file, "rb");
            while (!feof($fh)) {
                $line = rtrim(fgets($fh), "\r\n");
                if ($line) fwrite( $hh, $line."\t".$id."\t"."media_meta"."\n" );
            }
            fclose($fh);
        }
        // -- pages/*
        $data = null;
        search($data, $conf['datadir'], 'search_universal', array(
            'listfiles' => true,
            'skipacl' => true,
            'pagesonly' => true
            ));
        foreach($data as $item) {
            $id = $item['id'];
            $file = wikiFN($id, '', false);
            $old = @filemtime($file); // from page
            $oldRev = getRevisions($id, -1, 1, 1024); // from changelog
            $oldRev = (int) (empty($oldRev) ? 0 : $oldRev[0]);
            if(!@file_exists(wikiFN($id, $old)) && @file_exists($file) && $old >= $oldRev) {
                $logline = array(
                    'date'  => $old,
                    'ip'    => '127.0.0.1',
                    'type'  => DOKU_CHANGE_TYPE_EDIT,
                    'id'    => $id,
                    'user'  => '',
                    'sum'   => $lang['external_edit'],
                    'extra' => ''
                    );
                $line = implode("\t", $logline);
                fwrite( $hh, $line."\t".$id."\t"."pages"."\n" );
            }
        }
        // -- media/*
        $data = null;
        search($data, $conf['mediadir'], 'search_universal', array(
            'listfiles' => true,
            'skipacl' => true
            ));
        foreach($data as $item) {
            $id = $item['id'];
            $file = mediaFN($id, '');
            $old = @filemtime($file);
            if(!@file_exists(mediaFN($id, $old)) && @file_exists($file)) {
                $logline = array(
                    'date'  => $old,
                    'ip'    => '127.0.0.1',
                    'type'  => DOKU_CHANGE_TYPE_EDIT,
                    'id'    => $id,
                    'user'  => '',
                    'sum'   => $lang['external_edit'],
                    'extra' => ''
                    );
                $line = implode("\t", $logline);
                fwrite( $hh, $line."\t".$id."\t"."media"."\n" );
            }
        }
        fclose($hh);

        // sort the history, using shell sort to prevent memory issue
        $history_sort = $temp_dir.'/history_sort.txt';
        passthru(sprintf(
            'sort -n %s >%s',
            escapeshellarg($history),
            escapeshellarg($history_sort)
        ));

        // import from history
        $base = DOKU_INC.$this->getConf('repoWorkDir');
        $base_cut = strlen($base) - 1;
        $hh = fopen($history_sort, "rb");
        while (!feof($hh)) {
            $line = rtrim(fgets($hh), "\r\n");
            $logline = explode("\t", $line);
            $data_type = array_pop($logline);
            $id = array_pop($logline);
            $date = $logline[0];
            $type = $logline[2];
            $user = $logline[4];
            $message = $logline[5];
            $logline = implode("\t", $logline);
            $logfile = $temp_dir.'/_edit';
            file_put_contents($logfile, $logline);
            $repo->git('add', array(
                '' => $logfile
                ));
            switch ($data_type) {
                case "meta":
                    $source = wikiFN($id, '', false);
                    $file = $temp_dir.'/'.substr( $source, $base_cut );
                    print "import $source \n";
                    io_mkdir_p(dirname($file));
                    if ($type == 'D') {
                        $message = str_replace(
                            array('%page%', '%summary%', '%user%'),
                            array($id, $message, $user),
                            $this->getConf('commitPageMsgDel')
                        );
                        $repo->git('rm', array(
                            'cached' => null,
                            'ignore-unmatch' => null,
                            '' => $file
                            ));
                        $repo->git('commit', array(
                            'allow-empty' => null,
                            'm' => $message,
                            'date' => $date
                            ));
                    }
                    else {
                        $message = str_replace(
                            array('%page%', '%summary%', '%user%'),
                            array($id, $message, $user),
                            $this->getConf('commitPageMsg')
                        );
                        $attic = wikiFN($id, $date, false);
                        file_put_contents($file, io_readFile($attic, false));
                        $repo->git('add', array(
                            '' => $file
                            ));
                        $repo->git('commit', array(
                            'allow-empty' => null,
                            'm' => $message,
                            'date' => $date
                            ));
                    }
                    break;
                case "media_meta":
                    $source = mediaFN($id, '');
                    $file = $temp_dir.'/'.substr( $source, $base_cut );
                    print "import $source \n";
                    io_mkdir_p(dirname($file));
                    if ($type == 'D') {
                        $message = str_replace(
                            array('%media%', '%user%'),
                            array($id, $user),
                            $this->getConf('commitMediaMsgDel')
                        );
                        $repo->git('rm', array(
                            'cached' => null,
                            'ignore-unmatch' => null,
                            '' => $file
                            ));
                        $repo->git('commit', array(
                            'allow-empty' => null,
                            'm' => $message,
                            'date' => $date
                            ));
                    }
                    else {
                        $message = str_replace(
                            array('%media%', '%user%'),
                            array($id, $user),
                            $this->getConf('commitMediaMsg')
                        );
                        $attic = mediaFN($id, $date);
                        if (is_file($attic)) {
                            @copy($attic, $file);
                            $repo->git('add', array(
                                '' => $file
                                ));
                        }
                        $repo->git('commit', array(
                            'allow-empty' => null,
                            'm' => $message,
                            'date' => $date
                            ));
                    }
                    break;
                case "pages":
                    $source = wikiFN($id, '', false);
                    $file = $temp_dir.'/'.substr( $source, $base_cut );
                    print "import $source \n";
                    io_mkdir_p(dirname($file));
                    copy($source, $file);
                    $message = str_replace(
                        array('%page%', '%summary%', '%user%'),
                        array($id, $message, $user),
                        $this->getConf('commitPageMsg')
                    );
                    $repo->git('add', array(
                        '' => $file
                        ));
                    $repo->git('commit', array(
                        'allow-empty' => null,
                        'm' => $message,
                        'date' => $date
                        ));
                    break;
                case "media":
                    $source = mediaFN($id, '');
                    $file = $temp_dir.'/'.substr( $source, $base_cut );
                    print "import $source \n";
                    io_mkdir_p(dirname($file));
                    copy($source, $file);
                    $message = str_replace(
                        array('%media%', '%user%'),
                        array($id, $user),
                        $this->getConf('commitMediaMsg')
                    );
                    $repo->git('add', array(
                        '' => $file
                        ));
                    $repo->git('commit', array(
                        'allow-empty' => null,
                        'm' => $message,
                        'date' => $date
                        ));
                    break;
            }
        }
        fclose($hh);
        

        // unlock, clean, and done
        @rmdir($lock);
        passthru(sprintf(
            'rm -rf %s',
            escapeshellarg($temp_dir)
        ));
        print 'done.'.DOKU_LF;
    }

    // this script is not a true plugin, fake this method for convenience
    private function getConf($setting, $notset=false) {
        $my =& plugin_load('helper', 'gitbacked_git');
        return $my->getConf($setting, $notset);
    }

}
