<?php

declare(strict_types=1);

namespace Igzard\CodeStorage;

use Igzard\CodeStorage\Enum\GitFileMode;
use Igzard\CodeStorage\Enum\Permission;
use Igzard\CodeStorage\Internal\ApiFetcher;
use Igzard\CodeStorage\Internal\CommitPack;
use Igzard\CodeStorage\Internal\Ndjson;
use Igzard\CodeStorage\Internal\Uuid;
use Igzard\CodeStorage\Model\CommitResult;
use Igzard\CodeStorage\Model\CommitSignature;
use Igzard\CodeStorage\Model\RefPolicy;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Queues file operations and streams them to the commit-pack endpoint.
 *
 * Obtain one from Repo::createCommit(); a builder cannot be reused after send().
 */
final class CommitBuilder
{
    /** @var list<array{path: string, content_id: string, operation: string, mode: ?GitFileMode, source: mixed}> */
    private array $ops = [];

    private bool $sent = false;

    /**
     * @internal
     *
     * @param  list<RefPolicy>  $refPolicies
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $repoId,
        private readonly string $targetBranch,
        private readonly string $commitMessage,
        private readonly CommitSignature $author,
        private readonly string $expectedHeadSha,
        private readonly string $baseBranch,
        private readonly bool $ephemeral,
        private readonly bool $ephemeralBase,
        private readonly ?CommitSignature $committer,
        private readonly array $refPolicies,
        private readonly ?int $ttl,
    ) {}

    /**
     * Adds or replaces a file.
     *
     * @param  string|resource|StreamInterface  $source
     */
    public function addFile(string $path, mixed $source, ?GitFileMode $mode = null): self
    {
        $this->ensureNotSent();

        if (! is_string($source) && ! is_resource($source) && ! $source instanceof StreamInterface) {
            throw new InvalidArgumentException('unsupported content source; expected binary data');
        }

        $this->ops[] = [
            'path' => self::normalizePath($path),
            'content_id' => Uuid::v4(),
            'operation' => 'upsert',
            'mode' => $mode ?? GitFileMode::Regular,
            'source' => $source,
        ];

        return $this;
    }

    /** Adds or replaces a text file. */
    public function addFileFromString(
        string $path,
        string $contents,
        ?GitFileMode $mode = null,
        string $encoding = 'utf-8',
    ): self {
        $normalized = strtolower(trim($encoding));
        if ($normalized !== 'utf8' && $normalized !== 'utf-8') {
            throw new InvalidArgumentException('unsupported encoding: '.$normalized);
        }

        return $this->addFile($path, $contents, $mode);
    }

    /** Removes a file or directory. */
    public function deletePath(string $path): self
    {
        $this->ensureNotSent();

        $this->ops[] = [
            'path' => self::normalizePath($path),
            'content_id' => Uuid::v4(),
            'operation' => 'delete',
            'mode' => null,
            'source' => null,
        ];

        return $this;
    }

    /** Streams the queued operations and finalizes the commit. */
    public function send(): CommitResult
    {
        $this->ensureNotSent();
        $this->sent = true;

        $body = $this->openBody();
        Ndjson::writeLine($body, ['metadata' => $this->metadata(true)]);
        foreach ($this->ops as $op) {
            if ($op['operation'] === 'upsert') {
                Ndjson::writeChunks($body, $op['source'], $op['content_id']);
            }
        }

        return $this->dispatch($body, 'repos/commit-pack', 'createCommit');
    }

    /**
     * @internal Used by Repo::createCommitFromDiff().
     *
     * @param  string|resource|StreamInterface  $diff
     */
    public function sendDiff(mixed $diff): CommitResult
    {
        $this->ensureNotSent();
        $this->sent = true;

        $body = $this->openBody();
        Ndjson::writeLine($body, ['metadata' => $this->metadata(false)]);
        Ndjson::writeChunks($body, $diff, null);

        return $this->dispatch($body, 'repos/diff-commit', 'createCommitFromDiff');
    }

    /** @return array<string, mixed> */
    private function metadata(bool $withFiles): array
    {
        $metadata = [
            'target_branch' => $this->targetBranch,
            'commit_message' => $this->commitMessage,
            'author' => $this->author->toPayload('commit author'),
        ];

        if ($this->committer !== null) {
            $metadata['committer'] = $this->committer->toPayload('commit committer');
        }
        if ($this->expectedHeadSha !== '') {
            $metadata['expected_head_sha'] = $this->expectedHeadSha;
        }
        if ($this->baseBranch !== '') {
            $metadata['base_branch'] = $this->baseBranch;
        }
        if ($this->ephemeral) {
            $metadata['ephemeral'] = true;
        }
        if ($this->ephemeralBase) {
            $metadata['ephemeral_base'] = true;
        }

        if ($withFiles && $this->ops !== []) {
            $metadata['files'] = array_map(static function (array $op): array {
                $entry = [
                    'path' => $op['path'],
                    'content_id' => $op['content_id'],
                    'operation' => $op['operation'],
                ];
                if ($op['operation'] === 'upsert' && $op['mode'] instanceof GitFileMode) {
                    $entry['mode'] = $op['mode']->value;
                }

                return $entry;
            }, $this->ops);
        }

        return $metadata;
    }

    /** @param resource $body */
    private function dispatch($body, string $path, string $label): CommitResult
    {
        rewind($body);

        $jwt = $this->client->generateJwt(
            $this->repoId,
            [Permission::GitWrite],
            $this->client->invocationTtl($this->ttl),
            $this->refPolicies,
        );

        $api = $this->client->api();
        $response = $api->stream(
            'POST',
            $api->basePath().'/'.$path,
            $jwt,
            $api->streamFactory()->createStreamFromResource($body),
        );

        return self::handle($response, $label);
    }

    private static function handle(ResponseInterface $response, string $label): CommitResult
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw CommitPack::error($response, sprintf(
                '%s request failed (%d %s)',
                $label,
                $status,
                $response->getReasonPhrase(),
            ));
        }

        return CommitPack::result(ApiFetcher::json($response));
    }

    /** @return resource */
    private function openBody()
    {
        $stream = fopen('php://temp/maxmemory:'.Ndjson::MAX_CHUNK_BYTES, 'r+b');
        if ($stream === false) {
            throw new RuntimeException('failed to open the commit request buffer');
        }

        return $stream;
    }

    private function ensureNotSent(): void
    {
        if ($this->sent) {
            throw new RuntimeException('createCommit builder cannot be reused after send');
        }
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new InvalidArgumentException('file path must be a non-empty string');
        }

        return ltrim($path, '/');
    }
}
