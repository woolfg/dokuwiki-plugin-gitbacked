<?php

/**
 * DokuWiki Plugin gitbacked (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Wolfgang Gassler <wolfgang@gassler.org>
 */

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

require_once __DIR__ . '/../loader.php';

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

use woolfg\dokuwiki\plugin\gitbacked\Git;
use woolfg\dokuwiki\plugin\gitbacked\GitRepo;
use woolfg\dokuwiki\plugin\gitbacked\GitBackedUtil;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
class action_plugin_gitbacked_editcommit extends ActionPlugin
{
    /**
     * Temporary directory for this gitbacked plugin.
     *
     * @var string
     */
    private $temp_dir;

    public function __construct()
    {
        $this->temp_dir = GitBackedUtil::getTempDir();
    }

    public function register(EventHandler $controller)
    {
        $controller->register_hook('IO_WIKIPAGE_WRITE', 'AFTER', $this, 'handleIOWikiPageWrite');
        $controller->register_hook('MEDIA_UPLOAD_FINISH', 'AFTER', $this, 'handleMediaUpload');
        $controller->register_hook('MEDIA_DELETE_FILE', 'AFTER', $this, 'handleMediaDeletion');
        $controller->register_hook('DOKUWIKI_DONE', 'AFTER', $this, 'handlePeriodicPull');
    }

    private function initRepo()
    {
        //get path to the repo root (by default DokuWiki's savedir)
        $repoPath = GitBackedUtil::getEffectivePath($this->getConf('repoPath'));
        $gitPath = trim($this->getConf('gitPath'));
        if ($gitPath !== '') {
            Git::setBin($gitPath);
        }
        //init the repo and create a new one if it is not present
        io_mkdir_p($repoPath);
        $repo = new GitRepo($repoPath, $this, true, true);
        //set git working directory (by default DokuWiki's savedir)
        $repoWorkDir = $this->getConf('repoWorkDir');
        if (!empty($repoWorkDir)) {
            $repoWorkDir = GitBackedUtil::getEffectivePath($repoWorkDir);
        }
        Git::setBin(empty($repoWorkDir) ? Git::getBin()
            : Git::getBin() . ' --work-tree ' . escapeshellarg($repoWorkDir));
        $params = str_replace(
            array('%mail%', '%user%'),
            array($this->getAuthorMail(), $this->getAuthor()),
            $this->getConf('addParams')
        );
        if ($params) {
            Git::setBin(Git::getBin() . ' ' . $params);
        }
        return $repo;
    }

    private function isIgnored($filePath)
    {
        $ignore = false;
        $ignorePaths = trim($this->getConf('ignorePaths'));
        if ($ignorePaths !== '') {
            $paths = explode(',', $ignorePaths);
            foreach ($paths as $path) {
                if (strstr($filePath, $path)) {
                    $ignore = true;
                }
            }
        }
        return $ignore;
    }

    private function commitFile($filePath, $message)
    {
        if (!$this->isIgnored($filePath)) {
            try {
                $repo = $this->initRepo();

                //add the changed file and set the commit message
                $repo->add($filePath);
                $repo->commit($message);

                //if the push after Commit option is set we push the active branch to origin
                if ($this->getConf('pushAfterCommit')) {
                    $repo->push('origin', $repo->activeBranch());
                }
            } catch (Exception $e) {
                if (!$this->isNotifyByEmailOnGitCommandError()) {
                    throw new Exception('Git committing or pushing failed: ' . $e->getMessage(), 1, $e);
                }
                return;
            }
        }
    }

    private function getAuthor()
    {
        return $GLOBALS['USERINFO']['name'];
    }

    private function getAuthorMail()
    {
        return $GLOBALS['USERINFO']['mail'];
    }

    private function computeLocalPath()
    {
        global $conf;
        $repoPath = str_replace('\\', '/', realpath(GitBackedUtil::getEffectivePath($this->getConf('repoPath'))));
        $datadir = $conf['datadir']; // already normalized
        if (!(substr($datadir, 0, strlen($repoPath)) === $repoPath)) {
            throw new Exception('Datadir not inside repoPath ??');
        }
        return substr($datadir, strlen($repoPath) + 1);
    }

