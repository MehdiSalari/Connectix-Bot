<?php
// if ok = true => update success
 if (!isset($_GET['ok']) || $_GET['ok'] === 'false') {
    header('Location: ../index.php');
 }
// Check if zip extension is enabled
if (!extension_loaded('zip')) {
    die('Zip extension is not enabled');
}

// Get bot files from github
$zipUrl = 'https://github.com/MehdiSalari/Connectix-Bot/archive/refs/heads/main.zip';
$zipFile = 'main.zip';

// Download the zip file
$isUpdateGet = file_put_contents($zipFile, file_get_contents($zipUrl));

// Check if update downloaded
if (!$isUpdateGet) {
    header('Location: ../index.php?updated=false');
    exit;
}

// Extract bot files
$zip = new ZipArchive;
if ($zip->open($zipFile) === true) {
    $zip->extractTo('.');
    $zip->close();
}

// Delete zip file
unlink($zipFile);

function mergeCopy($src, $dst) {
    if (!is_dir($src)) return;

    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }

    $files = scandir($src);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;

        $srcPath = $src . '/' . $file;
        $dstPath = $dst . '/' . $file;

        if (is_dir($srcPath)) {
            mergeCopy($srcPath, $dstPath);
        } else {
            copy($srcPath, $dstPath); // overwrite only this file
        }
    }
}



$extractedDir = 'Connectix-Bot-main';
$targetDir    = '../';

if (is_dir($extractedDir)) {

    mergeCopy($extractedDir, $targetDir);

    // Remove git temp folder
    function deleteTempDir($dir) {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $path = $dir . '/' . $file;
                is_dir($path) ? deleteTempDir($path) : unlink($path);
            }
        }
        rmdir($dir);
    }

    deleteTempDir($extractedDir);
}

// Redirect to index page
header('Location: ../index.php?updated=true');
exit;

