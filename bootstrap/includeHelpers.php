<?php

// Deployments can retain an optimized Composer class map across a source update.
// Load this application trait directly so models using it remain bootable until
// Composer's autoload files are regenerated.
require_once __DIR__.'/../app/Traits/Auditable.php';

$files = glob(__DIR__.'/helpers/*.php');
foreach ($files as $file) {
    require $file;
}
