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

    public function register(Doku_Event_Handler $controller) {

        $controller->register_hook('IO_WIKIPAGE_WRITE', 'AFTER', $this, 'handle_io_wikipage_write');
        $controller->register_hook('MEDIA_UPLOAD_FINISH', 'AFTER', $this, 'handle_media_upload');
        $controller->register_hook('MEDIA_DELETE_FILE', 'AFTER', $this, 'handle_media_deletion');
        $controller->register_hook('DOKUWIKI_DONE', 'AFTER', $this, 'handle_periodic_pull');
		$controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'handle_code_or_config_on_start');
		$controller->register_hook('DOKUWIKI_DONE', 'AFTER', $this, 'handle_code_or_config_on_done', null, 10);
		$controller->register_hook('AJAX_CALL_UNKNOWN', 'AFTER', $this, 'handle_code_or_config_on_ajax');

    }

    private function initRepo($repoPathConfigKey, $repoWorkDirConfigKey) {
        //get path to the repo root (by default DokuWiki's savedir)
		$configuredRepoPath = trim($this->getConf($repoPathConfigKey));
		if (empty($configuredRepoPath)) {
			return null;
		}
        if(defined('DOKU_FARM')) {
            $repoPath = $configuredRepoPath;
        } else {
            $repoPath = DOKU_INC.$configuredRepoPath;
        }
        //set the path to the git binary
        $gitPath = trim($this->getConf('gitPath'));
        if ($gitPath !== '') {
            Git::set_bin($gitPath);
        }
        //init the repo and create a new one if it is not present
        io_mkdir_p($repoPath);
        $repo = new GitRepo($repoPath, $this, true, true);
        //set git working directory (by default DokuWiki's savedir)
		if (!empty($repoWorkDirConfigKey)) {
			$configuredRepoWorkDir = trim($this->getConf($repoWorkDirConfigKey));
			if (!empty($configuredRepoWorkDir)) {
			$repoWorkDir = DOKU_INC.$configuredRepoWorkDir;
			Git::set_bin(Git::get_bin().' --work-tree '.escapeshellarg($repoWorkDir));
			}
		}

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

	private function pullRepo($repoPathConfigKey,$repoWorkDirConfigKey) {
		try {
			$repo = $this->initRepo($repoPathConfigKey,$repoWorkDirConfigKey);
			if (is_null($repo)) {
				return;
			}
			//execute the pull request
			$repo->pull('origin',$repo->active_branch());
		} catch (Exception $e) {
			if (!$this->isNotifyByEmailOnGitCommandError()) {
				throw new Exception('Git command failed to perform pull: '.$e->getMessage(), 2, $e);
			}
			return;
		}
	}

    private function commitFile($repoPathConfigKey,$repoWorkDirConfigKey,$filePath,$message) {
		if (!$this->isIgnored($filePath)) {
			try {
				$repo = $this->initRepo($repoPathConfigKey,$repoWorkDirConfigKey);
				if (is_null($repo)) {
					return;
				}
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

    private function commitAll($repoPathConfigKey,$repoWorkDirConfigKey,$message) {
		try {
			$repo = $this->initRepo($repoPathConfigKey,$repoWorkDirConfigKey);
			if (is_null($repo)) {
				return;
			}
			$gitStatus = $repo->status(false, '-s');
			dbglog("GitBacked - commitAll[".$repoPathConfigKey."] - status BEFORE: (".strlen($gitStatus).") [".$gitStatus."]");
			if (empty($gitStatus)) {
				return;
			}	
			$repo->addAll();
			dbglog("GitBacked - commitAll[".$repoPathConfigKey."] - AFTER addAll()");
			$repo->commit($message);
			dbglog("GitBacked - commitAll[".$repoPathConfigKey."] - AFTER commit");
			$gitStatus = $repo->status(false, '-s');
			dbglog("GitBacked - commitAll[".$repoPathConfigKey."] - status AFTER: (".strlen($gitStatus).") [".$gitStatus."]");

			//if the push after Commit option is set we push the active branch to origin
			if ($this->getConf('pushAfterCommit')) {
				$repo->push('origin',$repo->active_branch());
			}
		} catch (Exception $e) {
			if (!$this->isNotifyByEmailOnGitCommandError()) {
				throw new Exception('Git committing or pushing failed: '.$e->getMessage(), 1, $e);
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
				$this->pullRepo('repoPath', 'repoWorkDir');
				$this->pullRepo('repoPathMedia', 'repoWorkDirMedia');
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
		
		if (!empty($this->getConf('repoPathMedia'))) {
	        $this->commitFile('repoPathMedia','repoWorkDirMedia',$mediaPath,$message);
		} else {
			$this->commitFile('repoPath','repoWorkDir',$mediaPath,$message);
		}

	}

    public function handle_media_upload(Doku_Event &$event, $param) {

        $mediaPath = $event->data[1];
        $mediaName = $event->data[2];

        $message = str_replace(
            array('%media%','%user%'),
            array($mediaName,$this->getAuthor()),
            $this->getConf('commitMediaMsg')
        );

		if (!empty($this->getConf('repoPathMedia'))) {
	        $this->commitFile('repoPathMedia','repoWorkDirMedia',$mediaPath,$message);
		} else {
			$this->commitFile('repoPath','repoWorkDir',$mediaPath,$message);
		}

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

            $this->commitFile('repoPath','repoWorkDir',$pagePath,$message);

        }
    }

    public function handle_code_or_config_on_start(Doku_Event &$event, $param) {

		global $INPUT;

		$message = '';
		$isPluginChanged = false;

		dbglog("GitBacked - handle_code_or_config_on_start - event=['".$event."'], data=['".$event->data."'], page='".$INPUT->str('page')."', save=".$INPUT->bool('save').", arr('config')=[".$INPUT->arr('config')."]");
		// configuration manager
        if ($INPUT->str('page') === 'config'
			&& $INPUT->str('do') === 'admin'
            && $INPUT->bool('save') === true
            && !empty($INPUT->arr('config'))
        ) {
        	//$this->logAdmin(['save config']);
			$message = $this->getAuthor().' changed config';
			dbglog("GitBacked - handle_code_or_config_on_start - config change['".$message."']");
		}

        // extension manager
        if ($INPUT->str('page') === 'extension') {
            if ($INPUT->post->has('fn')) {
				$aChangedExtensions = array();
                $actions = $INPUT->post->arr('fn');
                foreach ($actions as $action => $extensions) {
                    foreach ($extensions as $extname => $label) {
                        //$this->logAdmin([$action, $extname]);
						$changedExtension = $action."['".$extname."']";
						array_push($aChangedExtensions, $changedExtension);
						dbglog("GitBacked - handle_code_or_config_on_start - action[extension] = ".$changedExtension);
					}
                }
			    $isPluginChanged = true;
				$message = $this->getAuthor().' changed plugins: '.implode (', ', $aChangedExtensions);
				dbglog("GitBacked - handle_code_or_config_on_start - EXTENSION change['".$message."']");
            } elseif ($INPUT->post->str('installurl')) {
                //$this->logAdmin(['installurl', $INPUT->post->str('installurl')]);
			    $isPluginChanged = true;
 				$message = $this->getAuthor().' installed plugin by URL: '.$INPUT->post->str('installurl');
				dbglog("GitBacked - handle_code_or_config_on_start - PLUGIN_URL change['".$message."']");
            } elseif (isset($_FILES['installfile'])) {
				//$this->logAdmin(['installfile', $_FILES['installfile']['name']]);
			    $isPluginChanged = true;
	 			$message = $this->getAuthor().' installed plugin by file: '.$_FILES['installfile']['name'];
				dbglog("GitBacked - handle_code_or_config_on_start - PLUGIN_FILE change['".$message."']");
            }
        }

        // ACL manager
        if ($INPUT->str('page') === 'acl' && $INPUT->has('cmd')) {
            $cmd = $INPUT->extract('cmd')->str('cmd');
            $del = $INPUT->arr('del');
            if ($cmd === 'update' && !empty($del)) {
                $cmd = 'delete';
                $rule = $del;
            } else {
                $rule = [
                    'ns' => $INPUT->str('ns'),
                    'acl_t' => $INPUT->str('acl_t'),
                    'acl_w' => $INPUT->str('acl_w'),
                    'acl' => $INPUT->str('acl')
                ];
            }

            //$this->logAdmin([$cmd, $rule]);
 			$message = $this->getAuthor().' changed ACLs';
			dbglog("GitBacked - handle_code_or_config_on_start - ACL change['".$message."']");
        }

		if (!empty($message)) {
			$confOrCodeChangeMessageFile = $this->temp_dir.'/confOrCodeChangeMessage.txt';
			file_put_contents($confOrCodeChangeMessageFile,serialize($message));
		}
		if ($isPluginChanged == true) {
			$isPluginChangedFile = $this->temp_dir.'/isPluginChanged.txt';
			file_put_contents($isPluginChangedFile,serialize($isPluginChanged));
		}

	}

    public function handle_code_or_config_on_done(Doku_Event &$event, $param) {
		global $INPUT;

		dbglog("GitBacked - handle_code_or_config_on_done - event=['".$event."'], data=['".$event->data."'], page='".$INPUT->str('page')."', save=".$INPUT->bool('save').", arr('config')=[".$INPUT->arr('config')."]");

		$message = '';
		$confOrCodeChangeMessageFile = $this->temp_dir.'/confOrCodeChangeMessage.txt';
        if (is_file($confOrCodeChangeMessageFile)) {
            $message = unserialize(file_get_contents($confOrCodeChangeMessageFile));
		}
		$isPluginChangedFile = $this->temp_dir.'/isPluginChanged.txt';
		$isPluginChanged = is_file($isPluginChangedFile);
		if ($isPluginChanged == true) {
			dbglog("GitBacked - commitAll CODE['".$message."']");
			$this->commitAll('repoPathCode',null,$message);
			@unlink($isPluginChangedFile);
		}
		if (!empty($message)) {
			dbglog("GitBacked - commitAll CONF['".$message."']");
			$this->commitAll('repoPathConf',null,$message);
			@unlink($confOrCodeChangeMessageFile);
		}

	}

    /**
     * Catch admin actions performed via Ajax
     *
     * @param Doku_Event $event
     */
    public function handle_code_or_config_on_ajax(Doku_Event &$event, $param) {
        global $INPUT;

		dbglog("GitBacked - handle_code_or_config_on_ajax - event=['".$event."'], data=['".$event->data."'], page='".$INPUT->str('page')."'");

		// extension manager
        if ($event->data === 'plugin_extension') {
            //$this->logAdmin([$INPUT->str('act') . ' ' . $INPUT->str('ext')], 'extension');
			$message = $this->getAuthor().' '.$INPUT->str('act').' plugin '.$INPUT->str('ext');
			$this->commitAll('repoPathCode',null,$message);
			$this->commitAll('repoPathConf',null,$message);
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
		//dbglog("GitBacked - notifyByMail: [subject_id=".$subject_id.", template_id=".$template_id.", template_replacements=".$template_replacements."]");
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
