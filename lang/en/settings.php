<?php

/**
 * English strings for the gitbacked plugin
 *
 * @author Wolfgang Gassler <wolfgang@gassler.org>
 * @author Robin H. Johnson <robbat2@gentoo.org>
 */

function replacementMsg($keys) {
	sort($keys);
	$s = implode(',', array_map(function($_){ return '%'.$_.'%'; }, $keys));
	$lang = array();
	$lang['_valuesReplaced'] = '(%s are replaced by the corresponding values)';
	return sprintf($lang['_valuesReplaced'], $s);
}
$commitVars = replacementMsg(array('fullpage', 'mail', 'nl', 'page', 'pagens', 'summary', 'user'));
$mediaVars = replacementMsg(array('user', 'media', 'mail', 'nl'));
$coreVars = replacementMsg(array('user', 'mail'));

$lang['pushAfterCommit'] = 'Push active branch to remote origin after every commit';
$lang['periodicPull'] = 'Pull the remote git repository every "periodicMinutes" triggered by a http page request';
$lang['periodicMinutes'] = 'Timespan (in minutes) between periodic pull requests';
$lang['commitPageMsg']	= 'Commit message for page edits ' .  $commitVars;
$lang['commitPageMsgDel']	= 'Commit message for deleted pages ' .  $commitVars;
$lang['commitMediaMsg']	= 'Commit message for media files ' .  $mediaVars;
$lang['commitMediaMsgDel']	= 'Commit message for deleted media files ' .  $mediaVars;
$lang['repoPath']	= 'Path of the git repo (e.g. the savedir '.$GLOBALS['conf']['savedir'].')';
$lang['repoWorkDir']	= 'Path of the git working tree, must contain "pages" and "media" directories (e.g. the savedir '.$GLOBALS['conf']['savedir'].')';
$lang['addParams'] = 'Additional git parameters ' .  $coreVars;
$lang['envParams'] = 'Additional git environment variables (seperate variables with commas, whitespace not permitted)';
