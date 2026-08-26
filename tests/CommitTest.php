<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Tests;

use Igzard\CodeStorage\Enum\GitFileMode;
use Igzard\CodeStorage\Enum\RefUpdateReason;
use Igzard\CodeStorage\Exception\RefUpdateException;
use Igzard\CodeStorage\Internal\Ndjson;
use Igzard\CodeStorage\Model\CommitSignature;
use Igzard\CodeStorage\Repo;
use Igzard\CodeStorage\Tests\Support\MockHttpClient;
use Igzard\CodeStorage\Tests\Support\TestCase;
use InvalidArgumentException;
use RuntimeException;

final class CommitTest extends TestCase
{
    private const AUTHOR_NAME = 'Docs Bot';

    private const AUTHOR_EMAIL = 'docs@example.com';

    private function repo(MockHttpClient $http): Repo
    {
        return $this->client($http)->repo('repo-1');
    }

    private function author(): CommitSignature
    {
        return new CommitSignature(self::AUTHOR_NAME, self::AUTHOR_EMAIL);
    }

    private static function ack(array $overrides = []): array
    {
        return array_replace_recursive([
            'commit' => [
                'commit_sha' => 'commit-sha',
                'tree_sha' => 'tree-sha',
                'target_branch' => 'main',
                'pack_bytes' => 128,
                'blob_count' => 1,
            ],
            'result' => [
                'branch' => 'main',
                'old_sha' => 'old-sha',
                'new_sha' => 'new-sha',
                'success' => true,
                'status' => 'ok',
            ],
        ], $overrides);
    }

