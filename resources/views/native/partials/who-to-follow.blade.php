{{--
    Who to follow, in the log under its fifth commit, as the web shows it on smaller screens: `$ who --should-i-follow`
    in muted mono with a refresh and Don't show again beside it, then three people in a bordered list. Each has their
    avatar, name, their handle after a dot in their lane, the first line of their bio as a `#` comment and why they're
    suggested, with Follow and Not interested as icons on the right. A tap anywhere else opens them.

    $whoToFollow['note'] says what it shows instead: 'hidden' just after Don't show again, with an Undo, or 'emptied'
    once nobody is left to suggest. The rail stops above it (see Timeline), so its gutter is empty.
--}}
@php($people = $whoToFollow['people'])
@php($note = $whoToFollow['note'])
<native:row native:key="who-to-follow" ref="who-to-follow" class="w-full bg-theme-surface">
    <native:column :width="64" />
    <native:column class="flex-1">
        <native:column class="w-full py-4 pr-4 gap-2">
            <native:row class="w-full gap-2 items-center">
                <native:text font="mono" class="flex-1 text-[13px] text-theme-ink-muted" :max-lines="1">$ who --should-i-follow{{ $note === 'hidden' ? ' > /dev/null' : '' }}</native:text>
                @if ($note === null)
                    {{-- While the next few are on their way the refresh is faded, with nothing to press, as the web disables it. --}}
                    @if ($whoToFollow['refreshing'])
                        <native:column ref="who-to-follow-refresh" class="min-w-[40] h-[36] items-center justify-center rounded-md" a11y-label="Suggesting other people">
                            <native:icon :ios="\App\Icons\Lucide::RefreshCw->ios()" :android="\App\Icons\Lucide::RefreshCw->android()" :size="16" class="text-theme-ink-muted opacity-40" />
                        </native:column>
                    @else
                        <native:pressable ref="who-to-follow-refresh" @press="suggestOtherPeople" a11y-label="Suggest other people">
                            <native:column class="min-w-[40] h-[36] items-center justify-center rounded-md">
                                <native:icon :ios="\App\Icons\Lucide::RefreshCw->ios()" :android="\App\Icons\Lucide::RefreshCw->android()" :size="16" class="text-theme-ink-muted" />
                            </native:column>
                        </native:pressable>
                    @endif
                    <native:pressable ref="who-to-follow-hide" @press="hideWhoToFollow" a11y-label="Don't show who to follow again">
                        <native:column class="min-w-[40] h-[36] items-center justify-center rounded-md">
                            <native:icon :ios="\App\Icons\Lucide::EyeOff->ios()" :android="\App\Icons\Lucide::EyeOff->android()" :size="16" class="text-theme-ink-muted" />
                        </native:column>
                    </native:pressable>
                @endif
            </native:row>

            @if ($note === 'hidden')
                <native:row class="w-full gap-3 items-center">
                    <native:text class="flex-1 text-xs text-theme-ink-muted">Bring it back in Settings.</native:text>
                    <native:button ref="who-to-follow-undo" variant="secondary" size="sm" @press="undoHideWhoToFollow">Undo</native:button>
                </native:row>
            @elseif ($note === 'emptied')
                <native:column ref="who-to-follow-emptied" class="w-full gap-1">
                    <native:text font="mono" class="text-[13px] text-theme-ink-muted">fatal: no one left to suggest</native:text>
                    <native:text class="text-xs text-theme-ink-muted">You've followed or dismissed everyone we'd suggest. New people show up here as they start committing.</native:text>
                </native:column>
            @else
                <native:column class="w-full rounded-lg border border-theme-hairline">
                    @foreach ($people as $suggested)
                        @php($user = $suggested->user)
                        @php($reason = $suggested->reason())
                        <native:row native:key="suggested-{{ $user->id }}" ref="suggested-{{ $user->username }}" class="w-full py-3 pl-3 pr-1 gap-1 items-center">
                            <native:pressable @press="openSuggestedUser('{{ $user->username }}')" class="flex-1" a11y-label="{{ $user->name }}, {{ $user->handle() }}{{ $reason ? ', '.$reason : '' }}">
                                <native:row class="w-full gap-3 items-center">
                                    @include('native.partials.avatar', ['user' => $user, 'size' => 36])
                                    <native:column class="flex-1 gap-[2]">
                                        <native:text font="semibold" class="w-full text-sm text-theme-ink" :max-lines="1">{{ $user->name }}</native:text>
                                        <native:row class="w-full gap-[6] items-center">
                                            <native:column :width="6" :height="6" class="rounded-full bg-theme-{{ \App\Devnull\Lane::token($user->lane()) }} flex-shrink-0" />
                                            <native:text font="mono" class="text-xs text-theme-ink-muted flex-shrink" :max-lines="1">{{ $user->handle() }}</native:text>
                                        </native:row>
                                        @if (trim((string) $user->bio) !== '')
                                            <native:text font="mono" class="w-full text-xs text-theme-ink-muted" :max-lines="1"># {{ trim($user->bio) }}</native:text>
                                        @endif
                                        @if ($reason)
                                            <native:text class="w-full text-xs text-theme-ink-muted" :max-lines="1">{{ $reason }}</native:text>
                                        @endif
                                    </native:column>
                                </native:row>
                            </native:pressable>
                            <native:pressable ref="suggested-follow-{{ $user->username }}" @press="toggleSuggestedFollow('{{ $user->username }}')" a11y-label="{{ $suggested->isFollowed ? 'Unfollow' : ($suggested->followsYou ? 'Follow back' : 'Follow') }} {{ $user->handle() }}">
                                <native:column class="min-w-[44] h-[36] items-center justify-center rounded-md {{ $suggested->isFollowed ? '' : 'bg-theme-primary-soft' }}">
                                    @php($followIcon = $suggested->isFollowed ? \App\Icons\Lucide::UserCheck : \App\Icons\Lucide::UserPlus)
                                    <native:icon :ios="$followIcon->ios()" :android="$followIcon->android()" :size="16" class="{{ $suggested->isFollowed ? 'text-theme-ink-muted' : 'text-theme-primary' }}" />
                                </native:column>
                            </native:pressable>
                            <native:pressable ref="suggested-dismiss-{{ $user->username }}" @press="dismissSuggestion('{{ $user->username }}')" a11y-label="Not interested. {{ $user->handle() }} won't be suggested again.">
                                <native:column class="min-w-[44] h-[36] items-center justify-center rounded-md">
                                    <native:icon :ios="\App\Icons\Lucide::X->ios()" :android="\App\Icons\Lucide::X->android()" :size="16" class="text-theme-ink-muted" />
                                </native:column>
                            </native:pressable>
                        </native:row>
                        @unless ($loop->last)
                            <native:column class="w-full h-[1] bg-theme-hairline" />
                        @endunless
                    @endforeach
                </native:column>
            @endif
        </native:column>
        <native:column class="w-full h-[1] bg-theme-hairline" />
    </native:column>
</native:row>
