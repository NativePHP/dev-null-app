@use('App\Icons\Lucide')

<native:column fill class="safe-area bg-theme-background">
    <native:scroll-view class="w-full flex-1">
        <native:column ref="terms" class="w-full px-6 pt-10 pb-6 gap-4">
            <native:image ref="logo" :src="$logo" alt="devnull" class="w-[105] h-[28]" />

            <native:text ref="title" font="semibold" class="pt-6 text-2xl text-theme-ink">{{ $title }}</native:text>
            <native:text ref="explanation" class="text-[15px] leading-relaxed text-theme-ink">{{ $explanation }}</native:text>
            <native:text class="text-[15px] leading-relaxed text-theme-ink">There's no tolerance for objectionable content or abusive users.</native:text>
            <native:text class="text-[15px] leading-relaxed text-theme-ink-muted">If you don't accept them, you can sign out, or delete your account.</native:text>

            <native:column class="w-full pt-2 gap-2">
                <native:button ref="terms-link" variant="secondary" size="lg" :ios-icon-trailing="Lucide::ArrowUpRight->ios()" :android-icon-trailing="Lucide::ArrowUpRight->android()" @press="openTerms" class="w-full">Terms of service</native:button>
                <native:button ref="privacy-link" variant="secondary" size="lg" :ios-icon-trailing="Lucide::ArrowUpRight->ios()" :android-icon-trailing="Lucide::ArrowUpRight->android()" @press="openPrivacy" class="w-full">Privacy policy</native:button>
            </native:column>
        </native:column>
    </native:scroll-view>

    <native:column ref="actions" class="w-full px-6 pt-2 pb-4 gap-3">
        @if ($error)
            <native:text ref="error" font="mono" class="w-full text-center text-[13px] leading-snug text-theme-destructive">{{ $error }}</native:text>
        @endif

        <native:button ref="accept" variant="primary" size="lg" @press="accept" class="w-full">Accept the terms</native:button>
        <native:button ref="sign-out" variant="secondary" size="lg" @press="confirmSignOut" class="w-full">Sign out</native:button>
        <native:pressable ref="delete-account" @press="confirmDeleteAccount" class="w-full h-[44] items-center justify-center" a11y-label="Delete your account">
            <native:text class="text-[15px] text-theme-destructive">Delete account</native:text>
        </native:pressable>
    </native:column>
</native:column>