    public function test_it_streams_metadata_and_blob_chunks(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));

        $result = $this->repo($http)
            ->createCommit(targetBranch: 'main', commitMessage: 'Update docs', author: $this->author())
            ->addFileFromString('/docs/readme.md', "# Updated\n")
            ->send();

        $request = $http->lastRequest();
        self::assertSame('https://api.acme.code.storage/api/v1/repos/commit-pack', (string) $request->getUri());
        self::assertSame('application/x-ndjson', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame(['git:write'], $this->claims($this->bearer($http))['scopes']);

        $lines = $this->ndjson($http);
        self::assertCount(2, $lines);

        $metadata = $lines[0]['metadata'];
        self::assertSame('main', $metadata['target_branch']);
        self::assertSame('Update docs', $metadata['commit_message']);
        self::assertSame(['name' => self::AUTHOR_NAME, 'email' => self::AUTHOR_EMAIL], $metadata['author']);
        self::assertCount(1, $metadata['files']);
        self::assertSame('docs/readme.md', $metadata['files'][0]['path'], 'the leading slash is stripped');
        self::assertSame('upsert', $metadata['files'][0]['operation']);
        self::assertSame('100644', $metadata['files'][0]['mode']);

        $chunk = $lines[1]['blob_chunk'];
        self::assertSame($metadata['files'][0]['content_id'], $chunk['content_id']);
        self::assertSame("# Updated\n", base64_decode($chunk['data'], true));
        self::assertTrue($chunk['eof']);

        self::assertSame('commit-sha', $result->commitSha);
        self::assertSame(1, $result->blobCount);
        self::assertSame('new-sha', $result->refUpdate->newSha);
    }

    public function test_it_does_not_escape_slashes_or_unicode(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));

        $this->repo($http)
            ->createCommit(targetBranch: 'main', commitMessage: 'Árvíztűrő <tükörfúrógép>', author: $this->author())
            ->addFileFromString('docs/a/b.md', 'x')
            ->send();

        self::assertStringContainsString('"docs/a/b.md"', $http->lastBody());
        self::assertStringContainsString('Árvíztűrő <tükörfúrógép>', $http->lastBody());
    }

    public function test_it_honours_an_explicit_file_mode(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));

        $this->repo($http)
            ->createCommit(targetBranch: 'main', commitMessage: 'Add script', author: $this->author())
            ->addFileFromString('run.sh', "#!/bin/sh\n", GitFileMode::Executable)
            ->send();

        self::assertSame('100755', $this->ndjson($http)[0]['metadata']['files'][0]['mode']);
    }

    public function test_delete_operations_carry_no_chunk_or_mode(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));

        $this->repo($http)
            ->createCommit(targetBranch: 'main', commitMessage: 'Remove docs', author: $this->author())
            ->deletePath('docs/old.md')
            ->send();

        $lines = $this->ndjson($http);
        self::assertCount(1, $lines, 'deletes stream no blob chunks');

        $entry = $lines[0]['metadata']['files'][0];
        self::assertSame('delete', $entry['operation']);
        self::assertArrayNotHasKey('mode', $entry);
        self::assertNotSame('', $entry['content_id']);
    }

    public function test_an_empty_file_streams_a_single_eof_chunk(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));

        $this->repo($http)
            ->createCommit(targetBranch: 'main', commitMessage: 'Touch', author: $this->author())
            ->addFileFromString('empty.txt', '')
            ->send();

        $lines = $this->ndjson($http);
        self::assertCount(2, $lines);
        self::assertSame('', $lines[1]['blob_chunk']['data']);
        self::assertTrue($lines[1]['blob_chunk']['eof']);
    }

    public function test_large_files_are_split_into_chunks(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));
        $contents = str_repeat('a', Ndjson::MAX_CHUNK_BYTES + 1024);

        $this->repo($http)
            ->createCommit(targetBranch: 'main', commitMessage: 'Add blob', author: $this->author())
            ->addFileFromString('big.txt', $contents)
            ->send();

        $chunks = array_slice($this->ndjson($http), 1);
        self::assertCount(2, $chunks);
        self::assertFalse($chunks[0]['blob_chunk']['eof']);
        self::assertTrue($chunks[1]['blob_chunk']['eof']);

        $reassembled = '';
        foreach ($chunks as $line) {
            $reassembled .= base64_decode($line['blob_chunk']['data'], true);
        }
        self::assertSame($contents, $reassembled);
    }

    public function test_it_accepts_a_stream_resource(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));
        $source = fopen('php://temp', 'r+b');
        fwrite($source, 'from-a-stream');
        rewind($source);

        $this->repo($http)
            ->createCommit(targetBranch: 'main', commitMessage: 'Add blob', author: $this->author())
            ->addFile('blob.bin', $source)
            ->send();

        self::assertSame('from-a-stream', base64_decode($this->ndjson($http)[1]['blob_chunk']['data'], true));
    }

    public function test_it_forwards_optional_metadata(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));

        $this->repo($http)->createCommit(
            targetBranch: 'main',
            commitMessage: 'Update docs',
            author: $this->author(),
            expectedHeadSha: 'head-sha',
            baseBranch: 'develop',
            ephemeral: true,
            ephemeralBase: true,
            committer: new CommitSignature('CI', 'ci@example.com'),
        )->send();

        $metadata = $this->ndjson($http)[0]['metadata'];
        self::assertSame(['name' => 'CI', 'email' => 'ci@example.com'], $metadata['committer']);
        self::assertSame('head-sha', $metadata['expected_head_sha']);
        self::assertSame('develop', $metadata['base_branch']);
        self::assertTrue($metadata['ephemeral']);
        self::assertTrue($metadata['ephemeral_base']);
        self::assertArrayNotHasKey('files', $metadata, 'an empty commit sends no file list');
    }

    public function test_it_accepts_a_fully_qualified_target_branch(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));

        $this->repo($http)
            ->createCommit(targetBranch: 'refs/heads/main', commitMessage: 'Update', author: $this->author())
            ->send();

        self::assertSame('main', $this->ndjson($http)[0]['metadata']['target_branch']);
    }

    public function test_it_accepts_the_deprecated_target_ref(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));

        $this->repo($http)
            ->createCommit(commitMessage: 'Update', author: $this->author(), targetRef: 'refs/heads/feature')
            ->send();

        self::assertSame('feature', $this->ndjson($http)[0]['metadata']['target_branch']);
    }

    public static function invalidOptions(): iterable
    {
        yield 'no target' => [[], 'createCommit targetBranch is required'];
        yield 'non-branch ref' => [['targetBranch' => 'refs/tags/v1'], 'createCommit targetBranch must not include refs/ prefix'];
        yield 'bad target ref' => [['targetRef' => 'feature'], 'createCommit targetRef must start with refs/heads/'];
        yield 'no message' => [['targetBranch' => 'main', 'commitMessage' => '  '], 'createCommit commitMessage is required'];
        yield 'no author' => [['targetBranch' => 'main', 'commitMessage' => 'm', 'author' => null], 'createCommit author name and email are required'];
        yield 'base branch ref' => [['targetBranch' => 'main', 'baseBranch' => 'refs/heads/main'], 'createCommit baseBranch must not include refs/ prefix'];
        yield 'ephemeral base' => [['targetBranch' => 'main', 'ephemeralBase' => true], 'createCommit ephemeralBase requires baseBranch'];
    }

    /** @param array<string, mixed> $options */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOptions')]
    public function test_option_validation(array $options, string $message): void
    {
        $options += ['commitMessage' => 'Update docs', 'author' => $this->author()];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->repo(new MockHttpClient)->createCommit(...$options);
    }

    public function test_file_paths_must_not_be_empty(): void
    {
        $builder = $this->repo(new MockHttpClient)
            ->createCommit(targetBranch: 'main', commitMessage: 'Update', author: $this->author());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('file path must be a non-empty string');

        $builder->addFileFromString('  ', 'x');
    }

    public function test_it_rejects_unsupported_encodings(): void
    {
        $builder = $this->repo(new MockHttpClient)
            ->createCommit(targetBranch: 'main', commitMessage: 'Update', author: $this->author());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported encoding: latin-1');

        $builder->addFileFromString('a.txt', 'x', encoding: 'latin-1');
    }

    public function test_a_builder_cannot_be_reused(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));
        $builder = $this->repo($http)
            ->createCommit(targetBranch: 'main', commitMessage: 'Update', author: $this->author());
        $builder->send();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('createCommit builder cannot be reused after send');

        $builder->send();
    }

    public function test_a_rejected_ref_update_raises_a_ref_update_exception(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack([
            'result' => ['success' => false, 'status' => 'precondition_failed', 'message' => 'head moved'],
        ])));

        try {
            $this->repo($http)
                ->createCommit(targetBranch: 'main', commitMessage: 'Update', author: $this->author())
                ->send();
            self::fail('expected a RefUpdateException');
        } catch (RefUpdateException $exception) {
            self::assertSame('head moved', $exception->getMessage());
            self::assertSame(RefUpdateReason::PreconditionFailed, $exception->reason);
            self::assertSame('main', $exception->refUpdate?->branch);
            self::assertSame('old-sha', $exception->refUpdate?->oldSha);
        }
    }

    public function test_an_error_response_is_mapped_to_its_status_label(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'result' => [
                'branch' => 'main',
                'old_sha' => 'old-sha',
                'success' => false,
                'status' => 'conflict',
                'message' => 'branch moved',
            ],
        ], 409));

        try {
            $this->repo($http)
                ->createCommit(targetBranch: 'main', commitMessage: 'Update', author: $this->author())
                ->send();
            self::fail('expected a RefUpdateException');
        } catch (RefUpdateException $exception) {
            self::assertSame('branch moved', $exception->getMessage());
            self::assertSame(RefUpdateReason::Conflict, $exception->reason);
            self::assertSame('main', $exception->refUpdate?->branch);
        }
    }

    public function test_an_error_response_falls_back_to_the_error_envelope(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(['error' => 'payload too large'], 413));

        try {
            $this->repo($http)
                ->createCommit(targetBranch: 'main', commitMessage: 'Update', author: $this->author())
                ->send();
            self::fail('expected a RefUpdateException');
        } catch (RefUpdateException $exception) {
            self::assertSame('payload too large', $exception->getMessage());
            self::assertSame('failed', $exception->status);
            self::assertNull($exception->refUpdate);
        }
    }

    public function test_an_empty_error_response_falls_back_to_the_status_line(): void
    {
        $http = new MockHttpClient(MockHttpClient::text('', 500, []));

        $this->expectException(RefUpdateException::class);
        $this->expectExceptionMessage('createCommit request failed (500 Internal Server Error)');

        $this->repo($http)
            ->createCommit(targetBranch: 'main', commitMessage: 'Update', author: $this->author())
            ->send();
    }
}
