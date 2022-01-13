<?php
/**
 * Default settings for the gitbacked plugin
 *
 * @author Wolfgang Gassler <wolfgang@gassler.org>
 */

$conf['pushAfterCommit'] = 0;
$conf['periodicPull'] = 0;
$conf['periodicMinutes'] = 60;
$conf['commitPageMsg']	= 'Page UPDATE by %user% [%summary%]: %page%';
$conf['commitPageMsgDel']	= 'Page DELETE by %user% [%summary%]: %page%';
$conf['commitMediaMsg']	= 'Media UPLOAD by %user%: %media%';
$conf['commitMediaMsgDel']	= 'Media DELETE by %user%: %media%';
$conf['repoPath'] = $GLOBALS['conf']['savedir'];
$conf['repoWorkDir'] = $GLOBALS['conf']['savedir'];
$conf['repoPathMedia']= '';
$conf['repoWorkDirMedia'] = '';
$conf['repoPathConf']= '';
$conf['repoPathCode']= '';
$conf['gitPath'] = '';
$conf['addParams'] = '-c user.name="%user%" -c user.email="%mail%"';
$conf['ignorePaths'] = '';
$conf['emailAddressOnError'] = '';
$conf['notifyByMailOnSuccess'] = 0;
