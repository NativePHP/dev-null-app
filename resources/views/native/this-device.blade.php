@use('App\Devnull\Lane')
@use('App\Icons\Ios')
@use('App\Icons\Android')
@use('App\Icons\Lucide')

{{--
    Laid out like the web's profile settings: a section each, with its heading, on the surface. The full-width buttons
    are large, as Sign in on dev-null.sh is, and the main ones wear your colour.
--}}
<native:column class="w-full h-full bg-theme-surface">
    @include('native.partials.colour-sheet')

    <native:scroll-view class="w-full flex-1 bg-theme-surface">
        <native:column class="w-full pt-6 pb-10">
            @include('native.partials.error')
            @include('native.partials.refresh-failed')

            @if ($user !== null)
                <native:column ref="profile-information" class="w-full">
                    @include('native.partials.settings-heading', [
                        'title' => 'Profile information',
                        'description' => 'Mirrored from GitHub when you sign in. To change any of it, change it on GitHub, then sync it here.',
                    ])

                    <native:row ref="profile-identity" class="w-full px-4 pt-5 gap-4 items-center">
                        @include('native.partials.rail-avatar', ['user' => $user, 'lane' => $savedLane, 'size' => 56])
                        <native:column class="flex-1 gap-[2]">
                            <native:text font="semibold" class="text-base text-theme-ink" :max-lines="1">{{ $user->name }}</native:text>
                            <native:text font="mono" class="text-sm text-theme-ink-muted" :max-lines="1">{{ $user->handle() }}</native:text>
                        </native:column>
                    </native:row>

                    {{-- The web's hairline table: mono labels, and null in muted mono for what GitHub doesn't have. --}}
                    <native:column ref="profile-fields" class="mx-4 mt-5 rounded-lg border border-theme-hairline bg-theme-surface">
                        @foreach ($fields as $field)
                            @if (! $loop->first)
                                <native:column class="w-full h-[1] bg-theme-hairline" />
                            @endif
                            <native:row ref="field-{{ $field['label'] }}" class="w-full px-3 py-3 gap-3">
                                <native:text font="mono" class="w-[88] text-[13px] text-theme-ink-muted">{{ $field['label'] }}</native:text>
                                @if ($field['value'] === null)
                                    <native:text font="mono" class="flex-1 text-[13px] text-theme-ink-muted">null</native:text>
                                @else
                                    <native:text class="flex-1 text-sm text-theme-ink">{{ $field['value'] }}</native:text>
                                @endif
                            </native:row>
                        @endforeach
                    </native:column>

                    <native:column class="w-full px-4 pt-5 gap-2">
                        <native:button ref="edit-on-github" :variant="$myColour" size="lg" :ios-icon-trailing="Lucide::ArrowUpRight->ios()" :android-icon-trailing="Lucide::ArrowUpRight->android()" @press="editOnGitHub" class="w-full">Edit on GitHub</native:button>
                        {{-- The label stays put while it spins, so the button keeps its size. --}}
                        <native:button ref="sync-github" variant="secondary" size="lg" :ios-icon="Lucide::RefreshCw->ios()" :android-icon="Lucide::RefreshCw->android()" :loading="$syncing" :disabled="$syncing" @press="syncProfile" class="w-full">Sync from GitHub</native:button>
                        <native:text ref="synced-at" class="text-xs leading-relaxed text-theme-ink-muted">{{ $syncedAt }}</native:text>
                        @if ($syncError)
                            <native:text ref="sync-error" class="text-sm text-theme-destructive">{{ $syncError }}</native:text>
                        @endif
                    </native:column>
                </native:column>

                <native:column ref="color" class="w-full pt-10">
                    @include('native.partials.settings-heading', [
                        'title' => 'Color',
                        'description' => 'Your lane on the graph: the rail and nodes beside your commits, the ring around your avatar, and the dot before your handle. Automatic picks one from your account id.',
                    ])

                    {{-- You on the rail in the colour picked, as the web's preview draws you. --}}
                    <native:row ref="lane-preview" class="mx-4 mt-5 rounded-lg border border-theme-hairline bg-theme-surface">
                        <native:column :width="64" />
                        <native:column class="flex-1 min-h-[64] py-3 pr-4 gap-[2] justify-center">
                            <native:text font="semibold" class="text-base text-theme-ink" :max-lines="1">{{ $user->name }}</native:text>
                            <native:row class="w-full gap-[6] items-center">
                                <native:column ref="preview-dot" :width="8" :height="8" class="rounded-full bg-theme-{{ Lane::token($previewLane) }} flex-shrink-0" />
                                <native:text font="mono" class="flex-1 text-sm text-theme-ink-muted" :max-lines="1">{{ $user->handle() }}</native:text>
                            </native:row>
                        </native:column>
                        @include('native.partials.rail', ['previous' => $previewLane, 'tail' => $previewLane, 'node' => 'avatar', 'user' => $user, 'lane' => $previewLane])
                    </native:row>

                    {{--
                        Automatic, the six lanes, then Custom. The one picked wears a ring in its colour. Automatic is a dashed
                        ring round a dot, in the lane it gives you. Custom is your custom colour, or a spectrum until you mix
                        one, and opens the sheet that mixes it. No press-* feedback: it would keep the screen from scrolling.
                    --}}
                    <native:row ref="lanes" class="w-full px-2 pt-4">
                        @foreach ($lanes as $option)
                            <native:pressable ref="lane-{{ $option['key'] }}" @press="chooseLane('{{ $option['key'] }}')" class="flex-1 items-center" a11y-label="{{ $option['a11y'] }}{{ $option['chosen'] ? ', selected' : '' }}">
                                <native:column class="w-full items-center gap-[6] py-2">
                                    <native:stack :width="38" :height="38" center class="rounded-full border-2 {{ $option['chosen'] ? 'border-theme-'.Lane::token($option['lane']) : 'border-transparent' }}">
                                        @if ($option['auto'])
                                            <native:column :width="28" :height="28">
                                                @include('native.partials.dashed-ring', ['size' => 28, 'lane' => $option['lane']])
                                                <native:column class="absolute top-[9] left-[9] w-[10] h-[10] rounded-full bg-theme-{{ Lane::token($option['lane']) }}" />
                                            </native:column>
                                        @elseif ($option['lane'] === null)
                                            <native:column ref="custom-spectrum" :width="28" :height="28" class="rounded-full bg-gradient-to-br from-red-500 via-green-500 to-blue-500" />
                                        @else
                                            <native:column :width="28" :height="28" class="rounded-full bg-theme-{{ Lane::token($option['lane']) }}" />
                                        @endif
                                    </native:stack>
                                    <native:text class="text-xs {{ $option['chosen'] ? 'text-theme-ink' : 'text-theme-ink-muted' }}">{{ $option['label'] }}</native:text>
                                </native:column>
                            </native:pressable>
                        @endforeach
                    </native:row>

                    {{-- In the colour picked, so you see it on a button before saving it. --}}
                    <native:column class="w-full px-4 pt-4 gap-2">
                        <native:button ref="save-lane" :variant="Lane::token($previewLane)" size="lg" :loading="$savingLane" :disabled="$savingLane || ! $laneChanged" @press="saveLane" class="w-full">Save color</native:button>
                        @if ($laneError)
                            <native:text ref="lane-error" class="text-sm text-theme-destructive">{{ $laneError }}</native:text>
                        @endif
                    </native:column>
                </native:column>

                {{--
                    The web's pastéis mode section, word for word: its heading and two lines, with the switch beside them.
                    The switch is the system's own (a UISwitch on iOS), which takes taps since NativePHP/mobile-air#545.
                --}}
                <native:column ref="pasteis-mode" class="w-full pt-10">
                    <native:row class="w-full px-4 gap-6 items-start">
                        <native:column class="flex-1 gap-2">
                            <native:text font="semibold" class="text-lg text-theme-ink">Pastéis mode</native:text>
                            <native:text class="text-sm leading-relaxed text-theme-ink-muted">A little appreciation, fresh daily. Get 10 pastéis to give to other people’s commits, one per click. Your allowance resets at 00:00 UTC.</native:text>
                            <native:text class="text-sm leading-relaxed text-theme-ink-muted">When off, all pastéis controls and counters are hidden. You still receive pastéis from others.</native:text>
                        </native:column>
                        <native:toggle ref="pasteis-mode-switch" :value="$pasteisMode" @change="setPasteisMode" a11y-label="Pastéis mode" />
                    </native:row>
                    @if ($pasteisModeError)
                        <native:text ref="pasteis-mode-error" class="w-full px-4 pt-2 text-sm text-theme-destructive">{{ $pasteisModeError }}</native:text>
                    @endif
                </native:column>
            @endif

            @if ($user)
                {{--
                    The web's Remotes switch, word for word, with the settings its Remotes page keeps in a menu under it
                    while Remotes are on: who can push to you, only while devnull offers Everyone, and Share pulls.
                --}}
                <native:column ref="remotes" class="w-full pt-10">
                    <native:row class="w-full px-4 gap-6 items-start">
                        <native:column class="flex-1 gap-2">
                            <native:text font="semibold" class="text-lg text-theme-ink">Remotes</native:text>
                            <native:text class="text-sm leading-relaxed text-theme-ink-muted">One-to-one messages with other developers. People you follow can push to you, and you can push to people who follow you. Whether you share pulls (read receipts) is up to you, from Remotes.</native:text>
                            <native:text class="text-sm leading-relaxed text-theme-ink-muted">When off, nobody can message you and Remotes is hidden. Your conversations are kept for when you turn it back on.</native:text>
                        </native:column>
                        <native:toggle ref="remotes-switch" :value="$remotes !== null" @change="setRemotes" a11y-label="Remotes" />
                    </native:row>
                    @if ($remotesError)
                        <native:text ref="remotes-error" class="w-full px-4 pt-2 text-sm text-theme-destructive">{{ $remotesError }}</native:text>
                    @endif

                    @if ($remotes)
                        @if ($remotes->everyone)
                            <native:column ref="remotes-from" class="mx-4 mt-4 rounded-lg border border-theme-hairline bg-theme-surface">
                                <native:text font="mono" class="w-full px-3 pt-3 pb-1 text-[13px] text-theme-ink-muted">who can push to you</native:text>
                                @foreach (['following' => 'People you follow', 'everyone' => 'Everyone'] as $from => $label)
                                    <native:pressable ref="remotes-from-{{ $from }}" @press="setRemotesFrom('{{ $from }}')" a11y-label="{{ $label }}{{ $remotes->from === $from ? ', selected' : '' }}">
                                        <native:row class="w-full px-3 py-3 gap-3 items-center">
                                            <native:text class="flex-1 text-[15px] text-theme-ink">{{ $label }}</native:text>
                                            @if ($remotes->from === $from)
                                                <native:icon :ios="Lucide::Check->ios()" :android="Lucide::Check->android()" :size="16" class="text-theme-{{ $myColour }}" />
                                            @endif
                                        </native:row>
                                    </native:pressable>
                                @endforeach
                                <native:text class="w-full px-3 pb-3 text-xs leading-relaxed text-theme-ink-muted">{{ $remotes->requests ? 'Anyone else lands in Fetched until you merge them. Requests already fetched stay there.' : 'Anyone else can push to you once you follow them.' }}</native:text>
                            </native:column>
                        @endif

                        <native:row ref="share-pulls" class="w-full px-4 pt-6 gap-6 items-start">
                            <native:column class="flex-1 gap-1">
                                <native:text font="medium" class="text-[15px] text-theme-ink">Share pulls</native:text>
                                <native:text class="text-sm leading-relaxed text-theme-ink-muted">When off, nobody sees when you pull their messages, and you don't see when they pull yours.</native:text>
                            </native:column>
                            <native:toggle ref="share-pulls-switch" :value="$remotes->pullReceipts" @change="setPullReceipts" a11y-label="Share pulls" />
                        </native:row>
                        @if ($remotePreferencesError)
                            <native:text ref="remote-preferences-error" class="w-full px-4 pt-2 text-sm text-theme-destructive">{{ $remotePreferencesError }}</native:text>
                        @endif
                    @endif
                </native:column>
            @endif

            @if ($user)
                {{-- The web's Who to follow setting, in its words, as a switch like the others here. --}}
                <native:column ref="who-to-follow-setting" class="w-full pt-10">
                    <native:row class="w-full px-4 gap-6 items-start">
                        <native:column class="flex-1 gap-2">
                            <native:text font="semibold" class="text-lg text-theme-ink">Who to follow</native:text>
                            <native:text class="text-sm leading-relaxed text-theme-ink-muted">Three people you might want to follow, in your log.</native:text>
                        </native:column>
                        <native:toggle ref="who-to-follow-switch" :value="$showsWhoToFollow" @change="setShowsWhoToFollow" a11y-label="Who to follow" />
                    </native:row>
                </native:column>
            @endif

            <native:column ref="safety" class="w-full pt-10">
                @include('native.partials.settings-heading', [
                    'title' => 'Safety',
                    'description' => 'Everyone you mute or block, and a way to undo it.',
                ])

                <native:column class="w-full px-4 pt-4">
                    <native:button ref="muted-and-blocked" variant="secondary" size="lg" :ios-icon-trailing="Lucide::ChevronRight->ios()" :android-icon-trailing="Lucide::ChevronRight->android()" @press="openSafety" class="w-full">Muted and blocked people</native:button>
                </native:column>
            </native:column>

            @if ($offersApple)
                <native:column ref="apple" class="w-full pt-10">
                    @include('native.partials.settings-heading', ['title' => 'Apple ID'])

                    <native:column class="mx-4 mt-4 rounded-lg border border-theme-hairline bg-theme-surface">
                        <native:row class="w-full px-3 py-3 gap-3">
                            <native:text font="mono" class="w-[88] text-[13px] text-theme-ink-muted">apple id</native:text>
                            <native:text ref="apple-status" font="mono" class="flex-1 text-[13px] text-theme-ink">{{ $appleStatus }}</native:text>
                        </native:row>
                    </native:column>

                    <native:column class="w-full px-4 pt-2">
                        <native:text class="text-xs leading-relaxed text-theme-ink-muted">A quicker way back in on a new phone. The account is still your GitHub one.</native:text>
                    </native:column>

                    @if ($appleLinked === false)
                        {{-- Apple's black button (white in dark mode, the theme's accent) with its logo, as Apple's guidelines ask. --}}
                        <native:column class="w-full px-4 pt-4">
                            <native:button ref="link-apple" variant="accent" size="lg" :ios-icon="Ios::AppleLogo" @press="linkApple" class="w-full">Sign in with Apple</native:button>
                        </native:column>
                    @elseif ($appleLinked === true)
                        <native:column class="w-full px-4 pt-4">
                            <native:button ref="unlink-apple" variant="secondary" size="lg" @press="confirmUnlinkApple" class="w-full">Unlink Apple ID</native:button>
                        </native:column>
                    @endif
                </native:column>
            @endif

            <native:column ref="this-phone" class="w-full pt-10">
                @include('native.partials.settings-heading', [
                    'title' => 'This phone',
                    'description' => 'Signed in with a key that stays on this phone. dev-null.sh lists it by this fingerprint.',
                ])

                {{-- dev-null.sh lists each phone by name and fingerprint, so this is how you tell which one is this phone. --}}
                <native:column class="mx-4 mt-4 rounded-lg border border-theme-hairline bg-theme-surface">
                    <native:row ref="fingerprint" class="w-full px-3 py-3 gap-3 select-text">
                        <native:text font="mono" class="w-[88] text-[13px] text-theme-ink-muted">fingerprint</native:text>
                        <native:text font="mono" class="flex-1 text-[13px] text-theme-ink">{{ $fingerprint ?: 'null' }}</native:text>
                    </native:row>
                    @if ($remote !== null)
                        <native:column class="w-full h-[1] bg-theme-hairline" />
                        <native:row ref="remote" class="w-full px-3 py-3 gap-3">
                            <native:text font="mono" class="w-[88] text-[13px] text-theme-ink-muted">remote</native:text>
                            <native:text font="mono" class="flex-1 text-[13px] text-theme-ink">{{ $remote }}</native:text>
                        </native:row>
                    @endif
                </native:column>

                <native:column class="w-full px-4 pt-4">
                    <native:button ref="devices" variant="secondary" size="lg" :ios-icon="Ios::Iphone" :android-icon="Android::Smartphone" @press="openDevices" class="w-full">Your devices on dev-null.sh</native:button>
                </native:column>
            </native:column>

            <native:column class="w-full px-4 pt-8 gap-2">
                <native:button ref="sign-out" variant="secondary" size="lg" @press="confirmSignOut" class="w-full">Sign out</native:button>
                <native:text class="text-xs leading-relaxed text-theme-ink-muted">Deletes the key from this phone and signs you out.</native:text>
            </native:column>

            <native:column ref="delete-section" class="w-full pt-12">
                @include('native.partials.settings-heading', [
                    'title' => 'Delete account',
                    'description' => "Removes your /dev/null account, every commit and reply you've made, and everyone you follow. Replies others left under your commits go too. Replies under your replies stay, hanging off a [deleted] tombstone. Your GitHub account is not touched.",
                ])

                <native:column class="w-full px-4 pt-4">
                    <native:button ref="delete-account" variant="destructive" size="lg" @press="confirmDeleteAccount" class="w-full">Delete account</native:button>
                </native:column>
            </native:column>
        </native:column>
    </native:scroll-view>
</native:column>
