<?php
// bin/package.php
// Usage: php bin/package.php <source-dir> <dest-zip> <distignore>

if ($argc < 4) {
    fwrite(STDERR, "Usage: php bin/package.php <source-dir> <dest-zip> <distignore>\n");
    exit(2);
}

$source = $argv[1];
$dest = $argv[2];
$distignore = $argv[3];

if (!is_dir($source)) {
    fwrite(STDERR, "Source directory not found: $source\n");
    exit(3);
}

$patterns = [];
if (is_file($distignore)) {
    $lines = file($distignore, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        $patterns[] = $line;
    }
}

// Convert distignore glob patterns to regex patterns
function glob_to_regex($glob) {
    // Remove leading slash
    $glob = preg_replace('#^/+#', '', $glob);
    $isDir = false;
    if (substr($glob, -1) === '/') {
        $isDir = true;
        $glob = substr($glob, 0, -1);
    }
    // Escape regex special chars, then convert globs
    $re = preg_quote($glob, '#');
    $re = str_replace('\*\*', '##DOUBLESTAR##', $re);
    $re = str_replace('\*', '[^/]*', $re);
    $re = str_replace('##DOUBLESTAR##', '.*', $re);
    if ($isDir) {
        // match directory prefix
        $re = '#^' . $re . '(/.*)?$#';
    } else {
        $re = '#^' . $re . '$#';
    }
    return $re;
}

$regexes = array_map('glob_to_regex', $patterns);

$zip = new ZipArchive();
if (file_exists($dest)) {
    unlink($dest);
}
if ($zip->open($dest, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Could not create zip file: $dest\n");
    exit(4);
}

$filesAdded = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($it as $file) {
    $filePath = $file->getPathname();
    $relative = str_replace('\\', '/', ltrim(substr($filePath, strlen($source)), '/\\'));
    if ($relative === '') {
        continue;
    }
    // Check patterns
    $skip = false;
    foreach ($regexes as $re) {
        if (preg_match($re, $relative)) {
            $skip = true;
            break;
        }
        // Also check against directory prefix matches
        // (already handled by glob_to_regex for trailing /)
    }
    if ($skip) {
        continue;
    }
    // Add file to zip with relative path
    $zip->addFile($filePath, $relative);
    $filesAdded++;
}

$zip->close();

fwrite(STDOUT, "Created $dest with $filesAdded files.\n");
exit(0);

