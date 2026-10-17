<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;
use App\Devnull\Data\CommitThread;
use App\Devnull\Data\CurrentUser;
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

    public function __construct(private DeviceKey $deviceKey, private string $baseUrl, private ResponseCache $cache) {}

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
     */
    public function createCommit(string $body, ?string $parentId = null, ?string $quotedCommitId = null): Commit
    {
        $payload = array_filter(['body' => $body, 'parent_id' => $parentId, 'quoted_commit_id' => $quotedCommitId], fn (?string $value): bool => $value !== null);

        $commit = $this->data($this->send('POST', 'commits', payload: $payload));
        $this->cache->commitAdded($commit);

        return Commit::fromArray($commit);
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
     * Saves a commit privately, as the web's bookmark button does.
     */
    public function bookmark(string $id): Commit
    {
        return $this->react('PUT', 'commits/'.rawurlencode($id).'/bookmark', saving: true);
    }

    public function unbookmark(string $id): Commit
    {
        return $this->react('DELETE', 'commits/'.rawurlencode($id).'/bookmark', saving: true);
    }

    /**
     * The commits you saved, last saved first, as the web's Saved page lists them.
     *
     * @return Page<Commit>
     */
    public function saved(?string $cursor = null): Page
    {
        return Page::fromArray($this->get('saved', ['cursor' => $cursor]), Commit::fromArray(...));
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
        return Profile::fromArray($this->get('users/'.rawurlencode($username)));
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
        $target = $this->target($path, $query);

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
            : $pending->withBody($request->body, 'application/json');

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
        $json = $this->json($this->send('GET', $path, $query));
        $this->cache->put($path, $query, $json);

        return $json;
    }

    /**
     * Stars, unstars, saves or unsaves, and gives every kept copy of the commit the new counts.
     * Saving or unsaving puts it on top of your kept saved commits, or takes it out.
     */
    private function react(string $method, string $path, bool $saving = false): Commit
    {
        $commit = $this->data($this->send($method, $path));
        $this->cache->commitReacted($commit);

        if ($saving) {
            $this->cache->commitSaved($commit);
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

        return $response;
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
    private function sign(string $method, string $target, string $body): array
    {
        return (new RequestSigner($this->deviceKey->current()))
            ->headers($method, $target, $body, CarbonImmutable::now()->getTimestamp(), RequestSigner::nonce());
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
