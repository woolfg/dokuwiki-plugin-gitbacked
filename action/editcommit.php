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

class action_plugin_gitbacked_editcommit extends DokuWiki_Action_Plugin {

    function __construct() {
        global $conf;
        $this->temp_dir = $conf['tmpdir'].'/gitbacked';
    }

    public function register(Doku_Event_Handler &$controller) {
        if ($this->getConf('autoCommit')) {
            $controller->register_hook('IO_WIKIPAGE_WRITE', 'AFTER', $this, 'handle_io_wikipage_write');
            $controller->register_hook('MEDIA_UPLOAD_FINISH', 'AFTER', $this, 'handle_media_upload');
            $controller->register_hook('MEDIA_DELETE_FILE', 'AFTER', $this, 'handle_media_deletion');
        }
        if ($this->getConf('periodicPull')) {
            $controller->register_hook('DOKUWIKI_DONE', 'AFTER', $this, 'handle_periodic_pull');
        }
        // add this setting so that escapeshellarg() doesn't strip non-ASCII characters
        // when executing php on the web
        setlocale(LC_CTYPE, "en_US.UTF-8");
    }

    private function initRepo($work_tree=null) {
        $repo =& plugin_load('helper', 'gitbacked_git');
        if (!$repo->getGitRepo) {
            $repo->setGitRepo(null, $work_tree);
        }
        return $repo;
    }

    private function commitFile($file, $message) {
        $tempdir = $this->temp_dir.'/'.strval(time()).'_commit';
        $repo = $this->initRepo($tempdir);
        $repo->lock();
        
        //add the changed file and set the commit message
        $repo->addFile($file);
        $repo->git('commit --allow-empty -m '.escapeshellarg($message));

        //if the push after Commit option is set we push the active branch to origin
        if ($this->getConf('pushAfterCommit')) {
            $repo->git('push origin '.escapeshellarg($repo->active_branch()));
        }

        $repo->unlock();
        $repo->clearDir($tempdir);
    }

    private function getAuthor() {
        return $GLOBALS['USERINFO']['name'];
    }

    public function handle_periodic_pull(Doku_Event &$event, $param) {
        io_mkdir_p($this->temp_dir);
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
            //use an empty folder as working dir
            $tempdir = $this->temp_dir.'/'.strval(time()).'_pull';
            $repo = $this->initRepo($tempdir);

            //execute the pull request
            $repo->lock();
            $repo->git('pull origin -f '.escapeshellarg($repo->active_branch()));
            $repo->unlock();
            $repo->clearDir($tempdir);

            //save the current time to the file to track the last pull execution
            file_put_contents($lastPullFile,serialize(time()));
        }
    }

    public function handle_media_deletion(Doku_Event &$event, $param) {
        $mediaPath = $event->data['path'];
        $mediaName = $event->data['name'];

        $message = str_replace(
            array('%media%','%user%'),
            array($mediaName,$this->getAuthor()),
            $this->getConf('commitMediaMsgDel')
        );

        $this->commitFile($mediaPath,$message);
    }

    public function handle_media_upload(Doku_Event &$event, $param) {
        $mediaPath = $event->data[1];
        $mediaName = $event->data[2];

        $message = str_replace(
            array('%media%','%user%'),
            array($mediaName,$this->getAuthor()),
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
            $pagePath = $event->data[0][0];
            $pageName = $event->data[2];
            $pageContent = $event->data[0][1];

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
                @unlink($pagePath);
            } else {
                //get the commit text for edits
                $msgTemplate = $this->getConf('commitPageMsg');
            }

            $message = str_replace(
                array('%page%','%summary%','%user%'),
                array($pageName,$editSummary,$this->getAuthor()),
                $msgTemplate
            );

            $this->commitFile($pagePath,$message);
        }
    }
}

// vim:ts=4:sw=4:et:
