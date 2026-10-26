@use('App\Icons\Ios')
@use('App\Icons\Android')

<native:column fill class="safe-area bg-theme-background">
    {{-- The lockup, two thirds of the screen wide at its own 15:4, centred in the space above the buttons. --}}
    <native:column ref="logo-space" class="w-full flex-1 items-center justify-center">
        <native:image ref="logo" :src="$logo" alt="devnull" class="w-2/3 aspect-[15/4]" />
    </native:column>

    <native:column ref="actions" class="w-full px-6 pb-4 gap-3">
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
