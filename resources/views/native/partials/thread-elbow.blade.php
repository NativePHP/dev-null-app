{{--
    Where a thread leaves the rail, as the web's thread elbow from #121: a dashed line in the commit's lane down from
    the rail ($from), round a 12pt corner, and across to the first reply's avatar ($to), level with its middle. A
    nested reply's elbow leaves the spine of the reply above it the same way. It stops 1pt short of the avatar.
--}}
@php($from ??= 31.5)
@php($to ??= 64)
<native:column ref="thread-elbow" class="absolute top-0 left-[{{ $from }}] w-[{{ $to - 1 - $from }}] h-[{{ \App\Devnull\Display::ElbowLevel + 0.5 }}] border-l border-b rounded-bl-xl border-dashed border-theme-{{ \App\Devnull\Lane::token($lane) }}" />
