<?php

use Illuminate\Support\Facades\File;

/**
 * Every font the views name, in a `font` attribute or as a quoted branch of a bound `:font`, with the views that
 * name it.
 *
 * @return array<string, list<string>>
 */
function fontsNamedInViews(): array
{
    $fonts = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $contents = $file->getContents();

        preg_match_all('/(?<![:\w-])font="([^"]+)"/', $contents, $plain);
        preg_match_all('/:font="([^"]+)"/', $contents, $bound);
        preg_match_all("/(?:^|[?:])\\s*'([\\w-]+)'/m", implode("\n", $bound[1]), $quoted);

        foreach (array_unique([...$plain[1], ...$quoted[1]]) as $font) {
            $fonts[$font][] = $file->getRelativePathname();
        }
    }

    return $fonts;
}

/**
 * Whether resources/fonts has a file for a font token, which the build bundles into the app.
 */
function bundlesFont(string $token): bool
{
    return File::exists(resource_path("fonts/{$token}.ttf")) || File::exists(resource_path("fonts/{$token}.otf"));
}

it('bundles a file for every font alias', function () {
    $missing = array_filter(config('native-ui.fonts'), fn (string $token): bool => $token !== 'System' && ! bundlesFont($token));

    expect($missing)->toBe([]);
});

it('names only fonts the app has, since a phone draws any other in the system font', function () {
    $unknown = array_filter(
        fontsNamedInViews(),
        fn (string $font): bool => ! array_key_exists($font, config('native-ui.fonts')) && ! bundlesFont($font),
        ARRAY_FILTER_USE_KEY,
    );

    // Never bundled, so these two draw in the system font for now.
    unset($unknown['medium'], $unknown['bold']);

    expect($unknown)->toBe([]);
});
