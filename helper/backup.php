<?php
// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

/**
 * Gets the backup directory
 */
class helper_plugin_gitbacked_backup extends DokuWiki_Plugin {

    /**
     * Gets the backup directory corresponding to the $conf[$setting] directory
     */
    function conf($setting) {
        global $conf;
        return $conf[$setting] . $this->getConf('backupSuffix');
    }

    /**
     * Gets the backup file by the usual filename and the usual path
     */
    function getBackupFile($file, $dir) {
        $dir_bak = $dir . $this->getConf('backupSuffix');
        return $dir_bak . substr($file, strlen($dir));
    }

    /**
     * Functions getting the backup file corresponding to the usual version
     */
    function wikiFN($raw_id,$rev='',$clean=true){
        global $conf;
        $file = wikiFN($raw_id,$rev,$clean);
        $dir = empty($rev) ? $conf['datadir'] : $conf['olddir'];
        return $this->getBackupFile($file, $dir);
    }

    function mediaFN($id, $rev=''){
        global $conf;
        $file = mediaFN($id, $rev);
        $dir = empty($rev) ? $conf['mediadir'] : $conf['mediaolddir'];
        return $this->getBackupFile($file, $dir);
    }

    function metaFN($id,$ext){
        global $conf;
        $file = metaFN($id,$ext);
        $dir = $conf['metadir'];
        return $this->getBackupFile($file, $dir);
    }
    
    function mediaMetaFN($id,$ext){
        global $conf;
        $file = mediaMetaFN($id,$ext);
        $dir = $conf['mediametadir'];
        return $this->getBackupFile($file, $dir);
    }
}
