{{--
    A commit's poll, drawn as the web's CommitPoll. With results (you voted, it's yours, or it closed), each option is a
    hairline box with a bar of its share behind it, Blue Wash for the one you voted for with a check after its label,
    its percentage in mono on the right, and "4 votes · 3h left" under them all. Without results, the options are plain
    boxes over the time left.

    On someone else's published commit whose poll is open and has no vote of yours yet, you vote as on the web: tap an
    option to pick it, its box in Commit Blue on Blue Wash with its radio filled, then Vote, beside the time left,
    since votes are final (see VotesOnPolls). Vote spins while it goes, and the poll then shows its results. $static
    is the quote card's copy, which never votes.
--}}
@php($poll = $commit->poll)
@php($static ??= false)
@php($canVote = ! $static && $commit->canBeVotedOnBy($viewer ?? app(\App\Devnull\Account::class)->user()))
@php($choice = $pollChoices[$commit->id] ?? null)
@php($isVoting = in_array($commit->id, $voting ?? [], true))
<native:column ref="poll-{{ $commit->id }}" class="w-full mt-3 gap-2" a11y-label="{{ $poll->showsResults() ? 'Poll results' : 'Poll options' }}">
    @foreach ($poll->options as $option)
        @if ($poll->showsResults())
            <native:column ref="poll-option-{{ $option->id }}" class="w-full rounded-md border border-theme-hairline" a11y-label="{{ $option->label }}{{ $option->voted ? ', your vote' : '' }}, {{ $option->share() }}%, {{ $option->votesLabel() }}">
                @if ($option->share() > 0)
                    <native:row class="absolute top-0 bottom-0 left-0 right-0">
                        <native:column :flexGrow="$option->share()" :flexBasis="0" class="h-full rounded-md {{ $option->voted ? 'bg-theme-primary-soft' : 'bg-theme-wash' }}" />
                        {{-- EDGE grows a 0 flex-grow child that would fill the row (FlexContainer.swift effectiveGrow), so at 100% there's no spacer at all. --}}
                        @if ($option->share() < 100)
                            <native:column :flexGrow="100 - $option->share()" :flexBasis="0" />
                        @endif
                    </native:row>
                @endif
                <native:row class="w-full px-3 py-2 gap-[6] items-center">
                    <native:text :font="$option->voted ? 'medium' : null" class="flex-shrink text-[15px] text-theme-ink">{{ $option->label }}</native:text>
                    @if ($option->voted)
                        <native:icon ref="poll-voted-{{ $option->id }}" :ios="\App\Icons\Lucide::Check->ios()" :android="\App\Icons\Lucide::Check->android()" :size="16" class="text-theme-primary" />
                    @endif
                    <native:spacer />
                    <native:text font="mono" class="text-[13px] text-theme-ink">{{ $option->share() }}%</native:text>
                </native:row>
            </native:column>
        @elseif ($canVote)
            @php($chosen = $choice === $option->id)
            <native:pressable ref="poll-option-{{ $option->id }}" @press="choosePollOption('{{ $commit->id }}', '{{ $option->id }}')" a11y-label="{{ $option->label }}, option {{ $loop->iteration }} of {{ count($poll->options) }}{{ $chosen ? ', picked' : '' }}">
                <native:row class="w-full px-3 py-2 gap-3 items-center rounded-md border {{ $chosen ? 'border-theme-primary bg-theme-primary-soft' : 'border-theme-hairline' }}">
                    <native:column class="w-[16] h-[16] items-center justify-center rounded-full border {{ $chosen ? 'border-theme-primary' : 'border-theme-ink-muted' }}">
                        @if ($chosen)
                            <native:column ref="poll-picked-{{ $option->id }}" class="w-[8] h-[8] rounded-full bg-theme-primary" />
                        @endif
                    </native:column>
                    <native:text class="flex-1 text-[15px] text-theme-ink">{{ $option->label }}</native:text>
                </native:row>
            </native:pressable>
        @else
            <native:column ref="poll-option-{{ $option->id }}" class="w-full px-3 py-2 rounded-md border border-theme-hairline">
                <native:text class="w-full text-[15px] text-theme-ink">{{ $option->label }}</native:text>
            </native:column>
        @endif
    @endforeach

    @if ($canVote)
        <native:row class="w-full gap-3 items-center">
            <native:text ref="poll-summary-{{ $commit->id }}" font="mono" class="flex-1 text-[13px] text-theme-ink-muted">{{ $poll->status() }}</native:text>
            {{-- The label stays put while it spins, so the button keeps its size. --}}
            <native:button ref="poll-vote-{{ $commit->id }}" :variant="$myColour ?? 'primary'" size="sm" :loading="$isVoting" :disabled="$choice === null || $isVoting" @press="voteOnPoll('{{ $commit->id }}')">Vote</native:button>
        </native:row>
    @else
        <native:text ref="poll-summary-{{ $commit->id }}" font="mono" class="text-[13px] text-theme-ink-muted">{{ $poll->showsResults() ? $poll->summary() : $poll->status() }}</native:text>
    @endif
</native:column>
