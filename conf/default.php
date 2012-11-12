<?php
/**
 * Default settings for the gitbacked plugin
 *
 * @author Wolfgang Gassler <wolfgang@gassler.org>
 */

$conf['pushAfterCommit'] = 0;
$conf['periodicPull'] = 0;
$conf['periodicMinutes'] = 60;
$conf['commitPageMsg']	= '%page% changed with %summary% by %user%';
$conf['commitPageMsgDel']	= '%page% deleted %summary% by %user%';
$conf['commitMediaMsg']	= '%media% uploaded by %user%';
$conf['commitMediaMsgDel']	= '%media% deleted by %user%';
$conf['repoPath']	= $GLOBALS['conf']['savedir'];
$conf['addParams'] = '';
