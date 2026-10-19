{{--
    The graph rail, drawn over a row's 64pt gutter. Place it last inside a row that reserves the gutter, so it draws on top.
    $previous and $tail are a lane number, 'rail' for Rail Gray, or null for no segment. $tail can also be 'staged':
    a dashed Rail Gray line of $tailDashes dashes under a commit that isn't made yet.
    $node is what sits on the rail, centred 32pt down as on the web:
      'avatar'    the author's avatar ringed in their lane ($user, $lane), pressing $press if given
      'staged'    the same with a dashed ring, for a commit not made yet
      'dot'       a lane dot level with the name, for a commit carrying on its author's run
      'root'      a hollow lane dot, the end of history
      'tombstone' a dashed Rail Gray ring where a deleted commit's avatar was
      'skeleton'  a wash disc, for a row still loading
      null        no node; a $previous segment then runs the full height of the row
    $endsWithDot stops the $tail 20pt above the bottom at a lane dot, as the web ends a commit that's alone in its run.
    $replyTail runs a dashed line in the lane on down from the node, or from that dot, to the replies under the row.
    It's $replyDashes long when it starts at the node, enough for the row, since PHP can't know its height.
    $avatarSize draws the avatar's ring smaller than its 48pt, centred in the same place.
--}}
@php($previous ??= null)
@php($tail ??= null)
@php($node ??= null)
@php($press ??= null)
@php($tailDashes ??= 3)
@php($endsWithDot ??= false)
@php($replyTail ??= false)
@php($replyDashes ??= 40)
@php($avatarSize ??= 48)
@php($center = 32)
@php($endDot = 20)
@php($segment = fn (int|string $lane): string => 'bg-theme-'.\App\Devnull\Lane::token($lane))

@if ($node === null)
    @if ($previous !== null)
        <native:column class="absolute top-0 bottom-0 left-[31] w-[2] {{ $segment($previous) }}" />
    @endif
@else
    @if ($previous !== null)
        <native:column class="absolute top-0 left-[31] w-[2] h-[{{ $center }}] {{ $segment($previous) }}" />
    @endif
    @if ($tail === 'staged')
        <native:column class="absolute top-[58] left-[31] w-[2] gap-[4]">
            @foreach (range(1, $tailDashes) as $dash)
                <native:column class="w-[2] h-[4] bg-theme-rail" />
            @endforeach
        </native:column>
    @elseif ($tail !== null)
        {{-- `bottom-0`, never `bottom-[0]`: EDGE reads an arbitrary zero inset as no inset, which leaves the tail no height. --}}
        <native:column class="absolute top-[{{ $center }}] {{ $endsWithDot ? 'bottom-['.$endDot.']' : 'bottom-0' }} left-[31] w-[2] {{ $segment($tail) }}" />
    @endif

    @if ($replyTail && $endsWithDot)
        @include('native.partials.dashed-line', ['position' => 'bottom-0 h-['.$endDot.']', 'left' => 31.5, 'colour' => $segment($lane), 'dashes' => 3])
    @elseif ($replyTail)
        @include('native.partials.dashed-line', ['position' => 'top-['.($node === 'dot' ? $center - 9 : $center).'] bottom-0', 'left' => 31.5, 'colour' => $segment($lane), 'dashes' => $replyDashes])
    @endif

    @if ($endsWithDot && $tail !== null)
        <native:column ref="end-dot" class="absolute bottom-[{{ $endDot - 9 }}] left-[23]">
            @include('native.partials.rail-node', ['lane' => $tail])
        </native:column>
    @endif

    @if ($node === 'dot' || $node === 'root')
        {{-- A continuing commit's dot sits 9pt higher, level with the author's name, as on the web. --}}
        <native:column class="absolute top-[{{ $node === 'dot' ? $center - 18 : $center - 9 }}] left-[23]">
            @if ($press)
                <native:pressable @press="{{ $press }}" a11y-label="{{ $user->name }}'s profile">
                    @include('native.partials.rail-node', ['lane' => $lane])
                </native:pressable>
            @else
                @include('native.partials.rail-node', ['lane' => $lane, 'filled' => $node === 'dot'])
            @endif
        </native:column>
    @else
        @php($nodeSize = $node === 'avatar' ? $avatarSize : 48)
        <native:column class="absolute top-[{{ $center - intdiv($nodeSize, 2) }}] left-[{{ 32 - intdiv($nodeSize, 2) }}]">
            @if ($node === 'avatar' && $press)
                <native:pressable @press="{{ $press }}" a11y-label="{{ $user->name }}'s profile">
                    @include('native.partials.rail-avatar', ['user' => $user, 'lane' => $lane, 'size' => $avatarSize])
                </native:pressable>
            @elseif ($node === 'avatar' || $node === 'staged')
                @include('native.partials.rail-avatar', ['user' => $user, 'lane' => $lane, 'staged' => $node === 'staged'])
            @elseif ($node === 'tombstone')
                <native:column :width="48" :height="48" class="rounded-full bg-theme-surface">
                    @include('native.partials.dashed-ring', ['size' => 48, 'colour' => 'bg-theme-rail'])
                </native:column>
            @elseif ($node === 'skeleton')
                <native:stack :width="48" :height="48" center class="rounded-full bg-theme-surface">
                    <native:column :width="40" :height="40" class="rounded-full bg-theme-wash" />
                </native:stack>
            @endif
        </native:column>
    @endif
@endif
