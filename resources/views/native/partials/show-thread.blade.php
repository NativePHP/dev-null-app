{{--
    "Show thread" under a commit its author carried on in replies to themselves, as the web's ShowThreadLink: it opens
    the commit's page, where the thread reads in order (see SelfThread).
--}}
<native:pressable ref="show-thread-{{ $commit->id }}" @press="openCommit('{{ $commit->id }}')" class="mt-1 -ml-2 px-2 py-1 flex-row gap-1 items-center self-start" a11y-label="Show thread by {{ $commit->author->handle() }}">
    <native:icon :ios="\App\Icons\Lucide::ListTree->ios()" :android="\App\Icons\Lucide::ListTree->android()" :size="14" class="text-theme-ink-muted" />
    <native:text class="text-[13px] text-theme-ink-muted">Show thread</native:text>
</native:pressable>
