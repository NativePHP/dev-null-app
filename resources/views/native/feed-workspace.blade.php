<native:adaptive-feed native:key="feed-workspace" :depth="count($conversations)" :intent-revision="$navigationRevision" @change="hostChanged" class="w-full h-full">
    <native:column native:key="workspace-feed" class="w-full h-full">
        <native:log key="feed" :embedded="true" :selected-commit="$this->selectedCommit()" />
    </native:column>
    <native:adaptive-placeholder native:key="workspace-placeholder" class="w-full h-full">
        <native:column class="w-full h-full justify-center bg-theme-surface">
            @include('native.partials.empty', ['ref' => 'no-conversation', 'command' => 'no commit selected', 'message' => 'Choose a commit in the log to read its conversation here.'])
        </native:column>
    </native:adaptive-placeholder>
    @foreach ($conversations as $conversation)
        <native:adaptive-detail native:key="conversation-{{ $conversation['slot'] }}" :identity="'conversation-'.$conversation['slot']" :can-delete="$this->conversationCanDelete($conversation['slot'])" title="Commit" :subtitle="\App\Devnull\Display::shortHash($conversation['id'])" class="w-full h-full">
            <native:commit-detail :key="'conversation-'.$conversation['slot']" :id="$conversation['id']" :embedded="true" :conversation-data="$conversation['data']" />
        </native:adaptive-detail>
    @endforeach
</native:adaptive-feed>
