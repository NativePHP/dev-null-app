{{--
    The web's welcome page as three commits on the rail: the wordmark, the pitch, and a hollow node for the commit
    you haven't made yet, which is signing in.
--}}
<native:column fill class="safe-area bg-theme-surface">
    <native:spacer />

    <native:row class="w-full">
        <native:column :width="64" />
        <native:row class="flex-1 pr-4 pb-10 gap-2 items-center">
            @include('native.partials.mark', ['size' => 40, 'surface' => 'bg-theme-surface'])
            <native:text font="semibold" class="text-5xl tracking-tighter text-theme-ink">/dev/null</native:text>
        </native:row>

        @include('native.partials.rail', ['lane' => 0, 'node' => 'dot', 'tail' => 0])
    </native:row>

    <native:row class="w-full">
        <native:column :width="64" />
        <native:column class="flex-1 pt-[5] pr-6 pb-10 gap-3">
            <native:text font="semibold" class="text-3xl leading-tight tracking-tight text-theme-ink">A social network for developers.</native:text>
            <native:text class="text-lg leading-relaxed text-theme-ink-muted">Sign in with GitHub, follow people who write code, and commit plain text to a shared log, 280 characters at a time.</native:text>
        </native:column>

        @include('native.partials.rail', ['previous' => 0, 'lane' => 1, 'node' => 'dot', 'tail' => 1])
    </native:row>

    <native:row class="w-full">
        <native:column :width="64" />
        <native:column class="flex-1 pt-[10] pr-6 gap-3">
            <native:button ref="sign-in" variant="primary" size="lg" @press="signInWithGitHub">Sign in with GitHub</native:button>
            <native:text class="text-sm text-theme-ink-muted">Your profile is imported from GitHub. Nothing to fill in.</native:text>
        </native:column>

        @include('native.partials.rail', ['previous' => 1, 'lane' => 1, 'node' => 'root'])
    </native:row>

    <native:spacer />

    <native:text class="pl-16 pr-6 pb-8 text-sm text-theme-ink-muted">No likes. No reposts. No algorithm. Everything else goes to /dev/null.</native:text>
</native:column>
