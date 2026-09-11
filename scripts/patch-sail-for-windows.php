<?php

// Laravel Sail's bash script only recognizes Linux/macOS uname output, so it
// refuses to run under Git Bash / MSYS on native Windows (no WSL2 distro in
// this project's dev setup). Patch in support for that shell each time
// composer reinstalls the package, since vendor/ is regenerated from scratch.

$file = __DIR__.'/../vendor/laravel/sail/bin/sail';

if (! is_file($file)) {
    return;
}

$contents = file_get_contents($file);

if (str_contains($contents, 'MINGW')) {
    return;
}

$contents = str_replace(
    "Darwin*)            MACHINE=mac;;\n",
    "Darwin*)            MACHINE=mac;;\n    MINGW*|MSYS*|CYGWIN*) MACHINE=linux;;\n",
    $contents
);

file_put_contents($file, $contents);
