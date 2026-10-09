<?php

/*
 * The checkout's own src/ and tests/ win over the autoloader's mapping, so a git worktree that shares vendor/ through a
 * symlink tests its own code.
 */
$loader = require __DIR__.'/../vendor/autoload.php';
$loader->setPsr4('Sidetower\\Laravel\\', [__DIR__.'/../src']);
$loader->setPsr4('Sidetower\\Laravel\\Tests\\', [__DIR__]);
