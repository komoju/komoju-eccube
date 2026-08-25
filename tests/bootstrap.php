<?php

require_once __DIR__ . '/Stubs/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Register test stubs (Doctrine/Eccube/Symfony interfaces) that are not
// available outside a full EC-CUBE installation.  These must NOT be
// registered via composer.json classmap because EC-CUBE's plugin upload
// process scans that section and the duplicate class names cause fatals.
$stubDir = __DIR__ . '/Stubs';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stubDir, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file->getExtension() === 'php' && $file->getBasename() !== 'helpers.php') {
        require_once $file->getPathname();
    }
}
