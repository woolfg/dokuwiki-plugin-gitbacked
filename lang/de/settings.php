<?php

/**
 * German strings for the gitbacked plugin
 *
 * @author Markus Hoffrogge <mhoffrogge@gmail.com>
 */

$lang['autoDetermineRepos'] = 'Erkennt existierende git repo(s) in dem Pfad der geänderten Datei. Wenn gesetzt, dann werden die Einstellungen <code>repoPath</code> und <code>repoWorkDir</code> ignoriert.';
$lang['pushAfterCommit'] = 'Push des aktiven Branch zum remote origin nach jedem commit';
$lang['periodicPull'] = 'Pull des remote git Repositories alle "periodicMinutes", getriggert von einem http Page Request';
$lang['periodicMinutes'] = 'Zeitraum (in Minuten) zwischen den periodischen pull requests';
$lang['commitPageMsg']	= 'Commit Kommentar für Seitenänderungen (%user%,%summary%,%page% werden durch die tatsächlichen Werte ersetzt)';
$lang['commitPageMsgDel']	= 'Commit Kommentar für gelöschte Seiten (%user%,%summary%,%page% werden durch die tatsächlichen Werte ersetzt)';
$lang['commitMediaMsg']	= 'Commit Kommentar for media Dateien (%user%,%media% werden durch die tatsächlichen Werte ersetzt)';
$lang['commitMediaMsgDel']	= 'Commit Kommentar für gelöschte media Dateien (%user%,%media% werden durch die tatsächlichen Werte ersetzt)';
$lang['repoPath']	= 'Pfad des git repo (z.B. das <code>savedir</code> '.$GLOBALS['conf']['savedir'].')';
$lang['repoWorkDir']	= 'Pfad des git working tree. Dieser muss die "pages" and "media" Verzeichnisse enthalten (z.B. das <code>savedir</code> '.$GLOBALS['conf']['savedir'].')';
$lang['gitPath'] = 'Pfad zum git binary (Wenn leer, dann wird der Standard "/usr/bin/git" verwendet)';
$lang['addParams'] = 'Zusätzliche git Parameter (diese werden dem git Kommando zugefügt) (%user% und %mail% werden durch die tatsächlichen Werte ersetzt)';
$lang['ignorePaths'] = 'Pfade/Dateien die ignoriert werden und nicht von git archiviert werden sollen (durch Kommata getrennt)';
$lang['emailAddressOnError'] = 'Wenn definiert, dann wird bei einem Fehler eine eMail an diese Adresse(n) gesendet, anstatt den aktuellen Endanwender mit einer Exception zu verunsichern. Mehrere Adressen können durch Kommata getrennt konfiguriert werden';
$lang['notifyByMailOnSuccess'] = 'Wenn <code>emailAddressOnError</code> definiert ist, dann wird bei jedem Commit eine eMail gesendet. Diese Einstellung sollte nur zum Testen der eMail Benachrichtigung aktiviert werden';
