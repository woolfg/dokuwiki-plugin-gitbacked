<?php
/**
 * Default settings for the gitbacked plugin
 *
 * @author Wolfgang Gassler <wolfgang@gassler.org>
 */

$conf['initRepo'] = 1;
$conf['pushAfterCommit'] = 0;
$conf['periodicPull'] = 0;
$conf['periodicMinutes'] = 60;
$conf['commitPageMsg']	= 'Wiki page %page% changed with summary [%summary%] by %user%';
$conf['commitPageMsgDel']	= 'Wiki page %page% deleted with reason [%summary%] by %user%';
$conf['commitMediaMsg']	= 'Wiki media %media% uploaded by %user%';
$conf['commitMediaMsgDel']	= 'Wiki media %media% deleted by %user%';
$conf['repoPath'] = $GLOBALS['conf']['savedir'];
$conf['repoWorkDir'] = '';
$conf['gitPath'] = '';
$conf['addParams'] = '-c user.name="%user%" -c user.email="<%mail%>"';
$conf['ignorePaths'] = '';
$conf['emailAddressOnError'] = '';
$conf['notifyByMailOnSuccess'] = 0;
