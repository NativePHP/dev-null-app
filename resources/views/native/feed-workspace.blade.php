<native:adaptive-feed native:key="feed-workspace" :depth="count($conversations)" :intent-revision="$navigationRevision" @change="hostChanged" class="w-full h-full">
    <native:column native:key="workspace-feed" class="w-full h-full">
        <native:log key="feed" :embedded="true" :selected-commit="$this->selectedCommit()" />
    </native:column>
    <native:adaptive-placeholder native:key="workspace-placeholder" class="w-full h-full">
        <native:column ref="no-conversation" class="w-full h-full items-center justify-center px-8 gap-3 bg-theme-surface">
            <native:icon :ios="\App\Icons\Lucide::GitCommitVertical->ios()" :android="\App\Icons\Lucide::GitCommitVertical->android()" :size="32" class="text-theme-ink-muted" />
            <native:text font="semibold" class="text-[17px] text-center text-theme-ink">No commit selected</native:text>
            <native:text class="text-[15px] text-center leading-relaxed text-theme-ink-muted max-w-[320]">Choose a commit in the log to read its conversation here.</native:text>
        </native:column>
    </native:adaptive-placeholder>
    @foreach ($conversations as $conversation)
        <native:adaptive-detail native:key="conversation-{{ $conversation['slot'] }}" :identity="'conversation-'.$conversation['slot']" :can-delete="$this->conversationCanDelete($conversation['slot'])" title="Commit" :subtitle="\App\Devnull\Display::shortHash($conversation['id'])" class="w-full h-full">
            <native:commit-detail :key="'conversation-'.$conversation['slot']" :id="$conversation['id']" :embedded="true" :conversation-data="$conversation['data']" />
        </native:adaptive-detail>
    @endforeach
</native:adaptive-feed>
