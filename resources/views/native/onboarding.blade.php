@use('App\Icons\Ios')
@use('App\Icons\Android')

{{--
    On a phone, the lockup over the buttons. Once the window is expanded (a foldable's inner display held sideways) the
    lockup and the buttons sit side by side as two equal halves, with the gap between them in the middle of the
    window.
--}}
<native:column fill class="safe-area bg-theme-background expanded:flex-row expanded:items-center expanded:gap-16">
    {{--
        The lockup, two thirds wide at its own 15:4, centred in its space. Side by side it has to fit its half
        less the buttons' 24pt margin on each side. iOS resolves a percentage against the window, not the parent, and a
        variant can't swap a width for `w-full`, so there it's 40% of the window: at most that room from 840pt up (336pt
        of 340pt there, 380pt of 395pt on the inner display). Android resolves it against the parent, which is already
        the half, so it keeps two thirds.
    --}}
    <native:column ref="logo-space" class="w-full flex-1 items-center justify-center expanded:px-6">
        <native:image ref="logo" :src="$logo" alt="devnull" class="w-2/3 aspect-[15/4] ios:expanded:w-[40%]" />
    </native:column>

    <native:column ref="actions" class="w-full px-6 pb-4 gap-3 expanded:flex-1 expanded:pb-0">
        @if ($error)
            <native:text ref="error" font="mono" class="w-full text-center text-[13px] leading-snug text-theme-destructive">{{ $error }}</native:text>
        @endif

        @if ($appleError)
            <native:text ref="apple-error" font="mono" class="w-full text-center text-[13px] leading-snug text-theme-destructive">{{ $appleError }}</native:text>
        @endif

        {{-- What Apple asks of an app with user-generated content: agree to the terms before you're in. Neither button works until it's ticked. --}}
        <native:row ref="terms" class="w-full gap-3 items-start">
            <native:pressable ref="accept-terms" @press="toggleTerms" class="w-[44] h-[44] -ml-3 items-center justify-center" a11y-label="Agree to the terms of service and privacy policy" :a11y-value="$acceptedTerms ? 'ticked' : 'not ticked'">
                <native:column class="w-[22] h-[22] rounded-md border-2 items-center justify-center {{ $acceptedTerms ? 'border-theme-primary bg-theme-primary' : 'border-theme-ink-muted' }}">
                    @if ($acceptedTerms)
                        <native:icon :ios="\App\Icons\Lucide::Check->ios()" :android="\App\Icons\Lucide::Check->android()" :size="14" class="text-white" />
                    @endif
                </native:column>
            </native:pressable>
            <native:column class="flex-1 gap-1 pt-[10]">
                <native:text ref="terms-copy" class="text-[13px] leading-snug text-theme-ink">I agree to the terms of service and privacy policy. There's no tolerance for objectionable content or abusive users.</native:text>
                <native:row class="gap-4">
                    <native:pressable ref="terms-link" @press="openTerms" class="h-[32] justify-center" a11y-label="Read the terms of service">
                        <native:text class="text-[13px] text-theme-primary">Terms of service</native:text>
                    </native:pressable>
                    <native:pressable ref="privacy-link" @press="openPrivacy" class="h-[32] justify-center" a11y-label="Read the privacy policy">
                        <native:text class="text-[13px] text-theme-primary">Privacy policy</native:text>
                    </native:pressable>
                </native:row>
            </native:column>
        </native:row>

        {{-- Commit Blue, large, as the web's primary button. Nobody's lane colours it here: primary is always Commit Blue. --}}
        <native:button ref="sign-in" variant="primary" size="lg" :disabled="$fingerprint === '' || ! $acceptedTerms" @press="signIn" class="w-full">{{ $error && $fingerprint !== '' ? 'Try again' : 'Sign in on dev-null.sh' }}</native:button>

        {{-- Apple's button in Apple's colours, the theme's accent, with its logo, as big as the dev-null.sh one, as Apple's guidelines ask. --}}
        @if ($offersApple)
            <native:button ref="sign-in-apple" variant="accent" size="lg" :ios-icon="Ios::AppleLogo" :disabled="$fingerprint === '' || ! $acceptedTerms" @press="signInWithApple" class="w-full">Sign in with Apple</native:button>
        @endif
    </native:column>
</native:column>
