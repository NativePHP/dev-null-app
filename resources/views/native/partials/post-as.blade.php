{{--
    Who a composer posts as (see PostAs): the avatar of whoever it is now, round for you and a rounded square for an
    organization, with a small chevron on its corner, as the web's Post as button draws it. A tap opens the menu of you
    and your organizations, which calls $method with the handle picked, or '' for you. With $withName, their name and
    handle follow the avatar, for the sheet, which has the room.

    $picked is the handle the composer holds. An organization you've left since posts as you, so your avatar shows.
--}}
@php($size ??= 32)
@php($withName ??= false)
@php($author = \App\Devnull\PostAs::author($picked))
@php($identity = $author ?? $me)
<native:pressable ref="{{ $ref }}" :menu="\App\Devnull\PostAs::menu($me, $author, $method)" class="flex-shrink-0" a11y-label="{{ \App\Devnull\PostAs::label($me, $author) }}">
    <native:row class="gap-3 items-center">
        <native:column :width="$size + 4" :height="$size + 4">
            @include('native.partials.avatar', ['user' => $identity, 'size' => $size])
            <native:stack :width="16" :height="16" center class="absolute top-[{{ $size - 12 }}] left-[{{ $size - 12 }}] rounded-full border border-theme-hairline bg-theme-surface">
                <native:icon :ios="\App\Icons\Lucide::ChevronDown->ios()" :android="\App\Icons\Lucide::ChevronDown->android()" :size="11" class="text-theme-ink-muted" a11y-label="{{ \App\Devnull\PostAs::label($me, $author) }}" />
            </native:stack>
        </native:column>
        @if ($withName)
            <native:column class="flex-shrink gap-0.5">
                <native:text ref="{{ $ref }}-name" font="semibold" class="text-sm text-theme-ink" :max-lines="1">{{ $identity->name }}</native:text>
                <native:text ref="{{ $ref }}-handle" font="mono" class="text-xs text-theme-ink-muted" :max-lines="1">{{ $identity->handle() }}</native:text>
            </native:column>
        @endif
    </native:row>
</native:pressable>
