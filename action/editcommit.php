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

			$authorName = $GLOBALS['USERINFO']['name'];
			$editSummary = $GLOBALS['INFO']['meta']['last_change']['sum'];

			$message = str_replace(
				array('%page%','%summary%','%user%'),
				array($pageName,$editSummary,$authorName),
				$this->getConf('commitMsg')
			);

			//get path to the repo root (by default DokuWiki's savedir)
			$repoPath = $this->getConf('repoPath');
			
			//init the repo and create a new one if it is not present
			$repo = new GitRepo($repoPath, true, true);

			$params = $this->getConf('addParams');
			if ($params) {
				$repo->git_path .= ' '.$params;
			}
			
			//add the changed file and set the commit message
			$repo->add($pagePath);
			$repo->commit($message);

			//if the push after Commit option is set we push the active branch to origin
			if ($this->getConf('pushAfterCommit')) {
				$repo->push('origin',$repo->active_branch());
			}

		}

    }

}

// vim:ts=4:sw=4:et:
