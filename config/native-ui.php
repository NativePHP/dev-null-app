<?php

/**
 * Native UI — Theme Tokens
 *
 * Published via `php artisan vendor:publish --tag=native-ui-config`.
 * Edit to customize your app's visual identity in one place.
 *
 * For dynamic per-tenant theming, use Native\Mobile\UI\Theme::merge([...])
 * from a service provider. Runtime merges deep-merge on top of these values.
 *
 * Decision log: /docs/NATIVE-UI-REWRITE-PLAN.md (D — theme layer)
 */

return [

    /*
    |---------------------------------------------------------------------------
    | Theme
    |---------------------------------------------------------------------------
    |
    | 17 color tokens, 4 radii, 4 font sizes, font family.
    |
    | "on-X" means "color of content placed ON a surface of color X"
    |   — i.e., text/icons on that background.
    |
    | Color tokens accept:
    |   - CSS hex: '#B91C1C', '#F00', or with alpha '#8B5CF680' (#RRGGBBAA)
    |   - Tailwind palette names: 'red-300', 'orange-800'
    |   - Opacity modifiers on either: 'red-300/20', '#8B5CF6/50'
    |
    | Dark mode is auto-derived from `light` when `dark` is not set. To opt
    | into explicit dark tokens, fill out the `dark` block.
    |
    | The default pairs meet WCAG AA (4.5:1) — if you customize, keep each
    | `on-*` color at 4.5:1 contrast against its background token.
    |
    */

    'theme' => [

        // devnull's tokens from DESIGN.md: a cool, nearly colourless shell, one
        // working blue, and six author lanes that mark the graph. Your own lane
        // also colours your buttons.
        'light' => [
            // Commit Blue: links, the new commits pill, and the main buttons and
            // tab underlines before devnull has said whose phone this is.
            'primary' => '#3b6fe0',
            'on-primary' => '#ffffff',

            // Outline-style buttons ("Following") sit on the hover wash.
            'secondary' => '#eef0f2',
            'on-secondary' => '#121417',

            // Feed white on a cool shell.
            'surface' => '#ffffff',
            'on-surface' => '#121417',
            'background' => '#f6f7f8',
            'on-background' => '#121417',

            'surface-variant' => '#eef0f2',
            'on-surface-variant' => '#5b616b',

            'outline' => '#d3d7dd',

            // Rm Red.
            'destructive' => '#c92a2a',
            'on-destructive' => '#ffffff',

            // Sign in with Apple's button: black with white, as Apple's guidelines
            // ask. Nothing else in the app uses accent.
            'accent' => '#000000',
            'on-accent' => '#ffffff',

            // devnull's own tokens, used as bg-theme-*, text-theme-* and border-theme-*.
            'ink' => '#121417',
            'ink-muted' => '#5b616b',
            'hairline' => '#e1e4e8',
            'rail' => '#d3d7dd',
            'wash' => '#eef0f2',
            'primary-soft' => '#e8effc',
            'pending' => '#b45309',
            'pending-soft' => '#fef3c7',

            // Author lanes: graph nodes, rail segments, avatar rings. Yours also
            // colours the tab bar, the main buttons and the pager tab underlines.
            'lane-0' => '#d9480f',
            'lane-1' => '#2f9e44',
            'lane-2' => '#9c36b5',
            'lane-3' => '#0c8599',
            'lane-4' => '#c2255c',
            'lane-5' => '#b7791f',

            // A button's label on each lane: white, except ink on green and
            // ochre, where white falls well short of 4.5:1. White on orange and
            // teal is 4.3:1, a hair short, and ink is no better there.
            'on-lane-0' => '#ffffff',
            'on-lane-1' => '#121417',
            'on-lane-2' => '#ffffff',
            'on-lane-3' => '#ffffff',
            'on-lane-4' => '#ffffff',
            'on-lane-5' => '#121417',

            // Someone streaming: the web's red-600 ring and LIVE badge, the
            // same in both appearances.
            'live' => '#dc2626',
            'on-live' => '#ffffff',
        ],

        // A faithful mirror on graphite, with the lanes and primary lifted.
        'dark' => [
            'primary' => '#7aa2f7',
            'on-primary' => '#111112',

            'secondary' => '#232326',
            'on-secondary' => '#ececec',

            'surface' => '#18181a',
            'on-surface' => '#ececec',
            'background' => '#111112',
            'on-background' => '#ececec',

            'surface-variant' => '#232326',
            'on-surface-variant' => '#a1a1a6',

            'outline' => '#36363a',

            'destructive' => '#ff6b6b',
            'on-destructive' => '#111112',

            // White with black on a dark background, as Apple's guidelines ask.
            'accent' => '#ffffff',
            'on-accent' => '#000000',

            'ink' => '#ececec',
            'ink-muted' => '#a1a1a6',
            'hairline' => '#2a2a2d',
            'rail' => '#36363a',
            'wash' => '#232326',
            'primary-soft' => '#18233a',
            'pending' => '#fbbf24',
            'pending-soft' => '#3a2a0a',

            'lane-0' => '#ff8a4c',
            'lane-1' => '#51cf66',
            'lane-2' => '#da77f2',
            'lane-3' => '#3bc9db',
            'lane-4' => '#f06595',
            'lane-5' => '#fcc419',

            // The lifted lanes are all light enough that white won't read on
            // them (3:1 at best), so labels are graphite, as on primary.
            'on-lane-0' => '#111112',
            'on-lane-1' => '#111112',
            'on-lane-2' => '#111112',
            'on-lane-3' => '#111112',
            'on-lane-4' => '#111112',
            'on-lane-5' => '#111112',

            'live' => '#dc2626',
            'on-live' => '#ffffff',
        ],

        // Corner radii (points / dp): 6px controls, 8px groups, circles for people and commits.
        'radius-sm' => 4,
        'radius-md' => 6,
        'radius-lg' => 8,
        'radius-full' => 9999,

        // Font size scale (points / sp).
        'font-sm' => 14,
        'font-md' => 15,
        'font-lg' => 17,
        'font-xl' => 24,
    ],

    // Geist for people, Geist Mono for the machine. The files are the web app's,
    // under the SIL Open Font License (resources/fonts/OFL.txt).
    'fonts' => [
        'default' => 'Geist-Regular',
        'semibold' => 'Geist-SemiBold',
        'mono' => 'GeistMono-Regular',
        'mono-medium' => 'GeistMono-Medium',
    ],

];
