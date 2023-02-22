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
require_once dirname(__FILE__).'/../lib/GitBackedUtil.php';

class action_plugin_gitbacked_editcommit extends DokuWiki_Action_Plugin {

    function __construct() {
        $this->temp_dir = GitBackedUtil::getTempDir();
    }

    public function register(Doku_Event_Handler $controller) {

        $controller->register_hook('IO_WIKIPAGE_WRITE', 'AFTER', $this, 'handle_io_wikipage_write');
        $controller->register_hook('MEDIA_UPLOAD_FINISH', 'AFTER', $this, 'handle_media_upload');
        $controller->register_hook('MEDIA_DELETE_FILE', 'AFTER', $this, 'handle_media_deletion');
        $controller->register_hook('DOKUWIKI_DONE', 'AFTER', $this, 'handle_periodic_pull');
    }

    private function initRepo() {
        //get path to the repo root (by default DokuWiki's savedir)
        $repoPath = GitBackedUtil::getEffectivePath($this->getConf('repoPath'));
        $gitPath = trim($this->getConf('gitPath'));
        if ($gitPath !== '') {
            Git::set_bin($gitPath);
        }
        //init the repo and create a new one if it is not present
        io_mkdir_p($repoPath);
        $repo = new GitRepo($repoPath, $this, true, true);
        //set git working directory (by default DokuWiki's savedir)
        $repoWorkDir = $this->getConf('repoWorkDir');
        if (!empty($repoWorkDir)) {
            $repoWorkDir = GitBackedUtil::getEffectivePath($repoWorkDir);
        }
        Git::set_bin(empty($repoWorkDir) ? Git::get_bin() : Git::get_bin().' --work-tree '.escapeshellarg($repoWorkDir));
        $params = str_replace(
            array('%mail%','%user%'),
            array($this->getAuthorMail(),$this->getAuthor()),
            $this->getConf('addParams'));
        if ($params) {
            Git::set_bin(Git::get_bin().' '.$params);
        }
        return $repo;
    }

	private function isIgnored($filePath) {
		$ignore = false;
		$ignorePaths = trim($this->getConf('ignorePaths'));
		if ($ignorePaths !== '') {
			$paths = explode(',',$ignorePaths);
			foreach($paths as $path) {
				if (strstr($filePath,$path)) {
					$ignore = true;
				}
			}
		}
		return $ignore;
	}

    private function commitFile($filePath,$message) {
		if (!$this->isIgnored($filePath)) {
			try {
				$repo = $this->initRepo();

				//add the changed file and set the commit message
				$repo->add($filePath);
				$repo->commit($message);

				//if the push after Commit option is set we push the active branch to origin
				if ($this->getConf('pushAfterCommit')) {
					$repo->push('origin',$repo->active_branch());
				}
			} catch (Exception $e) {
				if (!$this->isNotifyByEmailOnGitCommandError()) {
					throw new Exception('Git committing or pushing failed: '.$e->getMessage(), 1, $e);
				}
				return;
			}
		}
    }

    private function getAuthor() {
        return $GLOBALS['USERINFO']['name'];
    }

    private function getAuthorMail() {
        return $GLOBALS['USERINFO']['mail'];
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
				try {
                	$repo = $this->initRepo();

                	//execute the pull request
                	$repo->pull('origin',$repo->active_branch());
				} catch (Exception $e) {
					if (!$this->isNotifyByEmailOnGitCommandError()) {
						throw new Exception('Git command failed to perform periodic pull: '.$e->getMessage(), 2, $e);
					}
					return;
				}

                //save the current time to the file to track the last pull execution
                file_put_contents($lastPullFile,serialize(time()));
            }
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
	public function notify_create_new_error($repo_path, $reference, $error_message) {
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
	public function notify_repo_path_error($repo_path, $error_message) {
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
	public function notify_command_error($repo_path, $cwd, $command, $status, $error_message) {
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
	public function notify_command_success($repo_path, $cwd, $command) {
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
	public function notifyByMail($subject_id, $template_id, $template_replacements) {
		$ret = false;
		dbglog("GitBacked - notifyByMail: [subject_id=".$subject_id.", template_id=".$template_id.", template_replacements=".$template_replacements."]");
		if (!$this->isNotifyByEmailOnGitCommandError()) {
			return $ret;
		}	
		//$template_text = rawLocale($template_id); // this works for core artifacts only - not for plugins
		$template_filename = $this->localFN($template_id);
        $template_text = file_get_contents($template_filename);
		$template_html = $this->render_text($template_text);

		$mailer = new \Mailer();
		$mailer->to($this->getEmailAddressOnErrorConfigured());
		dbglog("GitBacked - lang check['".$subject_id."']: ".$this->getLang($subject_id));
		dbglog("GitBacked - template text['".$template_id."']: ".$template_text);
		dbglog("GitBacked - template html['".$template_id."']: ".$template_html);
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
	public function isNotifyByEmailOnGitCommandError() {
		$emailAddressOnError = $this->getEmailAddressOnErrorConfigured();
		return !empty($emailAddressOnError);
	}
	
	/**
	 * Get the eMail address configured for notifications.
	 *
	 * @access  public
	 * @return  string
	 */
	public function getEmailAddressOnErrorConfigured() {
		$emailAddressOnError = trim($this->getConf('emailAddressOnError'));
		return $emailAddressOnError;
	}

}

// vim:ts=4:sw=4:et:
