{{--
    A guild's mark: its uploaded icon while devnull's signed link to it is good, or a copy is on the device
    (see Guild::iconSrc). A guild with an uploaded icon the app can't load shows a plain people glyph, never
    the emoji it stores underneath, which its moderator didn't choose and the web doesn't show. Otherwise its
    emoji, otherwise nothing. The mark sits beside the guild's name, so it has no alt text of its own.
--}}
@php($iconSrc = $guild->iconSrc())
@if ($iconSrc !== null)
    <native:image ref="{{ $ref }}" :src="$iconSrc" :width="$size" :height="$size" :fit="2" class="rounded-md" />
@elseif ($guild->iconUrl !== null)
    <native:icon ref="{{ $ref }}" :ios="\App\Icons\Lucide::UsersRound->ios()" :android="\App\Icons\Lucide::UsersRound->android()" :size="$size >= 24 ? 20 : 12" class="text-theme-ink-muted" />
@elseif ($guild->emoji !== '')
    <native:text ref="{{ $ref }}" class="{{ $size >= 24 ? 'text-2xl' : 'text-xs' }}">{{ $guild->emoji }}</native:text>
@endif
