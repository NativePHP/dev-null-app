<?php

namespace App\NativeComponents\Concerns;

/**
 * A tapped hashtag searches commits for it, as the web's links to its search
 * page with the hashtag, `#` included, as the term. Search opens in the tab it
 * was tapped in (see OpensInTab, which the screen uses too) with the hashtag
 * in its field and the commits for it loading.
 */
trait OpensHashtags
{
    public function openHashtag(string $hashtag): void
    {
        $this->openInTab('/search', ['query' => $hashtag]);
    }
}
