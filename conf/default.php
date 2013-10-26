<?php
/**
 * Default settings for the gitbacked plugin
 *
 * @author Wolfgang Gassler <wolfgang@gassler.org>
 */

$conf['pushAfterCommit'] = 0;
$conf['periodicPull'] = 0;
$conf['periodicMinutes'] = 60;
$conf['commitPageMsg'] = 'Wiki page %page% changed with summary [%summary%] by %user%';
$conf['commitPageMsgDel'] = 'Wiki page %page% deleted with reason [%summary%] by %user%';
$conf['commitMediaMsg'] = 'Wiki media %media% uploaded by %user%';
$conf['commitMediaMsgDel'] = 'Wiki media %media% deleted by %user%';
$conf['importMetaMsg'] = 'git importer: updated meta and image_meta from wiki';
$conf['backupSuffix'] = '.bak';
$conf['gitPath'] = '/usr/bin/git';
$conf['repoPath'] = $GLOBALS['conf']['savedir'];
$conf['repoWorkDir'] = $GLOBALS['conf']['savedir'];
$conf['gitBranch'] = '';
$conf['addParams'] = '';
