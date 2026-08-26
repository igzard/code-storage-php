<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Tests;

use Igzard\CodeStorage\Client;
use Igzard\CodeStorage\Enum\RepoProvider;
use Igzard\CodeStorage\Model\ForkBaseRepo;
use Igzard\CodeStorage\Model\GenericGitBaseRepo;
use Igzard\CodeStorage\Model\GitHubBaseRepo;
use Igzard\CodeStorage\Tests\Support\MockHttpClient;
use Igzard\CodeStorage\Tests\Support\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class ClientTest extends TestCase
{
    public function test_it_requires_a_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('git storage requires a name');

        new Client(name: '  ', key: self::TEST_KEY);
    }

    public function test_it_requires_a_key_or_token(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('git storage requires either a key or a token');

        new Client(name: 'acme');
    }

    public function test_it_rejects_a_malformed_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('failed to parse private key PEM');

        new Client(name: 'acme', key: 'not-a-pem');
    }

    public function test_it_derives_default_base_urls(): void
    {
        self::assertSame('https://api.acme.code.storage', Client::defaultApiBaseUrl('acme'));
        self::assertSame('acme.code.storage', Client::defaultStorageBaseUrl('acme'));

        $client = $this->client(new MockHttpClient);
        self::assertSame('https://api.acme.code.storage', $client->apiBaseUrl);
        self::assertSame('acme.code.storage', $client->storageBaseUrl);
    }

    public function test_create_repo_generates_an_id_and_defaults_to_main(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([]));
        $repo = $this->client($http)->createRepo();

        $request = $http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.acme.code.storage/api/v1/repos', (string) $request->getUri());
        self::assertSame(['default_branch' => 'main'], $http->lastJsonBody());
        self::assertSame('main', $repo->defaultBranch);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $repo->id,
        );

        $claims = $this->claims($this->bearer($http));
        self::assertSame('acme', $claims['iss']);
        self::assertSame('@pierre/storage', $claims['sub']);
        self::assertSame($repo->id, $claims['repo']);
        self::assertSame(['repo:write'], $claims['scopes']);
        self::assertSame(3600, $claims['exp'] - $claims['iat']);
    }

    public function test_create_repo_honours_an_explicit_id_and_branch(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([]));
        $repo = $this->client($http)->createRepo(id: 'repo-1', defaultBranch: 'trunk');

        self::assertSame(['default_branch' => 'trunk'], $http->lastJsonBody());
        self::assertSame('repo-1', $repo->id);
        self::assertSame('trunk', $repo->defaultBranch);
    }

    public function test_create_repo_forks_an_existing_repo(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([]));
        $repo = $this->client($http)->createRepo(
            id: 'fork-1',
            baseRepo: new ForkBaseRepo(id: 'base-1', ref: 'main', sha: 'abc123'),
        );

        $body = $http->lastJsonBody();
        self::assertArrayNotHasKey('default_branch', $body, 'forks inherit the base default branch');
        self::assertSame('code', $body['base_repo']['provider']);
        self::assertSame('acme', $body['base_repo']['owner']);
        self::assertSame('base-1', $body['base_repo']['name']);
        self::assertSame('fork', $body['base_repo']['operation']);
        self::assertSame('main', $body['base_repo']['ref']);
        self::assertSame('abc123', $body['base_repo']['sha']);

        $baseClaims = $this->claims($body['base_repo']['auth']['token']);
        self::assertSame('base-1', $baseClaims['repo']);
        self::assertSame(['git:read'], $baseClaims['scopes']);

        self::assertSame('main', $repo->defaultBranch, 'the handle still falls back to main');
    }

    public function test_create_repo_from_a_github_base_repo(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([]));
        $this->client($http)->createRepo(baseRepo: new GitHubBaseRepo(
            owner: 'octocat',
            name: 'hello-world',
            authType: \Igzard\CodeStorage\Enum\GitHubAuthType::Public,
        ));

        self::assertSame([
            'base_repo' => [
                'provider' => 'github',
                'owner' => 'octocat',
                'name' => 'hello-world',
                'auth' => ['auth_type' => 'public'],
            ],
            'default_branch' => 'main',
        ], $http->lastJsonBody());
    }

    public function test_create_repo_from_a_generic_git_base_repo(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([]));
        $repo = $this->client($http)->createRepo(baseRepo: new GenericGitBaseRepo(
            provider: RepoProvider::GitLab,
            owner: 'group',
            name: 'project',
            defaultBranch: 'develop',
            upstreamHost: 'gitlab.example.com',
        ));

        self::assertSame([
            'base_repo' => [
                'provider' => 'gitlab',
                'owner' => 'group',
                'name' => 'project',
                'default_branch' => 'develop',
                'upstream_host' => 'gitlab.example.com',
            ],
            'default_branch' => 'develop',
        ], $http->lastJsonBody());
        self::assertSame('develop', $repo->defaultBranch);
    }

    public function test_create_repo_reports_conflicts(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(['error' => 'exists'], 409));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('repository already exists');

        $this->client($http)->createRepo(id: 'repo-1');
    }

    public function test_list_repos(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'repos' => [[
                'repo_id' => 'repo-1',
                'url' => 'https://acme.code.storage/repo-1.git',
                'default_branch' => 'main',
                'created_at' => '2024-06-15T12:00:00Z',
                'base_repo' => ['provider' => 'github', 'owner' => 'octocat', 'name' => 'hello-world'],
            ]],
            'next_cursor' => 'cursor-2',
            'has_more' => true,
        ]));

        $result = $this->client($http)->listRepos(cursor: 'cursor-1', limit: 10, q: '  hello  ');

        self::assertSame(['cursor' => 'cursor-1', 'limit' => '10', 'q' => 'hello'], $http->lastQuery());
        self::assertSame(['org:read'], $this->claims($this->bearer($http))['scopes']);
        self::assertSame('org', $this->claims($this->bearer($http))['repo']);

        self::assertTrue($result->hasMore);
        self::assertSame('cursor-2', $result->nextCursor);
        self::assertCount(1, $result->repos);
        self::assertSame('repo-1', $result->repos[0]->repoId);
        self::assertSame('octocat', $result->repos[0]->baseRepo?->owner);
    }

    public function test_list_repos_without_options_sends_no_query(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(['repos' => []]));
        $result = $this->client($http)->listRepos();

        self::assertSame('', $http->lastRequest()->getUri()->getQuery());
        self::assertSame([], $result->repos);
        self::assertFalse($result->hasMore);
    }

    public function test_find_one_returns_null_for_missing_repos(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(['error' => 'not found'], 404));

        self::assertNull($this->client($http)->findOne('repo-1'));
        self::assertSame('https://api.acme.code.storage/api/v1/repo', (string) $http->lastRequest()->getUri());
    }

    public function test_find_one_hydrates_a_repo(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'default_branch' => 'trunk',
            'created_at' => '2024-06-15T12:00:00Z',
        ]));

        $repo = $this->client($http)->findOne('repo-1');

        self::assertNotNull($repo);
        self::assertSame('repo-1', $repo->id);
        self::assertSame('trunk', $repo->defaultBranch);
        self::assertSame('2024-06-15T12:00:00Z', $repo->createdAt);
    }

    public function test_find_one_requires_an_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('findOne id is required');

        $this->client(new MockHttpClient)->findOne(' ');
    }

    public function test_repo_hydrates_without_a_request(): void
    {
        $http = new MockHttpClient;
        $repo = $this->client($http)->repo('repo-1', createdAt: '2024-06-15T12:00:00Z');

        self::assertSame([], $http->requests);
        self::assertSame('main', $repo->defaultBranch);
    }

    public function test_delete_repo(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(['repo_id' => 'repo-1', 'message' => 'deleted']));
        $result = $this->client($http)->deleteRepo('repo-1');

        self::assertSame('DELETE', $http->lastRequest()->getMethod());
        self::assertSame('https://api.acme.code.storage/api/v1/repos/delete', (string) $http->lastRequest()->getUri());
        self::assertSame(['repo:write'], $this->claims($this->bearer($http))['scopes']);
        self::assertSame('deleted', $result->message);
    }

    public static function deleteRepoFailures(): iterable
    {
        yield 'missing' => [404, 'repository not found'];
        yield 'already deleted' => [409, 'repository already deleted'];
    }

    #[DataProvider('deleteRepoFailures')]
    public function test_delete_repo_failures(int $status, string $message): void
    {
        $http = new MockHttpClient(MockHttpClient::json([], $status));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->client($http)->deleteRepo('repo-1');
    }

    public function test_git_credential_lifecycle(): void
    {
        $http = new MockHttpClient(
            MockHttpClient::json(['id' => 'cred-1']),
            MockHttpClient::json(['id' => 'cred-1', 'created_at' => '2024-06-15T12:00:00Z']),
            MockHttpClient::json([]),
        );
        $client = $this->client($http);

        $created = $client->createGitCredential(repoId: 'repo-1', password: 'secret', username: 'bot');
        self::assertSame('cred-1', $created->id);
        self::assertSame(['repo_id' => 'repo-1', 'password' => 'secret', 'username' => 'bot'], $http->lastJsonBody());

        $updated = $client->updateGitCredential(id: 'cred-1', password: 'rotated');
        self::assertSame('2024-06-15T12:00:00Z', $updated->createdAt);
        self::assertSame(['id' => 'cred-1', 'password' => 'rotated'], $http->lastJsonBody());
        self::assertSame('PUT', $http->lastRequest()->getMethod());
        self::assertSame('org', $this->claims($this->bearer($http))['repo']);

        $client->deleteGitCredential('cred-1');
        self::assertSame('DELETE', $http->lastRequest()->getMethod());
        self::assertSame(['id' => 'cred-1'], $http->lastJsonBody());
    }

    public function test_git_credential_validation(): void
    {
        $client = $this->client(new MockHttpClient);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('createGitCredential password is required');

        $client->createGitCredential(repoId: 'repo-1', password: ' ');
    }

    public function test_create_git_credential_conflict(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([], 409));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a credential already exists for this repository');

        $this->client($http)->createGitCredential(repoId: 'repo-1', password: 'secret');
    }

    public function test_a_preminted_token_is_used_verbatim(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([]));
        $this->client($http, token: 'preminted-token')->createRepo(id: 'repo-1');

        self::assertSame('Bearer preminted-token', $http->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_it_sends_the_user_agent_header(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([]));
        $this->client($http)->createRepo(id: 'repo-1');

        self::assertSame(
            \Igzard\CodeStorage\Version::userAgent(),
            $http->lastRequest()->getHeaderLine('Code-Storage-Agent'),
        );
    }

    public function test_it_rejects_an_unknown_base_repo_type(): void
    {
        $baseRepo = new class implements \Igzard\CodeStorage\Model\BaseRepo {};

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported base repo type');

        $this->client(new MockHttpClient)->createRepo(baseRepo: $baseRepo);
    }

    public function test_create_repo_honours_a_per_call_ttl(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([]));
        $this->client($http)->createRepo(id: 'repo-1', ttl: 90);

        $claims = $this->claims($this->bearer($http));
        self::assertSame(90, $claims['exp'] - $claims['iat']);
    }
}
