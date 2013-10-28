<?php
/**
 * Options for the gitbacked plugin
 *
 * @author Wolfgang Gassler <wolfgang@gassler.org>
 */
$meta['autoCommit'] = array('onoff');
$meta['pushAfterCommit'] = array('onoff');
$meta['periodicPull'] = array('onoff');
$meta['periodicMinutes'] = array('numeric');
$meta['commitPageMsg'] = array('string');
$meta['commitPageMsgDel'] = array('string');
$meta['commitMediaMsg'] = array('string');
$meta['commitMediaMsgDel'] = array('string');
$meta['importMetaMsg'] = array('string');
$meta['backupSuffix'] = array('string');
$meta['gitPath'] = array('string');
$meta['repoPath'] = array('string');
$meta['repoBase'] = array('string');
$meta['dataBase'] = array('string');
$meta['gitBranch'] = array('string');
$meta['addParams'] = array('string');
