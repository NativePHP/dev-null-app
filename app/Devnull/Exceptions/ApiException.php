<?php

namespace App\Devnull\Exceptions;

use App\Devnull\Data\User;
use App\Devnull\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A devnull API error, carrying the server's message and its code.
 *
 * What the app shows for it depends on the code (see ErrorCode), never on
 * the message's wording.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  int|null  $retryAfter  How many seconds the server asked to wait before trying again.
     * @param  string|null  $errorCode  The machine-readable code devnull sends next to the message, such as "device_not_approved".
     * @param  User|null  $user  The user devnull sends with the error, as it sends the one you blocked with user_blocked.
     */
    public function __construct(string $message, public readonly int $status = 0, public readonly array $errors = [], public readonly ?int $retryAfter = null, public readonly ?string $errorCode = null, public readonly ?User $user = null)
    {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        return self::fromParts($response->status(), $response->json(), $response->header('Retry-After'));
    }

    /**
     * The error devnull answered with, from its status, decoded body and Retry-After
     * header. A background refresh only has these, not the response itself.
     */
    public static function fromParts(int $status, mixed $json, string $retryAfter = ''): self
    {
        $json = is_array($json) ? $json : [];
        $message = $json['message'] ?? null;

        if (! is_string($message) || $message === '') {
            $message = "fatal: the remote end hung up unexpectedly (HTTP {$status})";
        }

        $errors = is_array($json['errors'] ?? null) ? $json['errors'] : [];
        $errors = array_map(fn (mixed $messages): array => array_values(array_filter((array) $messages, 'is_string')), $errors);
        $code = $json['code'] ?? null;
        $code = is_string($code) && $code !== '' ? $code : null;

        return match ($status) {
            401 => new Unauthenticated($message, 401, errorCode: $code),
            422 => new ApiException($message, 422, $errors, errorCode: $code),
            default => new ApiException($message, $status, retryAfter: self::retryAfter($retryAfter), errorCode: $code, user: is_array($json['user'] ?? null) ? User::fromArray($json['user']) : null),
        };
    }

    /**
     * What a request that never reached devnull says.
     */
    public static function couldNotConnect(string $baseUrl): self
    {
        return new self("fatal: unable to access '{$baseUrl}': could not connect to the server");
    }

    /**
     * What a successful status with something other than devnull's answer says,
     * such as a maintenance page (see Client::answeredByDevnull).
     */
    public static function unexpectedAnswer(string $baseUrl, int $status): self
    {
        return new self("fatal: unable to access '{$baseUrl}': unexpected answer (HTTP {$status})", $status);
    }

    /**
     * The Retry-After header, which may be a number of seconds or a date.
     */
    private static function retryAfter(string $header): ?int
    {
        $header = trim($header);

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $at = strtotime($header);

        return $at === false ? null : max(0, $at - CarbonImmutable::now()->getTimestamp());
    }

    /**
     * The code devnull sent, when the app knows it.
     */
    public function code(): ?ErrorCode
    {
        return $this->errorCode === null ? null : ErrorCode::tryFrom($this->errorCode);
    }

    public function is(ErrorCode $code): bool
    {
        return $this->code() === $code;
    }

    /**
     * What to show for it: the app's own words for a code it knows, else the
     * first validation message, else the server's message.
     */
    public function firstError(): string
    {
        if (($message = $this->code()?->message()) !== null) {
            return $message;
        }

        foreach ($this->errors as $messages) {
            if (isset($messages[0])) {
                return $messages[0];
            }
        }

        return $this->getMessage();
    }
}