    private function updatePage($page)
    {

        if (is_callable('\\dokuwiki\\Search\\Indexer::getInstance')) {
            $Indexer = \dokuwiki\Search\Indexer::getInstance();
            $success = $Indexer->addPage($page, false, false);
        } elseif (class_exists('Doku_Indexer')) {
            $success = idx_addPage($page, false, false);
        } else {
            // Failed to index the page. Your DokuWiki is older than release 2011-05-25 "Rincewind"
            $success = false;
        }

        echo "Update $page: $success <br/>";
    }

    public function handlePeriodicPull(Event &$event, $param)
    {
        if ($this->getConf('periodicPull')) {
            $enableIndexUpdate = $this->getConf('updateIndexOnPull');
            $lastPullFile = $this->temp_dir . '/lastpull.txt';
            //check if the lastPullFile exists
            if (is_file($lastPullFile)) {
                $lastPull = unserialize(file_get_contents($lastPullFile));
            } else {
                $lastPull = 0;
            }
            //calculate time between pulls in seconds
            $timeToWait = $this->getConf('periodicMinutes') * 60;
            $now = time();

            //if it is time to run a pull request
            if ($lastPull + $timeToWait < $now) {
                try {
                    $repo = $this->initRepo();
                    if ($enableIndexUpdate) {
                        $localPath = $this->computeLocalPath();

                        // store current revision id
                        $revBefore = $repo->run('rev-parse HEAD');
                    }

                    //execute the pull request
                    $repo->pull('origin', $repo->activeBranch());

                    if ($enableIndexUpdate) {
                        // store new revision id
                        $revAfter = $repo->run('rev-parse HEAD');

                        if (strcmp($revBefore, $revAfter) != 0) {
                            // if there were some changes, get the list of all changed files
                            $changedFilesPage = $repo->run('diff --name-only ' . $revBefore . ' ' . $revAfter);
                            $changedFiles = preg_split("/\r\n|\n|\r/", $changedFilesPage);

                            foreach ($changedFiles as $cf) {
                                // check if the file is inside localPath, that is, it's a page
                                if (substr($cf, 0, strlen($localPath)) === $localPath) {
                                    // convert from relative filename to page name
                                    // for example: local/path/dir/subdir/test.txt -> dir:subdir:test
                                    // -4 removes .txt
                                    $page = str_replace('/', ':', substr($cf, strlen($localPath) + 1, -4));

                                    // update the page
                                    $this->updatePage($page);
                                } else {
                                    echo "Page NOT to update: $cf <br/>";
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    if (!$this->isNotifyByEmailOnGitCommandError()) {
                        throw new Exception('Git command failed to perform periodic pull: ' . $e->getMessage(), 2, $e);
                    }
                    return;
                }

                //save the current time to the file to track the last pull execution
                file_put_contents($lastPullFile, serialize(time()));
            }
        }
    }

    public function handleMediaDeletion(Event &$event, $param)
    {
        $mediaPath = $event->data['path'];
        $mediaName = $event->data['name'];

        $message = str_replace(
            array('%media%', '%user%'),
            array($mediaName, $this->getAuthor()),
            $this->getConf('commitMediaMsgDel')
        );

        $this->commitFile($mediaPath, $message);
    }

    public function handleMediaUpload(Event &$event, $param)
    {

        $mediaPath = $event->data[1];
        $mediaName = $event->data[2];

        $message = str_replace(
            array('%media%', '%user%'),
            array($mediaName, $this->getAuthor()),
            $this->getConf('commitMediaMsg')
        );

        $this->commitFile($mediaPath, $message);
    }

    public function handleIOWikiPageWrite(Event &$event, $param)
    {

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
                array('%page%', '%summary%', '%user%'),
                array($pageName, $editSummary, $this->getAuthor()),
                $msgTemplate
            );

            $this->commitFile($pagePath, $message);
        }
    }

    // ====== Error notification helpers ======
    /**
     * Notifies error on create_new
     *
     * @access  public
     * @param   string  repository path
     * @param   string  reference path / remote reference
     * @param   string  error message
     * @return  bool
     */
    public function notifyCreateNewError($repo_path, $reference, $error_message)
    {
        $template_replacements = array(
            'GIT_REPO_PATH' => $repo_path,
            'GIT_REFERENCE' => (empty($reference) ? 'n/a' : $reference),
            'GIT_ERROR_MESSAGE' => $error_message
        );
        return $this->notifyByMail('mail_create_new_error_subject', 'mail_create_new_error', $template_replacements);
    }

    /**
     * Notifies error on setting repo path
     *
     * @access  public
     * @param   string  repository path
     * @param   string  error message
     * @return  bool
     */
    public function notifyRepoPathError($repo_path, $error_message)
    {
        $template_replacements = array(
            'GIT_REPO_PATH' => $repo_path,
            'GIT_ERROR_MESSAGE' => $error_message
        );
        return $this->notifyByMail('mail_repo_path_error_subject', 'mail_repo_path_error', $template_replacements);
    }

    /**
     * Notifies error on git command
     *
     * @access  public
     * @param   string  repository path
     * @param   string  current working dir
     * @param   string  command line
     * @param   int     exit code of command (status)
     * @param   string  error message
     * @return  bool
     */
    public function notifyCommandError($repo_path, $cwd, $command, $status, $error_message)
    {
        $template_replacements = array(
            'GIT_REPO_PATH' => $repo_path,
            'GIT_CWD' => $cwd,
            'GIT_COMMAND' => $command,
            'GIT_COMMAND_EXITCODE' => $status,
            'GIT_ERROR_MESSAGE' => $error_message
        );
        return $this->notifyByMail('mail_command_error_subject', 'mail_command_error', $template_replacements);
    }

    /**
     * Notifies success on git command
     *
     * @access  public
     * @param   string  repository path
     * @param   string  current working dir
     * @param   string  command line
     * @return  bool
     */
    public function notifyCommandSuccess($repo_path, $cwd, $command)
    {
        if (!$this->getConf('notifyByMailOnSuccess')) {
            return false;
        }
        $template_replacements = array(
            'GIT_REPO_PATH' => $repo_path,
            'GIT_CWD' => $cwd,
            'GIT_COMMAND' => $command
        );
        return $this->notifyByMail('mail_command_success_subject', 'mail_command_success', $template_replacements);
    }

    /**
     * Send an eMail, if eMail address is configured
     *
     * @access  public
     * @param   string  lang id for the subject
     * @param   string  lang id for the template(.txt)
     * @param   array   array of replacements
     * @return  bool
     */
    public function notifyByMail($subject_id, $template_id, $template_replacements)
    {
        $ret = false;
        //dbglog("GitBacked - notifyByMail: [subject_id=" . $subject_id
        //    . ", template_id=" . $template_id
        //    . ", template_replacements=" . $template_replacements . "]");
        if (!$this->isNotifyByEmailOnGitCommandError()) {
            return $ret;
        }
        //$template_text = rawLocale($template_id); // this works for core artifacts only - not for plugins
        $template_filename = $this->localFN($template_id);
        $template_text = file_get_contents($template_filename);
        $template_html = $this->render_text($template_text);

        $mailer = new \Mailer();
        $mailer->to($this->getEmailAddressOnErrorConfigured());
        //dbglog("GitBacked - lang check['".$subject_id."']: ".$this->getLang($subject_id));
        //dbglog("GitBacked - template text['".$template_id."']: ".$template_text);
        //dbglog("GitBacked - template html['".$template_id."']: ".$template_html);
        $mailer->subject($this->getLang($subject_id));
        $mailer->setBody($template_text, $template_replacements, null, $template_html);
        $ret = $mailer->send();

        return $ret;
    }

    /**
     * Check, if eMail is to be sent on a Git command error.
     *
     * @access  public
     * @return  bool
     */
    public function isNotifyByEmailOnGitCommandError()
    {
        $emailAddressOnError = $this->getEmailAddressOnErrorConfigured();
        return !empty($emailAddressOnError);
    }

    /**
     * Get the eMail address configured for notifications.
     *
     * @access  public
     * @return  string
     */
    public function getEmailAddressOnErrorConfigured()
    {
        $emailAddressOnError = trim($this->getConf('emailAddressOnError'));
        return $emailAddressOnError;
    }
}
// phpcs:enable Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:enable PSR1.Classes.ClassDeclaration.MissingNamespace

// vim:ts=4:sw=4:et:
