<?php
/**
 * Options for the gitbacked plugin
 *
 * @author Wolfgang Gassler <wolfgang@gassler.org>
 */

$meta['initRepo'] = array('onoff');
$meta['pushAfterCommit'] = array('onoff');
$meta['periodicPull'] = array('onoff');
$meta['periodicMinutes'] = array('numeric');
$meta['commitPageMsg'] = array('string');
$meta['commitPageMsgDel'] = array('string');
$meta['commitMediaMsg'] = array('string');
$meta['commitMediaMsgDel'] = array('string');
$meta['repoPath'] = array('string');
$meta['repoWorkDir'] = array('string');
$meta['gitPath'] = array('string');
$meta['addParams'] = array('string');
$meta['ignorePaths'] = array('string');
$meta['emailAddressOnError'] = array('string');
$meta['notifyByMailOnSuccess'] = array('onoff');
