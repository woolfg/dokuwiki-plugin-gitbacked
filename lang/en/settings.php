<?php

/**
 * English strings for the gitbacked plugin
 *
 * @author Wolfgang Gassler <wolfgang@gassler.org>
 */

$lang['pushAfterCommit'] = 'Push active branch to remote origin after every commit';
$lang['periodicPull'] = 'Pull the remote git repository every "periodicMinutes" triggered by a http page request';
$lang['periodicMinutes'] = 'Timespan (in minutes) between periodic pull requests';
$lang['commitPageMsg'] = 'Commit message for page edits (%user%,%summary%,%page% are replaced by the corresponding values)';
$lang['commitPageMsgDel'] = 'Commit message for deleted pages (%user%,%summary%,%page% are replaced by the corresponding values)';
$lang['commitMediaMsg']	= 'Commit message for media files (%user%,%media% are replaced by the corresponding values)';
$lang['commitMediaMsgDel'] = 'Commit message for deleted media files (%user%,%media% are replaced by the corresponding values)';
$lang['autoDetermineRepos'] = 'Auto determine the next git repo path upwards from the path of the file to commit. If enabled, then multiple repos e.g. within namespaces or separate repos for pages and media are supported in a generic way.';
$lang['repoPath'] = '<b>Legacy config:</b> Path of the git repo (e.g. the <code>savedir</code> <code>$GLOBALS[\'conf\'][\'savedir\']</code>)<br><b>NOTE:</b> This config is for backward compatibility of an existing configuration only. If <code>autoDetermineRepos</code> is on, then this config should not be set for new installations.';
$lang['repoWorkDir'] = '<b>Legacy config:</b> Path of the git working tree, must contain "pages" and "media" directories (e.g. the <code>savedir</code> <code>$GLOBALS[\'conf\'][\'savedir\']</code>)<br><b>NOTE:</b> This config is considered only, if <code>repoPath</code> is set. In this case it does apply for the repo in <code>repoPath</code> only.';
$lang['gitPath'] = 'Path to the git binary (if empty, the default "/usr/bin/git" will be used)';
$lang['addParams'] = 'Additional git parameters (added to the git execution command) (%user% and %mail% are replaced by the corresponding values)';
$lang['ignorePaths'] = 'Paths/files which are ignored and not added to git (comma-separated)';
$lang['emailAddressOnError'] = 'If set, in case of a git error an eMail will be sent to this address rather than confusing the end user by the Exception raised. Multiple mail addresses can be configured comma separated';
$lang['notifyByMailOnSuccess'] = 'If <code>emailAddressOnError</code> is defined, an eMail will be sent on any git commit. This is supposed to be used for eMail notification test purposes only';
