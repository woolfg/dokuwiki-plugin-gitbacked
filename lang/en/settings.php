<?php
/**
 * English strings for the gitbacked plugin
 *
 * @author Wolfgang Gassler <wolfgang@gassler.org>
 */
$lang['autoCommit'] = 'Commit to the git repo on every edit or media upload in the wiki';
$lang['pushAfterCommit'] = 'Push active branch to remote origin after every commit';
$lang['periodicPull'] = 'Pull the remote git repository every "periodicMinutes" triggered by a http page request';
$lang['periodicMinutes'] = 'Timespan (in minutes) between periodic pull requests';
$lang['commitPageMsg'] = 'Commit message for page edits (%user%,%summary%,%page% are replaced by the corresponding values)';
$lang['commitPageMsgDel'] = 'Commit message for deleted pages (%user%,%summary%,%page% are replaced by the corresponding values)';
$lang['commitMediaMsg'] = 'Commit message for media files (%user%,%media% are replaced by the corresponding values)';
$lang['commitMediaMsgDel'] = 'Commit message for deleted media files (%user%,%media% are replaced by the corresponding values)';
$lang['importMetaMsg'] = 'Commit message for meta update (git importer)';
$lang['backupSuffix'] = 'Suffix of backup folders (to store hidden data or history).';
$lang['gitPath'] = 'Full path of the git program';
$lang['repoPath'] = 'Path of the git repo, relative to dokuwiki directory.';
$lang['repoBase'] = 'Subdirectory from repoPath to dataBase. A data file will be located at [Dokuwiki]/[repoPath]/[repoBase]/{[file]-[dataBase]} in the git repo.';
$lang['dataBase'] = 'Prefix that data files are to be based (stripped). Make sure it is an existing directory covering (shorter than) dokuwiki pages, meta, media, and media_meta, or they\'ll be messed up on commit or import and cannot be exported correctly.';
$lang['gitBranch'] = 'Specify a branch to commit, use current branch if left blank';
$lang['addParams'] = 'Additional git parameters (added to the git execution command)';
