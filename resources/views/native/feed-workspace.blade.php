<native:adaptive-feed native:key="feed-workspace" :depth="count($conversations)" :intent-revision="$navigationRevision" @change="hostChanged" class="w-full h-full">
    <native:column native:key="workspace-feed" class="w-full h-full">
        <native:log key="feed" :embedded="true" />
    </native:column>
    @foreach ($conversations as $conversation)
        <native:adaptive-detail native:key="conversation-{{ $conversation['slot'] }}" :identity="'conversation-'.$conversation['slot']" :can-delete="$this->conversationCanDelete($conversation['slot'])" title="Commit" :subtitle="\App\Devnull\Display::shortHash($conversation['id'])" class="w-full h-full">
            <native:commit-detail :key="'conversation-'.$conversation['slot']" :id="$conversation['id']" :embedded="true" :conversation-data="$conversation['data']" />
        </native:adaptive-detail>
    @endforeach
</native:adaptive-feed>
