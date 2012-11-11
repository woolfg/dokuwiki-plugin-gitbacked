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
require_once(DOKU_PLUGIN.'gitbacked/lib/Git.php');

class action_plugin_gitbacked_editcommit extends DokuWiki_Action_Plugin {

    public function register(Doku_Event_Handler &$controller) {

		$controller->register_hook('IO_WIKIPAGE_WRITE', 'AFTER', $this, 'handle_io_wikipage_write');
		$controller->register_hook('MEDIA_UPLOAD_FINISH', 'AFTER', $this, 'handle_media_upload');
		$controller->register_hook('MEDIA_DELETE_FILE', 'AFTER', $this, 'handle_media_deletion');
    }

	private function commitFile($filePath,$message) {

		//get path to the repo root (by default DokuWiki's savedir)
		$repoPath = DOKU_INC.$this->getConf('repoPath');
		//init the repo and create a new one if it is not present
		$repo = new GitRepo($repoPath, true, true);

		$params = $this->getConf('addParams');
		if ($params) {
			$repo->git_path .= ' '.$params;
		}
		
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
			// as the metadata hasn't updated yer
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
