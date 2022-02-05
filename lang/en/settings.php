<?php

/**
 * English strings for the gitbacked plugin
 *
 * @author Wolfgang Gassler <wolfgang@gassler.org>
 */

$lang['autoDetermineRepos'] = 'Determine existing git repo(s) from the path of the file to commit. If set, then the configs <code>repoPath</code> and <code>repoWorkDir</code> are ignored.';
$lang['pushAfterCommit'] = 'Push active branch to remote origin after every commit';
$lang['periodicPull'] = 'Pull the remote git repository every "periodicMinutes" triggered by a http page request';
$lang['periodicMinutes'] = 'Timespan (in minutes) between periodic pull requests';
$lang['commitPageMsg']	= 'Commit message for page edits (%user%,%summary%,%page% are replaced by the corresponding values)';
$lang['commitPageMsgDel']	= 'Commit message for deleted pages (%user%,%summary%,%page% are replaced by the corresponding values)';
$lang['commitMediaMsg']	= 'Commit message for media files (%user%,%media% are replaced by the corresponding values)';
$lang['commitMediaMsgDel']	= 'Commit message for deleted media files (%user%,%media% are replaced by the corresponding values)';
$lang['repoPath']	= 'Path of the git repo(s) (e.g. the savedir '.$GLOBALS['conf']['savedir'].')';
$lang['repoWorkDir']	= 'Path of the git working tree, must contain "pages" and "media" directories (e.g. the savedir '.$GLOBALS['conf']['savedir'].')';
$lang['gitPath'] = 'Path to the git binary (if empty, the default "/usr/bin/git" will be used)';
$lang['addParams'] = 'Additional git parameters (added to the git execution command) (%user% and %mail% are replaced by the corresponding values)';
$lang['ignorePaths'] = 'Paths/files which are ignored and not added to git (comma-separated)';
$lang['emailAddressOnError'] = 'If set, in case of a git error an eMail will be sent to this address rather than confusing the end user by the Exception raised. Multiple mail addresses can be configured comma separated';
$lang['notifyByMailOnSuccess'] = 'If <code>emailAddressOnError</code> is defined, an eMail will be sent on any git commit. This is supposed to be used for eMail notification test purposes only';
