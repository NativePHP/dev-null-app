<?php

use Composer\InstalledVersions;

/**
 * Applies the native tab reselect patches to nativephp/mobile 4.7.x.
 *
 * The PHP patch is applied everywhere, including the staged PHP copy
 * NativePHP builds on device. The native patch is only applied where the
 * package's native sources are present (the app root), because the staged
 * copy deliberately excludes them.
 *
 * The change is carried here until it lands in a released NativePHP
 * version. The script is idempotent and fails loudly when the package
 * version moves or a patch no longer applies.
 */
$root = dirname(__DIR__);
$package = $root.'/vendor/nativephp/mobile';
$phpPatch = $root.'/patches/nativephp-mobile-tab-reselect.patch';
$nativePatch = $root.'/patches/nativephp-mobile-tab-reselect-native.patch';

if (! is_file($package.'/composer.json')) {
    fwrite(STDERR, "nativephp/mobile is not installed; skipping the tab reselect patch.\n");
    exit(0);
}

$manifest = json_decode((string) file_get_contents($package.'/composer.json'), true);
$version = is_array($manifest) ? (string) ($manifest['version'] ?? '0.0.0') : '0.0.0';

if ($version === '0.0.0' && is_file($root.'/vendor/autoload.php')) {
    require_once $root.'/vendor/autoload.php';

    if (class_exists(InstalledVersions::class)) {
        $version = InstalledVersions::getPrettyVersion('nativephp/mobile') ?? '0.0.0';
    }
}

if ($version === '0.0.0' && is_file($root.'/composer.lock')) {
    $lock = json_decode((string) file_get_contents($root.'/composer.lock'), true);

    foreach ($lock['packages'] ?? [] as $installed) {
        if (($installed['name'] ?? null) === 'nativephp/mobile') {
            $version = (string) ($installed['version'] ?? '0.0.0');
            break;
        }
    }
}

if (! str_starts_with($version, '4.7.')) {
    fwrite(STDERR, "nativephp/mobile {$version} is not 4.7.x; rebase the tab reselect patches.\n");
    exit(1);
}

/**
 * @param  array<string, string>  $markers  file => marker that proves it is patched
 */
$apply = function (string $patch, array $markers) use ($package): void {
    $applied = true;

    foreach ($markers as $file => $marker) {
        if (! is_file($file) || ! str_contains((string) file_get_contents($file), $marker)) {
            $applied = false;
            break;
        }
    }

    if ($applied) {
        return;
    }

    $command = sprintf(
        'patch -p1 --forward --batch -d %s < %s',
        escapeshellarg($package),
        escapeshellarg($patch),
    );

    exec($command, $output, $code);

    if ($code !== 0) {
        fwrite(STDERR, "Could not apply {$patch}:\n".implode("\n", $output)."\n");
        exit(1);
    }
};

$apply($phpPatch, [
    $package.'/src/Edge/Element.php' => 'reselectMethod',
    $package.'/src/Edge/Layouts/Builders/Tab.php' => 'reselectMethod',
]);

$nativeMarkers = [
    $package.'/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/nativerender/NativeRootTabsRenderer.kt' => 'on_reselect',
    $package.'/resources/xcode/NativePHP/NativeRender/NativeRootTabsRenderer.swift' => 'TabBarReselectObserver',
];

if (is_file($package.'/resources/xcode/NativePHP/NativeRender/NativeRootTabsRenderer.swift')) {
    $apply($nativePatch, $nativeMarkers);
}

$generated = [
    'iOS' => [
        $root.'/nativephp/ios/NativePHP/NativeRender/NativeRootTabsRenderer.swift',
        'TabBarReselectObserver',
        'php artisan native:install ios --force',
    ],
    'Android' => [
        $root.'/nativephp/android/app/src/main/java/com/nativephp/mobile/ui/nativerender/NativeRootTabsRenderer.kt',
        'on_reselect',
        'php artisan native:install android --force',
    ],
];

foreach ($generated as $platform => [$file, $marker, $install]) {
    if (is_file($file) && ! str_contains((string) file_get_contents($file), $marker)) {
        fwrite(STDERR, "The patched {$platform} renderer is not in the generated native project yet. Run: {$install}\n");
    }
}

echo "nativephp/mobile tab reselect patch is applied.\n";
