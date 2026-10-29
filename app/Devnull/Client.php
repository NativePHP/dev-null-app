<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;
use App\Devnull\Data\CommitThread;
use App\Devnull\Data\Connection;
use App\Devnull\Data\CurrentUser;
use App\Devnull\Data\DraftImage;
use App\Devnull\Data\MessageVoice;
use App\Devnull\Data\Notification;
use App\Devnull\Data\Page;
use App\Devnull\Data\Profile;
use App\Devnull\Data\User;
use App\Devnull\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * The devnull API, signed with this device's SSH key.
 *
 * First pages it fetches are kept in the response cache, and the writes it
 * makes patch the kept copies they change, so every screen can show the
 * latest it knows straight away.
 */
final readonly class Client
{
    /**
     * Where the app talks to unless DEVNULL_URL says otherwise.
     */
    public const string ProductionUrl = 'https://dev-null.sh';

    /**
     * The one place the sign-in's redeem endpoint lives.
     */
    public const string SignInPath = 'devices';

    /**
     * Where the signed-in account's Apple ID is linked and unlinked.
     */
    public const string ApplePath = 'me/apple';

    /**
     * Where the signed-in account's profile is synced from GitHub again, as
     * Sync from GitHub does on the web.
     */
    public const string GitHubPath = 'me/github';

    /**
     * Where the signed-in account's colour, its lane on the graph, is saved.
     */
    public const string LanePath = 'me/lane';

    /**
     * The people the signed-in account mutes, and blocks, as the web's
     * Settings → Safety lists them.
     */
    public const string MutedPath = 'me/muted';

    public const string BlockedPath = 'me/blocked';

    /**
     * Who to follow: three people to show and three spares, the next page of the day's picks for each ?round=.
     */
    public const string SuggestedUsersPath = 'me/suggested-users';

    /**
     * Where pastéis mode is switched on and off for the signed-in account.
     */
    public const string PasteisModePath = 'me/pasteis-mode';

    /**
     * Where a draft is shown as the commit it would become, as the web's composer previews it.
     */
    public const string PreviewPath = 'commits/preview';

    /**
     * Your Remotes inbox, and where Remotes are switched on and off and their settings saved, as on the web.
     */
    public const string RemotesPath = 'remotes';

    public const string RemotesSwitchPath = 'me/remotes';

    public const string RemotePreferencesPath = 'me/remote-preferences';

    /** Who you could start a remote or an org with, as the web's pickers ask. */
    public const string RemoteCandidatesPath = 'remotes/candidates';

    public const string OrgsPath = 'remotes/orgs';

    /**
     * Where a `/feature` or `/bug` draft goes, to be opened as an issue upstream.
     */
    public const string IssuesPath = 'issues';

    /**
     * How many replies the app asks for at a time. devnull sends 50 a page
     * unless it's asked for fewer, and the app never asks for more than this.
     */
    public const int RepliesPerPage = 5;

    public function __construct(private DeviceKey $deviceKey, private string $baseUrl, private ResponseCache $cache) {}

    /**
     * Where a vote on a commit's poll goes. devnull answers with the commit, whose poll then shows its results.
     */
    public static function pollVotesPath(string $id): string
    {
        return 'commits/'.rawurlencode($id).'/poll/votes';
    }

    /**
     * Where a Remotes thread lives: with a person, by their username, or an org, by its id. Its messages are pushed
     * to the same path, and its read, typing and stash live beside it.
     */
    public static function threadPath(string $username = '', ?string $orgId = null): string
    {
        return $orgId === null ? 'remotes/@'.rawurlencode($username) : 'remotes/orgs/'.rawurlencode($orgId);
    }

    /**
     * Where a message's reactions are left and taken back.
     */
    public static function reactionsPath(int $messageId): string
    {
        return 'remotes/messages/'.$messageId.'/reactions';
    }

    /**
     * Where an org's maintainer pins a message for everyone in it, and unpins it.
     */
    public static function pinPath(int $messageId): string
    {
        return 'remotes/messages/'.$messageId.'/pin';
    }

    /**
     * Where a pastel is given to a commit, one per request.
     */
    public static function pastelPath(string $id): string
    {
        return 'commits/'.rawurlencode($id).'/pasteis';
    }

    /**
     * Trades the code dev-null.sh sent back, with the PKCE verifier only this
     * phone knows, for the user it approved this phone for. It's signed like
     * every request, so the code only works for the key it was made for.
     */
    public function redeemSignIn(string $code, #[SensitiveParameter] string $verifier): User
    {
        return User::fromArray($this->data($this->send('POST', self::SignInPath, payload: ['code' => $code, 'code_verifier' => $verifier])));
    }

    /**
     * Signs in on a phone devnull doesn't know yet with an Apple ID linked to
     * an account, which approves this phone's key. Apple's identity token
     * carries sha256 of the nonce, and devnull checks it against the raw one.
     */
    public function signInWithApple(#[SensitiveParameter] string $identityToken, #[SensitiveParameter] string $nonce, string $deviceName): User
    {
        return User::fromArray($this->data($this->send('POST', self::SignInPath, payload: [
            'apple_identity_token' => $identityToken,
            'nonce' => $nonce,
            'device_name' => $deviceName,
        ])));
    }

    /**
     * Links an Apple ID to the account this phone is signed in as. Returns
     * whether devnull now has one linked.
     */
    public function linkApple(#[SensitiveParameter] string $identityToken, #[SensitiveParameter] string $nonce): bool
    {
        $linked = $this->appleLinked($this->send('PUT', self::ApplePath, payload: ['identity_token' => $identityToken, 'nonce' => $nonce]));
        $this->cache->appleLinkChanged($linked);

        return $linked;
    }

    /**
     * Unlinks the account's Apple ID. Returns whether one is still linked.
     */
    public function unlinkApple(): bool
    {
        $linked = $this->appleLinked($this->send('DELETE', self::ApplePath));
        $this->cache->appleLinkChanged($linked);

        return $linked;
    }

    public function me(): CurrentUser
    {
        return CurrentUser::fromArray($this->get('me'));
    }

    /**
     * Deletes the account this phone is signed in as, the way the web's Delete
     * account does. devnull deletes its devices with it, so this phone's key
     * stops working.
     */
    public function deleteAccount(): void
    {
        $this->send('DELETE', 'me');
    }

    /**
     * @return Page<Commit>
     */
    public function log(string $log, ?string $cursor = null): Page
    {
        return Page::fromArray($this->get('log/'.rawurlencode($log), ['cursor' => $cursor]), Commit::fromArray(...));
    }

    public function commit(string $id): CommitThread
    {
        return CommitThread::fromArray($this->get('commits/'.rawurlencode($id)));
    }

    /**
     * @return Page<Commit>
     */
    public function replies(string $id, ?string $cursor = null): Page
    {
        return Page::fromArray($this->get('commits/'.rawurlencode($id).'/replies', ['cursor' => $cursor]), Commit::fromArray(...));
    }

    /**
     * Commits, as a reply to $parentId or quoting $quotedCommitId, as the web's
     * composer, reply box and Quote do. devnull takes one or the other, not both.
     * A new commit or a quote may start a poll (see PollDraft::payload()).
     *
     * @param  array{options: list<string>, duration: int}|null  $poll
     */
    public function createCommit(string $body, ?string $parentId = null, ?string $quotedCommitId = null, ?array $poll = null): Commit
    {
        $commit = $this->data($this->send('POST', 'commits', payload: self::commitPayload($body, $parentId, $quotedCommitId, poll: $poll)));
        $this->cache->commitAdded($commit);

        return Commit::fromArray($commit);
    }

    /**
     * Signs a commit without sending it, for the outbox to send from the
     * background, as createCommit() would send it (see Outbox).
     *
     * @param  array{options: list<string>, duration: int}|null  $poll
     */
    public function signedCommit(string $body, ?string $parentId = null, ?string $quotedCommitId = null, ?array $poll = null): SignedRequest
    {
        return $this->signedWrite('POST', 'commits', self::commitPayload($body, $parentId, $quotedCommitId, poll: $poll));
    }

    /**
     * Signs a commit with images without sending it, for the background to
     * send while the sheet says it's under way, since photos take a while to
     * upload (see signedWithImages()).
     *
     * @param  list<DraftImage>  $images
     */
    public function signedCommitWithImages(string $body, array $images, ?string $parentId = null, ?string $quotedCommitId = null): SignedRequest
    {
        return $this->signedWithImages('commits', self::commitPayload($body, $parentId, $quotedCommitId), $images);
    }

    /**
     * Signs a message for a Remotes thread without sending it, for the outbox
     * to send from the background. devnull stores it once per client id, so
     * it can go again. One with photos goes as a commit's do (see
     * signedWithImages()), and may have no text. A voice note goes the same
     * way, alone, with its waveform and no text (see signedWithVoice()).
     *
     * @param  list<DraftImage>  $images
     */
    public function signedMessage(string $threadPath, string $body, string $clientId, ?int $replyToId = null, array $images = [], ?MessageVoice $voice = null): SignedRequest
    {
        $payload = array_filter(['body' => $body, 'client_id' => $clientId, 'reply_to_id' => $replyToId], fn (mixed $value): bool => $value !== null);

        return match (true) {
            $voice !== null => $this->signedWithVoice($threadPath.'/messages', $clientId, $replyToId, $voice),
            $images !== [] => $this->signedWithImages($threadPath.'/messages', $payload, $images),
            default => $this->signedWrite('POST', $threadPath.'/messages', $payload),
        };
    }

    public function deleteCommit(string $id): void
    {
        $this->send('DELETE', 'commits/'.rawurlencode($id));
        $this->cache->commitDeleted($id);
    }

    public function star(string $id): Commit
    {
        return $this->react('POST', 'commits/'.rawurlencode($id).'/star');
    }

    public function unstar(string $id): Commit
    {
        return $this->react('DELETE', 'commits/'.rawurlencode($id).'/star');
    }

    /**
     * Pins your commit to the top of your profile, as the web's Pin does,
     * taking the place of any commit pinned before.
     */
    public function pin(string $id): Commit
    {
        return $this->react('PUT', 'commits/'.rawurlencode($id).'/pin', starring: false);
    }

    public function unpin(string $id): Commit
    {
        return $this->react('DELETE', 'commits/'.rawurlencode($id).'/pin', starring: false);
    }

    /**
     * The commits you starred, newest first, as the web's Starred page lists them.
     *
     * @return Page<Commit>
     */
    public function starred(?string $cursor = null): Page
    {
        return Page::fromArray($this->get('starred', ['cursor' => $cursor]), Commit::fromArray(...));
    }

    /**
     * A page of your Remotes inbox, newest first, for the web's Open, Fetched
     * or Stashed tab, continuing before a conversation.
     *
     * @return array<string, mixed>
     */
    public function remotes(?string $tab = null, ?string $before = null): array
    {
        return $this->get(self::RemotesPath, ['tab' => $tab, 'before' => $before]);
    }

    /**
     * People matching a term, each with the web's reason you can't message
     * them, or add them to a new org or the org $orgId, when there is one.
     * Never kept: who you can reach changes as people follow, block and turn
     * Remotes off.
     *
     * @return list<array{user: User, reason: string|null}>
     */
    public function remoteCandidates(string $term, string $for, ?string $orgId = null): array
    {
        $query = $orgId === null ? ['q' => $term, 'for' => $for] : ['q' => $term, 'org' => $orgId];
        $candidates = $this->json($this->send('GET', self::RemoteCandidatesPath, $query))['candidates'] ?? null;

        return array_values(array_map(
            fn (array $candidate): array => [
                'user' => User::fromArray($candidate['user']),
                'reason' => is_string($candidate['reason'] ?? null) ? $candidate['reason'] : null,
            ],
            array_filter(is_array($candidates) ? $candidates : [], fn (mixed $candidate): bool => is_array($candidate) && is_array($candidate['user'] ?? null)),
        ));
    }

    /**
     * Starts an org, as the web's New org does: you maintain it, and the
     * people named contribute. Answers its id.
     *
     * @param  list<string>  $members
     */
    public function createOrg(?string $name, array $members): string
    {
        $org = $this->json($this->send('POST', self::OrgsPath, payload: ['name' => $name, 'members' => $members]))['org'] ?? null;

        return is_array($org) && is_string($org['id'] ?? null) ? $org['id'] : '';
    }

    /**
     * Where an org's members are added, and where one of them is given a role or taken out, which is how you leave
     * it too, by your own username.
     */
    public static function orgMembersPath(string $orgId, ?string $username = null): string
    {
        return self::threadPath(orgId: $orgId).'/members'.($username === null ? '' : '/'.rawurlencode($username));
    }

    /**
     * The messages of a Remotes thread before one, as the web loads them when
     * you scroll back.
     *
     * @return array<string, mixed>
     */
    public function olderMessages(string $threadPath, int $before): array
    {
        return $this->get($threadPath.'/messages', ['before' => (string) $before]);
    }

    /**
     * Reports a commit to devnull's moderators, privately, as the web's Report
     * button does. Nothing on the commit changes, so nothing kept changes
     * either. Reporting one again keeps the first report.
     */
    public function reportCommit(string $id, ReportReason $reason, string $details = ''): void
    {
        $payload = $details === '' ? ['reason' => $reason->value] : ['reason' => $reason->value, 'details' => $details];

        $this->send('POST', 'commits/'.rawurlencode($id).'/reports', payload: $payload);
    }

    public function profile(string $username): Profile
    {
        return Profile::fromArray($this->profileJson($username));
    }

    /**
     * Someone's profile as devnull sent it, for keeping as it is (see Account::rememberProfile()).
     *
     * @return array<string, mixed>
     */
    public function profileJson(string $username): array
    {
        return $this->get('users/'.rawurlencode($username));
    }

    /**
     * A tab of someone's profile: their commits, or with ?replies=1 their replies.
     * The server ignores anything else, so a wrong parameter would quietly show commits.
     *
     * @return Page<Commit>
     */
    public function userCommits(string $username, string $tab = 'commits', ?string $cursor = null): Page
    {
        $query = ['replies' => $tab === 'replies' ? '1' : null, 'cursor' => $cursor];

        return Page::fromArray($this->get('users/'.rawurlencode($username).'/commits', $query), Commit::fromArray(...));
    }

    /**
     * The people following someone, or with 'following' the people they
     * follow, newest follow first as the web lists them, 30 a page.
     *
     * @return Page<Connection>
     */
    public function connections(string $username, string $type, ?string $cursor = null): Page
    {
        return Page::fromArray($this->get(self::connectionsPath($username, $type), ['cursor' => $cursor]), Connection::fromArray(...));
    }

    /**
     * Where someone's followers are, or with 'following' the people they follow.
     */
    public static function connectionsPath(string $username, string $type): string
    {
        return 'users/'.rawurlencode($username).'/'.($type === 'following' ? 'following' : 'followers');
    }

    public function follow(string $username): void
    {
        $this->send('POST', 'users/'.rawurlencode($username).'/follow');
        $this->cache->followChanged(rawurlencode($username), true);
    }

    public function unfollow(string $username): void
    {
        $this->send('DELETE', 'users/'.rawurlencode($username).'/follow');
        $this->cache->followChanged(rawurlencode($username), false);
    }

    /**
     * Not interested, as the web's who to follow says it: devnull never suggests them again.
     */
    public function dismissSuggestion(string $username): void
    {
        $this->send('POST', 'users/'.rawurlencode($username).'/dismiss');
    }

    /**
     * Mutes someone, as the web's Mute does. It's silent: they aren't told,
     * and the follows between you stay. Their commits leave your logs.
     */
    public function mute(string $username): void
    {
        $this->cache->muteChanged($this->json($this->send('POST', 'users/'.rawurlencode($username).'/mute')));
    }

    public function unmute(string $username): void
    {
        $this->cache->muteChanged($this->json($this->send('DELETE', 'users/'.rawurlencode($username).'/mute')));
    }

    /**
     * Blocks someone, as the web's Block does, which ends the follows both
     * ways. Neither of you sees the other's profile or commits after it.
     */
    public function block(string $username): void
    {
        $this->cache->blockChanged($this->json($this->send('POST', 'users/'.rawurlencode($username).'/block')));
    }

    /**
     * Unblocking doesn't bring back the follows, as on the web.
     */
    public function unblock(string $username): void
    {
        $this->cache->blockChanged($this->json($this->send('DELETE', 'users/'.rawurlencode($username).'/block')));
    }

    /**
     * The people you mute, last muted first.
     *
     * @return Page<User>
     */
    public function muted(?string $cursor = null): Page
    {
        return Page::fromArray($this->get(self::MutedPath, ['cursor' => $cursor]), User::fromArray(...));
    }

    /**
     * The people you block, last blocked first.
     *
     * @return Page<User>
     */
    public function blocked(?string $cursor = null): Page
    {
        return Page::fromArray($this->get(self::BlockedPath, ['cursor' => $cursor]), User::fromArray(...));
    }

    /**
     * Your notifications grouped as the web groups them, filtered by the web's
     * ?type= (all, replies, quotes, stars, mentions, follows or moderation).
     * Listing any of them marks them all read.
     *
     * @return Page<Notification>
     */
    public function notifications(string $type = 'all', ?string $cursor = null): Page
    {
        return Page::fromArray($this->get('notifications', self::notificationsQuery($type, $cursor)), Notification::fromArray(...));
    }

    /**
     * Every notification in the group led by $id, with the same filter, as the web expands a group.
     *
     * @return Page<Notification>
     */
    public function notificationGroup(string $id, string $type = 'all', ?string $cursor = null): Page
    {
        return Page::fromArray($this->get('notifications/'.rawurlencode($id).'/group', self::notificationsQuery($type, $cursor)), Notification::fromArray(...));
    }

    /**
     * All is the default, so it's left off, as the web's own links leave it off.
     *
     * @return array<string, string|null>
     */
    public static function notificationsQuery(string $type, ?string $cursor = null): array
    {
        return ['type' => $type === 'all' ? null : $type, 'cursor' => $cursor];
    }

    /**
     * Signs a GET without sending it, for a refresh to send from the background.
     *
     * @param  array<string, string|null>  $query
     */
    public function signedGet(string $path, array $query = []): SignedRequest
    {
        $target = $this->target($path, self::paged($path, $query));

        return new SignedRequest($this->origin().$target, $this->sign('GET', $target, ''));
    }

    /**
     * Signs a write without sending it, for the background to send while the
     * screen says it's under way. The body is the exact JSON that was signed.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function signedWrite(string $method, string $path, ?array $payload = null): SignedRequest
    {
        $target = $this->target($path, []);
        $body = self::body($payload);

        return new SignedRequest($this->origin().$target, $this->sign($method, $target, $body), $method, $body);
    }

    /**
     * Signs a write with images, as devnull takes them from the `devnull
     * commit` script: multipart, the JSON body in a payload part beside the
     * images, with the SHA-256 of each image signed on a line before it so the
     * signature covers them too. Descriptions go along when any photo has one.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<DraftImage>  $images
     */
    private function signedWithImages(string $path, array $payload, array $images): SignedRequest
    {
        $target = $this->target($path, []);
        $descriptions = array_map(fn (DraftImage $image): ?string => $image->isDescribed() ? trim($image->description) : null, $images);
        $payload = self::body(array_filter($descriptions) === [] ? $payload : [...$payload, 'image_descriptions' => $descriptions]);
        $files = array_map(fn (DraftImage $image, int $index): array => [
            'images[]', 'image-'.($index + 1).'.'.substr($image->mimeType, 6), $image->mimeType, (string) file_get_contents($image->path),
        ], $images, array_keys($images));

        return $this->signedMultipart($target, $payload, $files);
    }

    /**
     * Signs a voice note, as devnull takes one: multipart like a write with
     * images, the recording alone in a voice part, its SHA-256 the line
     * signed before the body, and the waveform the phone drew from it in the
     * payload, since devnull never decodes it.
     */
    private function signedWithVoice(string $path, string $clientId, ?int $replyToId, MessageVoice $voice): SignedRequest
    {
        $payload = array_filter(['client_id' => $clientId, 'reply_to_id' => $replyToId, 'voice_waveform' => $voice->waveform], fn (mixed $value): bool => $value !== null);

        return $this->signedMultipart($this->target($path, []), self::body($payload), [
            ['voice', 'voice-note.wav', 'audio/wav', (string) file_get_contents($voice->url)],
        ]);
    }

    /**
     * The payload part, then each file, with the files' SHA-256 signed on a line before the body.
     *
     * @param  list<array{string, string, string, string}>  $files  each one's field, filename, type and bytes
     */
    private function signedMultipart(string $target, string $payload, array $files): SignedRequest
    {
        $boundary = 'devnull-'.bin2hex(random_bytes(16));

        $part = fn (string $name, string $filename, string $type, string $bytes): string => "--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n"
            ."Content-Type: {$type}\r\n\r\n{$bytes}\r\n";

        $body = $part('payload', 'payload.json', 'application/json', $payload);

        foreach ($files as [$name, $filename, $type, $bytes]) {
            $body .= $part($name, $filename, $type, $bytes);
        }

        $hashes = implode(',', array_map(fn (array $file): string => hash('sha256', $file[3]), $files));

        return new SignedRequest(
            $this->origin().$target,
            $this->sign('POST', $target, $hashes."\n".$payload, RequestSigner::ImagesNamespace),
            'POST',
            $body."--{$boundary}--\r\n",
            "multipart/form-data; boundary={$boundary}",
        );
    }

    /**
     * Sends a request signed elsewhere, exactly as signed: the body as the
     * bytes that were signed, or no body and no Content-Type when there was
     * none. Returns the response whatever its status.
     *
     * @throws ConnectionException
     */
    public static function sendSigned(SignedRequest $request): Response
    {
        $pending = Http::acceptJson()->withHeaders($request->headers)->timeout(20);

        $pending = $request->body === ''
            ? $pending->withRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withoutHeader('Content-Type'))
            : $pending->withBody($request->body, $request->contentType);

        return $pending->send($request->method, $request->url);
    }

    /**
     * A signed GET's JSON. A first page is kept for next time.
     *
     * @param  array<string, string|null>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $json = $this->json($this->send('GET', $path, self::paged($path, $query)));
        $this->cache->put($path, $query, $json);
        ImageCache::prefetch($json);

        return $json;
    }

    /**
     * Stars, unstars, pins or unpins, and gives every kept copy of the commit the new counts.
     * Starring or unstarring updates your kept starred commits too.
     */
    private function react(string $method, string $path, bool $starring = true): Commit
    {
        $commit = $this->data($this->send($method, $path));
        $this->cache->commitReacted($commit);

        if ($starring) {
            $this->cache->commitStarred($commit);
        }

        return Commit::fromArray($commit);
    }

    /**
     * Sends a signed request. The query string and the body are built here and
     * sent as the exact bytes that were signed, so nothing can re-encode them.
     *
     * @param  array<string, string|null>  $query
     * @param  array<string, mixed>|null  $payload
     */
    private function send(string $method, string $path, array $query = [], ?array $payload = null): Response
    {
        $target = $this->target($path, $query);
        $body = self::body($payload);
        $headers = $this->sign($method, $target, $body);

        $request = Http::acceptJson()->withHeaders($headers)->timeout(20);

        $request = $payload === null
            ? $request->withRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withoutHeader('Content-Type'))
            : $request->withBody($body, 'application/json');

        try {
            $response = $request->send($method, $this->origin().$target);
        } catch (ConnectionException) {
            throw ApiException::couldNotConnect($this->baseUrl);
        }

        if (! $response->successful()) {
            throw ApiException::fromResponse($response);
        }

        if (! self::answeredByDevnull($response, $method)) {
            throw ApiException::unexpectedAnswer($this->baseUrl, $response->status());
        }

        return $response;
    }

    /**
     * What a commit sends, leaving out what it doesn't have.
     *
     * @param  array{options: list<string>, duration: int}|null  $poll
     * @return array<string, mixed>
     */
    private static function commitPayload(string $body, ?string $parentId, ?string $quotedCommitId, ?array $poll = null): array
    {
        return array_filter([
            'body' => $body,
            'parent_id' => $parentId,
            'quoted_commit_id' => $quotedCommitId,
            'poll' => $poll,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Whether a successful response is devnull's API answering, and not a page
     * served in its place, such as the HTML that dev-null.sh answers every URL
     * with, and a 200, while it's down for maintenance. devnull answers a GET
     * with a JSON object, never an empty one, and a write with one or with no
     * body at all. Anything else counts as a failed request, so it's never kept
     * over what the app had.
     */
    public static function answeredByDevnull(Response $response, string $method): bool
    {
        $json = $response->json();

        if (is_array($json) && ! array_is_list($json)) {
            return true;
        }

        return $method !== 'GET' && $response->body() === '';
    }

    /**
     * A request's JSON body, or nothing for a request without one.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private static function body(?array $payload): string
    {
        return $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{X-Devnull-Timestamp: string, X-Devnull-Nonce: string, X-SSH-Signature: string}
     */
    private function sign(string $method, string $target, string $body, string $namespace = RequestSigner::Namespace): array
    {
        return (new RequestSigner($this->deviceKey->current()))
            ->headers($method, $target, $body, CarbonImmutable::now()->getTimestamp(), RequestSigner::nonce(), $namespace);
    }

    /**
     * A GET's query with the page size the app asks for: a page of replies
     * holds RepliesPerPage. The size isn't part of what the answer's kept
     * under, as every page of replies has it.
     *
     * @param  array<string, string|null>  $query
     * @return array<string, string|null>
     */
    private static function paged(string $path, array $query): array
    {
        return preg_match('#^commits/[^/]+/replies$#', $path) === 1 ? [...$query, 'limit' => (string) self::RepliesPerPage] : $query;
    }

    /**
     * The request target: the path as the server sees it, plus the query string.
     *
     * @param  array<string, string|null>  $query
     */
    private function target(string $path, array $query): string
    {
        $prefix = rtrim((string) parse_url($this->baseUrl, PHP_URL_PATH), '/');
        $query = array_filter($query, fn (?string $value): bool => $value !== null && $value !== '');

        return $prefix.'/api/v1/'.$path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    private function origin(): string
    {
        $parts = parse_url($this->baseUrl);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function appleLinked(Response $response): bool
    {
        return ($this->json($response)['apple_linked'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        $data = $this->json($response)['data'] ?? null;

        return is_array($data) ? $data : [];
    }
}
