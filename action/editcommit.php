<?php
/**
 * DokuWiki Plugin gitbacked (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Wolfgang Gassler <wolfgang@gassler.org>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once DOKU_PLUGIN.'action.php';
require_once dirname(__FILE__).'/../lib/Git.php';

class action_plugin_gitbacked_editcommit extends DokuWiki_Action_Plugin {

    function __construct() {
        global $conf;
        $this->temp_dir = $conf['tmpdir'].'/gitbacked';
        io_mkdir_p($this->temp_dir);
    }

    public function register(Doku_Event_Handler &$controller) {

        $controller->register_hook('IO_WIKIPAGE_WRITE', 'AFTER', $this, 'handle_io_wikipage_write');
        $controller->register_hook('MEDIA_UPLOAD_FINISH', 'AFTER', $this, 'handle_media_upload');
        $controller->register_hook('MEDIA_DELETE_FILE', 'AFTER', $this, 'handle_media_deletion');
        $controller->register_hook('DOKUWIKI_DONE', 'AFTER', $this, 'handle_periodic_pull');
    }

    public function handle_periodic_pull(Doku_Event &$event, $param) {
        if ($this->getConf('periodicPull')) {
            $lastPullFile = $this->temp_dir.'/lastpull.txt';
            //check if the lastPullFile exists
            if (is_file($lastPullFile)) {
                $lastPull = unserialize(file_get_contents($lastPullFile));
            } else {
                $lastPull = 0;
            }
            //calculate time between pulls in seconds
            $timeToWait = $this->getConf('periodicMinutes')*60;
            $now = time();

            //if it is time to run a pull request
            if ($lastPull+$timeToWait < $now) {
                $repo = $this->initRepo();

                //execute the pull request
                $repo->pull('origin',$repo->active_branch());

                //save the current time to the file to track the last pull execution
                file_put_contents($lastPullFile,serialize(time()));
            }
        }
    }

    private function initRepo() {
        //get path to the repo root (by default DokuWiki's savedir)
        $repoPath = DOKU_INC.$this->getConf('repoPath');
        //init the repo and create a new one if it is not present
        io_mkdir_p($repoPath);
        $repo = new GitRepo($repoPath, true, true);
        //set git working directory (by default DokuWiki's savedir)
        $repoWorkDir = DOKU_INC.$this->getConf('repoWorkDir');
        $repo->git_path .= ' --work-tree '.escapeshellarg($repoWorkDir);

        $params = str_replace(
            array('%mail%','%user%'),
            array($this->getAuthorMail(),$this->getAuthor()),
            $this->getConf('addParams'));
        if ($params) {
            $repo->git_path .= ' '.$params;
        }
        foreach($this->getConf('envParams') as $e) {
            $p = strpos($e, '=');
            $k = substr($e,0,$p);
            $v = substr($e,$p+1);
            $repo->setenv($k,$v);
        }
        return $repo;
    }

    private function commitFile($filePath,$message) {

        $repo = $this->initRepo();

        //add the changed file and set the commit message
        $repo->add($filePath);
        $repo->commit($message);

        //if the push after Commit option is set we push the active branch to origin
        if ($this->getConf('pushAfterCommit')) {
            $repo->push('origin',$repo->active_branch());
        }

    }

    private function getAuthor() {
        return $GLOBALS['USERINFO']['name'];
    }

    private function getAuthorMail() {
        return $GLOBALS['USERINFO']['mail'];
    }

    public function handle_media_deletion(Doku_Event &$event, $param) {
        $mediaPath = $event->data['path'];
        $mediaName = $event->data['name'];

        $message = str_replace(
            array('%media%','%user%','%mail%','%nl%'),
            array($mediaName,$this->getAuthor(),$this->getAuthorMail(),"\n"),
            $this->getConf('commitMediaMsgDel')
        );

        $this->commitFile($mediaPath,$message);

    }

    public function handle_media_upload(Doku_Event &$event, $param) {

        $mediaPath = $event->data[1];
        $mediaName = $event->data[2];

        $message = str_replace(
            array('%media%','%user%','%mail%','%nl%'),
            array($mediaName,$this->getAuthor(),$this->getAuthorMail(),"\n"),
            $this->getConf('commitMediaMsg')
        );

        $this->commitFile($mediaPath,$message);

    }

    public function handle_io_wikipage_write(Doku_Event &$event, $param) {

        $rev = $event->data[3];

        /* On update to an existing page this event is called twice,
         * once for the transfer of the old version to the attic (rev will have a value)
         * and once to write the new version of the page into the wiki (rev is false)
         */
        if (!$rev) {

            $pageFsPath = $event->data[0][0];
            $pageNsPath = $event->data[1];
            $pageName = $event->data[2];
            $pageContent = $event->data[0][1];

            $fullPageName = str_replace(':', '/', $pageNsPath).'/'.$pageName;

            // get the summary directly from the form input
            // as the metadata hasn't updated yet
            $editSummary = $GLOBALS['INPUT']->str('summary');

            // empty content indicates a page deletion
            if ($pageContent == '') {
                // get the commit text for deletions
                $msgTemplate = $this->getConf('commitPageMsgDel');

                // bad hack as DokuWiki deletes the file after this event
                // thus, let's delete the file by ourselves, so git can recognize the deletion
                // DokuWiki uses @unlink as well, so no error should be thrown if we delete it twice
                @unlink($pageFsPath);

            } else {
                //get the commit text for edits
                $msgTemplate = $this->getConf('commitPageMsg');
            }

            $message = str_replace(
                array('%fullpage%','%pagens%','%page%','%summary%','%user%','%mail%','%nl%'),
                array($fullPageName,$pageNsPath,$pageName,$editSummary,$this->getAuthor(),$this->getAuthorMail(),"\n"),
                //array($pageName,$editSummary,$this->getAuthor(),$this->getAuthorMail()),
                $msgTemplate
            );

            $this->commitFile($pageFsPath,$message);

        }

    }

}

// vim:ts=4:sw=4:et:
