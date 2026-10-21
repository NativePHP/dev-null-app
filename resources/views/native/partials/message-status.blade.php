{{--
    The web's status glyph for a message you pushed: a dashed ring in your lane while staged, a dot once pushed, your
    dot joined to the partner's once they pulled it, and a red ring when devnull didn't take it.
--}}
@php($mineToken = \App\Devnull\Lane::token($lane))
@php($theirToken = \App\Devnull\Lane::token($partnerLane ?? null))
@if ($status === 'pulled')
    <native:row ref="{{ $ref }}" :width="22" :height="8" class="items-center flex-shrink-0" a11y-label="pulled">
        <native:column :width="7" :height="7" class="rounded-full bg-theme-{{ $mineToken }}" />
        <native:column class="flex-1 h-[1.5] bg-theme-ink-muted/40" />
        <native:column :width="7" :height="7" class="rounded-full bg-theme-{{ $theirToken }}" />
    </native:row>
@elseif ($status === 'staged')
    <native:column ref="{{ $ref }}" :width="8" :height="8" class="rounded-full border border-dashed border-theme-{{ $mineToken }} flex-shrink-0" a11y-label="staged" />
@elseif ($status === 'rejected')
    <native:column ref="{{ $ref }}" :width="8" :height="8" class="rounded-full border border-theme-destructive flex-shrink-0" a11y-label="rejected" />
@else
    <native:column ref="{{ $ref }}" :width="7" :height="7" class="rounded-full bg-theme-{{ $mineToken }} flex-shrink-0" a11y-label="pushed" />
@endif
