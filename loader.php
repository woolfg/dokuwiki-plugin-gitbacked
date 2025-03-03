<?php

spl_autoload_register(
    /**
     * Simple PSR-4 autoloader
     *
     * Based on PSR-4 example code
     *
     * @link http://www.php-fig.org/psr/psr-4/examples/
     * @param string $class The fully-qualified class name.
     * @return void
     */
    function ($class) {
        $namespaces = array(
            'woolfg\\dokuwiki\\plugin\\gitbacked\\' => __DIR__ . '/classes/'
        );

        foreach ($namespaces as $prefix => $base_dir) {
            // does the class use the namespace prefix?
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                // no, move to the next
                continue;
            }

            // get the relative class name
            $relative_class = substr($class, $len);

            // replace the namespace prefix with the base directory, replace namespace
            // separators with directory separators in the relative class name, append
            // with .php
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

            // if the file exists, require it
            if (file_exists($file)) {
                require $file;
            }
        }
    }
);
