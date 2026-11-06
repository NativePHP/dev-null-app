{{--
    Reply, a little way past the bubble on the side a pull uncovers (past its pin when it has one), so it's where you're
    looking. It's laid over the bubble's row as the row is tall, so it's centred on the bubble, and it goes with it. It
    grows from 40%, turns upright from a quarter turn and fades in as the pull goes, and is in place at
    Conversation::ReplyPull. Lying over the row rather than in it, it takes none of the bubble's room on either
    platform, so the bubble wraps where it would without it.
--}}
@if ($replyable)
    <native:stack ref="swipe-reply-{{ $key }}" class="absolute top-0 bottom-0 w-[30] {{ $mine ? '-left-[38]' : '-right-[38]' }}" center
        :scale="$mine ? $pan->interpolate([0, $pull], [0.4, 1]) : $pan->interpolate([-$pull, 0], [1, 0.4])"
        :rotate="$mine ? $pan->interpolate([0, $pull], [-90, 0]) : $pan->interpolate([-$pull, 0], [0, 90])"
        :opacity="$mine ? $pan->interpolate([0, $pull], [0, 1]) : $pan->interpolate([-$pull, 0], [1, 0])">
        <native:stack :width="30" :height="30" center class="rounded-full bg-theme-wash">
            <native:icon :ios="\App\Icons\Lucide::Reply->ios()" :android="\App\Icons\Lucide::Reply->android()" :size="16" class="text-theme-ink-muted" />
        </native:stack>
    </native:stack>
@endif
