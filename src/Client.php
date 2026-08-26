<?php

declare(strict_types=1);

namespace Igzard\CodeStorage;

use Firebase\JWT\JWT;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Igzard\CodeStorage\Enum\Op;
use Igzard\CodeStorage\Enum\Permission;
use Igzard\CodeStorage\Internal\ApiFetcher;
use Igzard\CodeStorage\Internal\Arr;
use Igzard\CodeStorage\Internal\Uuid;
use Igzard\CodeStorage\Model\BaseRepo;
use Igzard\CodeStorage\Model\DeleteRepoResult;
use Igzard\CodeStorage\Model\ForkBaseRepo;
use Igzard\CodeStorage\Model\GenericGitBaseRepo;
use Igzard\CodeStorage\Model\GitCredential;
use Igzard\CodeStorage\Model\GitHubBaseRepo;
use Igzard\CodeStorage\Model\ListReposResult;
use Igzard\CodeStorage\Model\RefPolicy;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/** Pierre Code Storage client. */
final class Client
{
    public const DEFAULT_API_VERSION = 1;

    /** TTL used for the short-lived tokens minted per API call. */
    public const DEFAULT_TOKEN_TTL = 3600;

    /** TTL used for remote URL tokens when none is configured. */
    public const DEFAULT_JWT_TTL = 365 * 24 * 3600;

    private const API_BASE_URL_TEMPLATE = 'https://api.{{org}}.code.storage';

    private const STORAGE_BASE_URL_TEMPLATE = '{{org}}.code.storage';

    public readonly string $apiBaseUrl;

    public readonly string $storageBaseUrl;

    private readonly ApiFetcher $api;

    private readonly ?OpenSSLAsymmetricKey $privateKey;

    /**
     * @param  string  $name  Organisation name.
     * @param  string|null  $key  EC private key in PEM format. Required unless $token is set.
     * @param  string|null  $token  Pre-minted JWT, used verbatim if set.
     * @param  int|null  $defaultTtl  Default remote URL token lifetime in seconds.
     */
    public function __construct(
        public readonly string $name,
        ?string $key = null,
        private readonly ?string $token = null,
        ?string $apiBaseUrl = null,
        ?string $storageBaseUrl = null,
        public readonly int $apiVersion = self::DEFAULT_API_VERSION,
        private readonly ?int $defaultTtl = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('git storage requires a name');
        }
        if (trim((string) $key) === '' && trim((string) $token) === '') {
            throw new InvalidArgumentException('git storage requires either a key or a token');
        }

        $this->privateKey = trim((string) $key) === '' ? null : self::parseEcPrivateKey((string) $key);
        $this->apiBaseUrl = $apiBaseUrl ?? self::defaultApiBaseUrl($name);
        $this->storageBaseUrl = $storageBaseUrl ?? self::defaultStorageBaseUrl($name);

