<?php
declare(strict_types=1);

$siteArg = $argv[1] ?? '';
$root = realpath($siteArg);
if ($root === false || !is_dir($root) || $root === DIRECTORY_SEPARATOR) {
    fwrite(STDERR, "FAIL invalid installed site root\n");
    exit(1);
}

require $root . '/vendor/autoload.php';
defined('BASE_PATH') || define('BASE_PATH', $root . DIRECTORY_SEPARATOR);
require_once $root . '/kernel/Helper.php';
require_once $root . '/app/View/User/Helper.php';

$theme = $root . '/app/View/User/Theme/Pika';
$compileDirectory = sys_get_temp_dir() . '/pika-smarty-' . getmypid();
if (!is_dir($compileDirectory) && !mkdir($compileDirectory, 0700, true) && !is_dir($compileDirectory)) {
    fwrite(STDERR, "FAIL unable to create Smarty compile directory\n");
    exit(1);
}

$expected = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($theme, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'html') {
        $expected++;
    }
}

$smarty = new Smarty();
$smarty->left_delimiter = '#{';
$smarty->right_delimiter = '}';
$smarty->setTemplateDir($theme);
$smarty->setCompileDir($compileDirectory);

ob_start();
$compiled = $smarty->compileAllTemplates('.html', true, 0, 1);
$output = (string)ob_get_clean();

if (str_contains($output, '------>Error:') || $compiled !== $expected) {
    fwrite(STDERR, "FAIL Smarty compile expected={$expected} compiled={$compiled}\n{$output}\n");
    exit(1);
}

fwrite(STDOUT, "PASS installed Pika Smarty compile templates={$compiled} smarty=" . Smarty::SMARTY_VERSION . "\n");
