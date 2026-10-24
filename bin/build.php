<?php
/**
 * Builds dist/infosub.zip, ready for Plugins -> Add New -> Upload Plugin.
 *
 * Run through Composer so vendor/ is a fresh, prefixed, no-dev install first:
 *
 *     composer build
 *
 * Everything in the plugin folder goes into the zip under "infosub/", except what
 * .distignore lists.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$slug = 'infosub';

if (!preg_match('/^[ \t\/*#@]*Version:\s*(\S+)/mi', (string) file_get_contents("$root/$slug.php"), $m)) {
    fwrite(STDERR, "No Version header in $slug.php.\n");
    exit(1);
}
$version = $m[1];

foreach (array('vendor/autoload.php', 'vendor/firebase/php-jwt/src/JWT.php') as $required) {
    if (!is_file("$root/$required")) {
        fwrite(STDERR, "Missing $required: run \"composer build\", not this script directly.\n");
        exit(1);
    }
}

$patterns = array();
if (is_file("$root/.distignore")) {
    foreach (file("$root/.distignore", FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#') {
            $patterns[] = trim($line, '/');
        }
    }
}

$ignored = function ($rel) use ($patterns) {
    foreach ($patterns as $pattern) {
        if (strpos($pattern, '/') === false) {
            foreach (explode('/', $rel) as $segment) {
                if (fnmatch($pattern, $segment)) {
                    return true;
                }
            }
        } elseif (fnmatch($pattern, $rel) || strpos($rel, $pattern . '/') === 0) {
            return true;
        }
    }
    return false;
};

if (!is_dir("$root/dist") && !mkdir("$root/dist", 0777, true)) {
    fwrite(STDERR, "Could not create dist/.\n");
    exit(1);
}
$target = "$root/dist/$slug.zip";
if (is_file($target)) {
    unlink($target);
}

$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Could not create $target.\n");
    exit(1);
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
$count = 0;
foreach ($files as $file) {
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if ($ignored($rel)) {
        continue;
    }
    if ($file->isDir()) {
        $zip->addEmptyDir("$slug/$rel");
    } else {
        $zip->addFile($file->getPathname(), "$slug/$rel");
        $count++;
    }
}
$zip->close();

echo "Built dist/$slug.zip (version $version, $count files)\n";