        $this->api = new ApiFetcher(
            $this->apiBaseUrl,
            $this->apiVersion,
            $httpClient ?? Psr18ClientDiscovery::find(),
            $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory(),
            $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory(),
        );
    }

    public static function defaultApiBaseUrl(string $name): string
    {
        return str_replace('{{org}}', $name, self::API_BASE_URL_TEMPLATE);
    }

    public static function defaultStorageBaseUrl(string $name): string
    {
        return str_replace('{{org}}', $name, self::STORAGE_BASE_URL_TEMPLATE);
    }

    /** Creates a new repository. */
    public function createRepo(
        ?string $id = null,
        ?BaseRepo $baseRepo = null,
        ?string $defaultBranch = null,
        ?int $ttl = null,
    ): Repo {
        $repoId = trim((string) $id) !== '' ? (string) $id : Uuid::v4();
        $ttl = $this->invocationTtl($ttl);
        $jwt = $this->generateJwt($repoId, [Permission::RepoWrite], $ttl);

        $payload = null;
        $isFork = false;
        $resolvedDefaultBranch = '';

        if ($baseRepo instanceof ForkBaseRepo) {
            $isFork = true;
            $payload = [
                'provider' => 'code',
                'owner' => $this->name,
                'name' => $baseRepo->id,
                'operation' => 'fork',
                'auth' => ['token' => $this->generateJwt($baseRepo->id, [Permission::GitRead], $ttl)],
            ];
            if (trim((string) $baseRepo->ref) !== '') {
                $payload['ref'] = $baseRepo->ref;
            }
            if (trim((string) $baseRepo->sha) !== '') {
                $payload['sha'] = $baseRepo->sha;
            }
            if (trim((string) $defaultBranch) !== '') {
                $resolvedDefaultBranch = (string) $defaultBranch;
            }
        } elseif ($baseRepo instanceof GitHubBaseRepo) {
            $payload = [
                'provider' => $baseRepo->provider->value,
                'owner' => $baseRepo->owner,
                'name' => $baseRepo->name,
            ];
            if ($baseRepo->authType !== null) {
                $payload['auth'] = ['auth_type' => $baseRepo->authType->value];
            }
            if (trim((string) $baseRepo->defaultBranch) !== '') {
                $payload['default_branch'] = $baseRepo->defaultBranch;
                $resolvedDefaultBranch = (string) $baseRepo->defaultBranch;
            }
        } elseif ($baseRepo instanceof GenericGitBaseRepo) {
            $payload = [
                'provider' => $baseRepo->provider->value,
                'owner' => $baseRepo->owner,
                'name' => $baseRepo->name,
            ];
            if (trim((string) $baseRepo->defaultBranch) !== '') {
                $payload['default_branch'] = $baseRepo->defaultBranch;
                $resolvedDefaultBranch = (string) $baseRepo->defaultBranch;
            }
            if (trim((string) $baseRepo->upstreamHost) !== '') {
                $payload['upstream_host'] = $baseRepo->upstreamHost;
            }
        } elseif ($baseRepo !== null) {
            throw new InvalidArgumentException('unsupported base repo type');
        }

        if ($resolvedDefaultBranch === '') {
            if (trim((string) $defaultBranch) !== '') {
                $resolvedDefaultBranch = (string) $defaultBranch;
            } elseif (! $isFork) {
                $resolvedDefaultBranch = 'main';
            }
        }

        $body = null;
        if ($payload !== null || $resolvedDefaultBranch !== '') {
            $body = [];
            if ($payload !== null) {
                $body['base_repo'] = $payload;
            }
            if ($resolvedDefaultBranch !== '') {
                $body['default_branch'] = $resolvedDefaultBranch;
            }
        }

        $response = $this->api->post('repos', $body, $jwt, [409]);
        if ($response->getStatusCode() === 409) {
            throw new RuntimeException('repository already exists');
        }

        return $this->repo(
            $repoId,
            $resolvedDefaultBranch !== '' ? $resolvedDefaultBranch : 'main',
            gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * Lists repositories for the org.
     *
     * @param  string|null  $q  Case-insensitive substring matched against the repository URL.
     */
    public function listRepos(
        ?string $cursor = null,
        ?int $limit = null,
        ?string $q = null,
        ?int $ttl = null,
    ): ListReposResult {
        $jwt = $this->generateJwt('org', [Permission::OrgRead], $this->invocationTtl($ttl));

        $query = [];
        if (trim((string) $cursor) !== '') {
            $query['cursor'] = (string) $cursor;
        }
        if ($limit !== null && $limit > 0) {
            $query['limit'] = (string) $limit;
        }
        if (trim((string) $q) !== '') {
            $query['q'] = trim((string) $q);
        }

        return ListReposResult::fromArray(ApiFetcher::json($this->api->get('repos', $query, $jwt)));
    }

    /** Retrieves a repo by ID, or null when it does not exist. */
    public function findOne(string $id): ?Repo
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException('findOne id is required');
        }

        $jwt = $this->generateJwt($id, [Permission::GitRead], self::DEFAULT_TOKEN_TTL);
        $response = $this->api->get('repo', [], $jwt, [404]);
        if ($response->getStatusCode() === 404) {
            return null;
        }

        $payload = ApiFetcher::json($response);
        $defaultBranch = Arr::str($payload, 'default_branch');

        return $this->repo(
            $id,
            $defaultBranch !== '' ? $defaultBranch : 'main',
            Arr::str($payload, 'created_at'),
        );
    }

    /** Creates a repo handle from known metadata, without an HTTP request. */
    public function repo(string $id, ?string $defaultBranch = null, string $createdAt = ''): Repo
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException('repo id is required');
        }

        return new Repo(
            $id,
            trim((string) $defaultBranch) !== '' ? (string) $defaultBranch : 'main',
            $createdAt,
            $this,
        );
    }

    /** Deletes a repository by ID. */
    public function deleteRepo(string $id, ?int $ttl = null): DeleteRepoResult
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException('deleteRepo id is required');
        }

        $jwt = $this->generateJwt($id, [Permission::RepoWrite], $this->invocationTtl($ttl));
        $response = $this->api->delete('repos/delete', null, $jwt, [404, 409]);

        if ($response->getStatusCode() === 404) {
            throw new RuntimeException('repository not found');
        }
        if ($response->getStatusCode() === 409) {
            throw new RuntimeException('repository already deleted');
        }

        return DeleteRepoResult::fromArray(ApiFetcher::json($response));
    }

    /** Creates a generic git credential for a repository. */
    public function createGitCredential(
        string $repoId,
        string $password,
        ?string $username = null,
        ?int $ttl = null,
    ): GitCredential {
        if (trim($repoId) === '') {
            throw new InvalidArgumentException('createGitCredential repoId is required');
        }
        if (trim($password) === '') {
            throw new InvalidArgumentException('createGitCredential password is required');
        }

        $jwt = $this->generateJwt($repoId, [Permission::RepoWrite], $this->invocationTtl($ttl));
        $body = ['repo_id' => $repoId, 'password' => $password];
        if (trim((string) $username) !== '') {
            $body['username'] = (string) $username;
        }

        $response = $this->api->post('repos/git-credentials', $body, $jwt, [409]);
        if ($response->getStatusCode() === 409) {
            throw new RuntimeException('a credential already exists for this repository');
        }

        return GitCredential::fromArray(ApiFetcher::json($response));
    }

    /** Updates an existing generic git credential. */
    public function updateGitCredential(
        string $id,
        string $password,
        ?string $username = null,
        ?int $ttl = null,
    ): GitCredential {
        if (trim($id) === '') {
            throw new InvalidArgumentException('updateGitCredential id is required');
        }
        if (trim($password) === '') {
            throw new InvalidArgumentException('updateGitCredential password is required');
        }

        $jwt = $this->generateJwt('org', [Permission::RepoWrite], $this->invocationTtl($ttl));
        $body = ['id' => $id, 'password' => $password];
        if (trim((string) $username) !== '') {
            $body['username'] = (string) $username;
        }

        $response = $this->api->put('repos/git-credentials', $body, $jwt, [404]);
        if ($response->getStatusCode() === 404) {
            throw new RuntimeException('credential not found');
        }

        return GitCredential::fromArray(ApiFetcher::json($response));
    }

    /** Deletes a generic git credential. */
    public function deleteGitCredential(string $id, ?int $ttl = null): void
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException('deleteGitCredential id is required');
        }

        $jwt = $this->generateJwt('org', [Permission::RepoWrite], $this->invocationTtl($ttl));
        $response = $this->api->delete('repos/git-credentials', ['id' => $id], $jwt, [404]);
        if ($response->getStatusCode() === 404) {
            throw new RuntimeException('credential not found');
        }
    }

    /**
     * Mints a JWT for a repo.
     *
     * @internal
     *
     * @param  list<Permission>  $permissions
     * @param  list<RefPolicy>  $refPolicies  Evaluated in declaration order; first match wins.
     * @param  list<Op>  $ops  Deprecated repo-wide policy ops; prefer $refPolicies.
     */
    public function generateJwt(
        string $repoId,
        array $permissions = [],
        ?int $ttl = null,
        array $refPolicies = [],
        array $ops = [],
    ): string {
        if (trim((string) $this->token) !== '') {
            return (string) $this->token;
        }
        if ($this->privateKey === null) {
            throw new RuntimeException('git storage requires a key to generate a JWT');
        }

        if ($permissions === []) {
            $permissions = [Permission::GitWrite, Permission::GitRead];
        }
        if ($ttl === null || $ttl <= 0) {
            $ttl = ($this->defaultTtl !== null && $this->defaultTtl > 0) ? $this->defaultTtl : self::DEFAULT_JWT_TTL;
        }

        $issuedAt = time();
        $claims = [
            'iss' => $this->name,
            'sub' => '@pierre/storage',
            'repo' => $repoId,
            'scopes' => array_map(static fn (Permission $p): string => $p->value, $permissions),
            'iat' => $issuedAt,
            'exp' => $issuedAt + $ttl,
        ];
        if ($refPolicies !== []) {
            $claims['refs'] = array_map(static fn (RefPolicy $rule): array => $rule->toClaim(), $refPolicies);
        }
        if ($ops !== []) {
            $claims['ops'] = array_map(static fn (Op $op): string => $op->value, $ops);
        }

        return JWT::encode($claims, $this->privateKey, 'ES256');
    }

    /** @internal */
    public function api(): ApiFetcher
    {
        return $this->api;
    }

    /** @internal Resolves a per-call TTL, falling back to the short-lived token default. */
    public function invocationTtl(?int $ttl): int
    {
        return ($ttl !== null && $ttl > 0) ? $ttl : self::DEFAULT_TOKEN_TTL;
    }

    private static function parseEcPrivateKey(string $pem): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new InvalidArgumentException('failed to parse private key PEM');
        }

        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            throw new InvalidArgumentException('private key is not ECDSA');
        }

        return $key;
    }
}
