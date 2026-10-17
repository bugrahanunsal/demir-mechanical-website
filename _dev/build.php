<?php
/**
 * CLI build for development and the Claude preview:
 *   php _dev/build.php --out=/path/to/dir [--preview] [--content=file.json]
 * Writes every page plus sitemap.xml and robots.txt. The live server builds the same way from the admin panel.
 */
declare(strict_types=1);
define('DM_APP', dirname(__DIR__) . '/app');
define('DM_ROOT', dirname(__DIR__));
date_default_timezone_set('America/Toronto');
require DM_APP . '/pages.php';
$o = getopt('', ['out:', 'preview', 'content:']);
$out = rtrim($o['out'] ?? '', '/');
if ($out === '') { fwrite(STDERR, "usage: php _dev/build.php --out=DIR [--preview] [--content=FILE]\n"); exit(2); }
$cf = $o['content'] ?? (is_file(DM_ROOT . '/data/content.json') ? DM_ROOT . '/data/content.json' : DM_APP . '/content.default.json');
$def = json_decode((string)file_get_contents(DM_APP . '/content.default.json'), true, 512, JSON_THROW_ON_ERROR);
$GLOBALS['DM_C'] = merge_defaults($def, json_decode((string)file_get_contents($cf), true, 512, JSON_THROW_ON_ERROR));   // same as the panel
$r = build_site(isset($o['preview']));
if (!is_dir($out)) mkdir($out, 0777, true);
foreach ($r['files'] as $p => $html) file_put_contents("$out/$p", $html);
echo count($r['files']), " files written to $out\n";
