<?php

namespace justinholtweb\carrierfreight\auth;

use Craft;
use justinholtweb\carrier\auth\BaseAuth;
use justinholtweb\carrier\models\Connection;

/**
 * A bearer token minted from some other credential, for the LTL carriers that are not quite
 * OAuth 2.0.
 *
 * ODFL trades an ODFL.com username and password (Basic) for a one-hour session token with a GET.
 * Estes trades an API key header plus MyEstes Basic credentials for a ten-hour token with a POST.
 * XPO wants a password grant with the consumer key and secret as Basic. Three shapes, one
 * lifecycle: fetch, cache for slightly less than the carrier says, forget on a 401 and try once
 * more. The carrier supplies the token request as a closure; this class owns the rest.
 *
 * Some carriers (Estes) want a static header on every call *as well as* the bearer token. Those
 * go in `$staticHeaders` as `settingName => headerName`, so they ride along even when no token
 * could be fetched — the carrier's own refusal is then more useful than a request that never left.
 */
class SessionTokenAuth extends BaseAuth
{
    /** Refresh this many seconds before the carrier says the token expires. */
    private const EXPIRY_SLACK = 60;

    /**
     * @param \Closure $tokenRequest `fn(Connection $c): array{0: string, 1: string, 2: array}` —
     *        the method, absolute URL and Transport options of the token request
     * @param string[] $requiredFields settings that must be filled before a token is attempted
     * @param string[] $tokenPaths dotted paths tried in order; vendors disagree with their own docs
     * @param int $defaultTtl seconds, when the answer carries no `expires_in`
     * @param array<string,string> $staticHeaders setting name => header name, sent on every call
     */
    public function __construct(
        private \Closure $tokenRequest,
        private array $requiredFields,
        private array $tokenPaths = ['access_token', 'token', 'sessionToken'],
        private int $defaultTtl = 3600,
        private array $staticHeaders = [],
    ) {
    }

    public function headers(): array
    {
        $headers = [];

        foreach ($this->staticHeaders as $field => $header) {
            $value = (string)$this->setting($field, '');

            if ($value !== '') {
                $headers[$header] = $value;
            }
        }

        $token = $this->accessToken();

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    public function isConfigured(): bool
    {
        foreach ($this->requiredFields as $field) {
            if ((string)$this->setting($field, '') === '') {
                return false;
            }
        }

        return true;
    }

    public function reauthenticate(): bool
    {
        $this->cacheForget('access');

        return $this->accessToken() !== null;
    }

    public function accessToken(): ?string
    {
        $cached = $this->cacheGet('access');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (!$this->isConfigured()) {
            return null;
        }

        [$method, $url, $options] = ($this->tokenRequest)($this->connection);
        $response = $this->http()->request($method, $url, $options);

        if (!$response->ok()) {
            Craft::warning('Carrier could not get a freight session token: ' . $response->errorMessage(), 'carrier');

            return null;
        }

        $token = '';

        foreach ($this->tokenPaths as $path) {
            $value = $response->at($path);

            if (is_string($value) && $value !== '') {
                $token = $value;
                break;
            }
        }

        // ODFL's guide never shows the key its token comes back under. A bare JSON string or a
        // plain-text body is the other shape a session endpoint commonly answers with.
        if ($token === '') {
            $body = trim($response->body);
            $decoded = json_decode($body, true);

            if (is_string($decoded) && $decoded !== '') {
                $token = $decoded;
            } elseif ($body !== '' && !str_starts_with($body, '{') && !str_starts_with($body, '[') && !str_contains($body, ' ')) {
                $token = $body;
            }
        }

        if ($token === '') {
            return null;
        }

        $ttl = (int)($response->at('expires_in') ?? $response->at('expiresIn') ?? $this->defaultTtl);
        $this->cacheSet('access', $token, max(30, $ttl - self::EXPIRY_SLACK));

        return $token;
    }

    /** For the closures: a connection setting with `$ENV_VAR` resolved. */
    public static function settingOf(Connection $connection, string $name): string
    {
        return (string)$connection->getSetting($name, '');
    }
}
