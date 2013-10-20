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
$lang['commitMediaMsg'] = 'Commit message for media files (%user%,%media% are replaced by the corresponding values)';
$lang['commitMediaMsgDel'] = 'Commit message for deleted media files (%user%,%media% are replaced by the corresponding values)';
$lang['gitPath'] = 'Full path of the git program';
$lang['repoPath'] = 'Path of the git repo, relative to dokuwiki directory (e.g. the savedir '.$GLOBALS['conf']['savedir'].')';
$lang['repoWorkDir'] = 'Path of the git working tree, relative to dokuwiki directory, must contain "pages" and "media" directories (e.g. the savedir '.$GLOBALS['conf']['savedir'].')';
$lang['addParams'] = 'Additional git parameters (added to the git execution command)';