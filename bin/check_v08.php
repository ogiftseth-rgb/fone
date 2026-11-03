<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$checks = [];
$manifestPath = $root . '/public/manifest.webmanifest';
$manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
$checks['manifest_valid_json'] = is_array($manifest);
$checks['manifest_has_start_url'] = !empty($manifest['start_url']);
$checks['manifest_standalone'] = ($manifest['display'] ?? '') === 'standalone';
$checks['manifest_192_icon'] = false;
$checks['manifest_512_icon'] = false;
foreach (($manifest['icons'] ?? []) as $icon) {
    if (($icon['sizes'] ?? '') === '192x192' && is_file($root . '/public/' . ($icon['src'] ?? ''))) $checks['manifest_192_icon'] = true;
    if (($icon['sizes'] ?? '') === '512x512' && is_file($root . '/public/' . ($icon['src'] ?? ''))) $checks['manifest_512_icon'] = true;
}
foreach (['public/sw.js','public/offline.html','public/assets/app.js','public/assets/app.css','public/assets/apple-touch-icon.png'] as $file) $checks['file_' . str_replace(['.','/'],['_','_'],$file)] = is_file($root . '/' . $file);
$bootstrap = (string)file_get_contents($root . '/app/bootstrap.php');
$checks['bootstrap_manifest_link'] = str_contains($bootstrap, 'rel="manifest"');
$checks['bootstrap_install_button'] = str_contains($bootstrap, 'pwaInstallButton');
$all = !in_array(false, $checks, true);
foreach ($checks as $name => $ok) echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
echo $all ? "FoneFlex PWA shell checks passed.\n" : "One or more PWA shell checks failed.\n";
exit($all ? 0 : 1);
