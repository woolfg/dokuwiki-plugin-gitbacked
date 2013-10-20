<?php
// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

/**
 * Packs history data into a string and reverse
 * to be stored in a commit message
 * for git import/export or other similar usage
 */
class helper_plugin_gitbacked_histmeta extends DokuWiki_Plugin {

    /**
     * Packs history data into a string
     *
     * Format:
     *     <commit message>
     *
     *     ~~dokuwiki~~
     *     <log key>: <log value>
     *     cmd: <command>
     *     ......
     *
     * @param   string  the cardinal commit summary for git
     * @param   mixed   string or array, a logline of a DokuWiki .changes entry, with linefeed or not
     * @param   array   extra commands for special usage
     * @return  string  the formatted string suitable for git commit
     */
    function pack($message, $logline="", $commands=array()) {
        $packed = $message;
        if (is_string($logline)) {
            $logline = rtrim($logline, "\r\n");
            $logline = explode("\t", $logline);
        }
        $loglineempty = true;
        foreach($logline as $item) {
            if (!empty($item)) {
                $loglineempty = false;
                break;
            }
        }
        if ($loglineempty) $logline = null;
        if (!empty($logline) || !empty($commands)) {
            $packed .= "\n\n~~dokuwiki~~\n";
            if (!empty($logline)) {
                $packed .= "time    : $logline[0]\n";
                $packed .= "ip      : $logline[1]\n";
                $packed .= "action  : $logline[2]\n";
                $packed .= "id      : $logline[3]\n";
                $packed .= "user    : $logline[4]\n";
                $packed .= "summary : $logline[5]\n";
                $packed .= "extra   : $logline[6]\n";
            }
            if (!empty($commands)) {
                foreach($commands as $cmd) {
                    $packed .= "cmd: $cmd\n";
                }
            }
        }
        return $packed;
    }

    /**
     * Unpacks a packed commit message into source data
     *
     * @param   string  the packed message
     * @return  array   the source (string $message, array $logline, array $commands)
     */
    function unpack($packed) {
        $packed = str_replace( array("\r\n", "\r"), "\n", $packed );
        preg_match( "#^((?:.|\n)*?)(?:\n\n~~dokuwiki~~\n(?:".
            "time    : ?(.*)\n".
            "ip      : ?(.*)\n".
            "action  : ?(.*)\n".
            "id      : ?(.*)\n".
            "user    : ?(.*)\n".
            "summary : ?(.*)\n".
            "extra   : ?(.*)\n".
            ")?((?:cmd: ?.*\n)*))?$#u", $packed, $matches );
        // message
        $message = $matches[1];
        // has logline
        if ($matches[2]) {
            $logline = array_slice($matches, 2, 7);
        }
        // has command
        if ($matches[9]) {
            preg_match_all( "#^cmd: ?(.*)$#um", $matches[9], $matches );
            $commands = $matches[1];
        }
        return array($message, $logline, $commands);
    }
}
