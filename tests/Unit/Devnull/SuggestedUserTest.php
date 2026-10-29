<?php

use App\Devnull\Data\SuggestedUser;

/**
 * @param  array<string, mixed>  $overrides
 */
function suggestedUser(array $overrides = []): SuggestedUser
{
    return SuggestedUser::fromArray(['id' => 'u2', 'handle' => 'ada', 'followers_count' => 0, 'followed_by_following_count' => 0, ...$overrides]);
}

it('says why someone is suggested as the web does', function (array $counts, ?string $reason) {
    expect(suggestedUser($counts)->reason())->toBe($reason);
})->with([
    'people you follow follow them' => [['followed_by_following_count' => 2, 'followers_count' => 40], 'Followed by 2 you follow'],
    'one follower' => [['followers_count' => 1], '1 follower'],
    'many followers, compact' => [['followers_count' => 1250], '1.3k followers'],
    'nobody' => [[], null],
]);

it('reads whether you follow them and whether they follow you', function () {
    $suggested = suggestedUser(['follows_you' => true]);

    expect($suggested->id)->toBe('u2')
        ->and($suggested->followsYou)->toBeTrue()
        ->and($suggested->isFollowed)->toBeFalse()
        ->and($suggested->withFollowed(true)->isFollowed)->toBeTrue()
        ->and(SuggestedUser::fromArray(['id' => 'u3', 'handle' => 'grace', 'followers_count' => 'many'])->followersCount)->toBe(0);
});
