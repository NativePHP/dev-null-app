<?php

use App\Icons\Lucide;
use Illuminate\Support\Facades\File;
use Native\Mobile\Icon\Icon;
use Native\Mobile\Icon\Svg\NormalisedSvgWriter;
use Native\Mobile\Icon\Svg\SvgParser;
use Native\Mobile\Icon\Svg\VectorDrawableWriter;

/**
 * The name of every custom icon the app draws: each Lucide case in both states, and any
 * `Icon::custom('…')` or `'custom:…'` written out in the app's PHP and Blade.
 *
 * @return list<string>
 */
function referencedCustomIcons(): array
{
    $icons = collect(Lucide::cases())
        ->flatMap(fn (Lucide $icon): array => [$icon->custom(), $icon->custom(filled: true)])
        ->filter()
        ->map(fn (string $icon): string => substr($icon, strlen(Icon::PREFIX)));

    foreach ([...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))] as $file) {
        preg_match_all('/(?:Icon::custom\(\s*|[\'"]custom:)[\'"]?([a-z0-9-]+)[\'"]/', $file->getContents(), $matches);

        $icons->push(...$matches[1]);
    }

    return $icons->unique()->sort()->values()->all();
}

it('has an SVG in resources/icons for every custom icon the app draws', function () {
    $missing = array_filter(referencedCustomIcons(), fn (string $name): bool => ! File::exists(resource_path("icons/{$name}.svg")));

    expect($missing)->toBe([]);
});

it('draws no system icon but the Apple logo, which Sign in with Apple has to show', function () {
    $systemIcons = collect([...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))])
        ->reject(fn (SplFileInfo $file): bool => str_starts_with($file->getPathname(), app_path('Icons')))
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all('/\b(?:Ios|Android|AndroidOutlined)::(?!AppleLogo\b)\w+/', (string) file_get_contents($file->getPathname()), $matches);

            return array_map(fn (string $icon): string => $file->getFilename().': '.$icon, $matches[0]);
        });

    expect($systemIcons->all())->toBe([]);
});

it('converts each SVG in resources/icons for both platforms', function (string $path) {
    // The build runs the same conversion and stops at the first SVG it can't draw.
    $icon = SvgParser::parse((string) file_get_contents($path), 'resources/icons/'.basename($path));

    expect(Icon::isValidName(basename($path, '.svg')))->toBeTrue()
        ->and(simplexml_load_string(VectorDrawableWriter::write($icon))->getName())->toBe('vector')
        ->and(simplexml_load_string(NormalisedSvgWriter::write($icon))->getName())->toBe('svg')
        ->and([$icon->width(), $icon->height()])->toBe([24.0, 24.0]);
})->with(fn (): array => collect(glob(__DIR__.'/../../resources/icons/*.svg'))
    ->mapWithKeys(fn (string $path): array => [basename($path) => $path])
    ->all());
