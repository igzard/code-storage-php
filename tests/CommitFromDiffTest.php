<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Tests;

use Igzard\CodeStorage\Exception\RefUpdateException;
use Igzard\CodeStorage\Internal\Ndjson;
use Igzard\CodeStorage\Model\CommitSignature;
use Igzard\CodeStorage\Repo;
use Igzard\CodeStorage\Tests\Support\MockHttpClient;
use Igzard\CodeStorage\Tests\Support\TestCase;
use InvalidArgumentException;
use Nyholm\Psr7\Stream;

final class CommitFromDiffTest extends TestCase
{
    private const DIFF = "--- a/a.txt\n+++ b/a.txt\n@@ -1 +1 @@\n-old\n+new\n";

    private function repo(MockHttpClient $http): Repo
    {
        return $this->client($http)->repo('repo-1');
    }

    private function author(): CommitSignature
    {
        return new CommitSignature('Patch Bot', 'patch@example.com');
    }

    private static function ack(): array
    {
        return [
            'commit' => ['commit_sha' => 'commit-sha', 'tree_sha' => 'tree-sha', 'target_branch' => 'main'],
            'result' => ['branch' => 'main', 'new_sha' => 'new-sha', 'success' => true, 'status' => 'ok'],
        ];
    }

    public function test_it_streams_metadata_and_diff_chunks(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));

        $result = $this->repo($http)->createCommitFromDiff(
            targetBranch: 'main',
            commitMessage: 'Apply patch',
            author: $this->author(),
            diff: self::DIFF,
        );

        self::assertSame('https://api.acme.code.storage/api/v1/repos/diff-commit', (string) $http->lastRequest()->getUri());
        self::assertSame('application/x-ndjson', $http->lastRequest()->getHeaderLine('Content-Type'));

        $lines = $this->ndjson($http);
        self::assertCount(2, $lines);
        self::assertArrayNotHasKey('files', $lines[0]['metadata'], 'diff commits send no file list');
        self::assertSame('main', $lines[0]['metadata']['target_branch']);
        self::assertSame(self::DIFF, base64_decode($lines[1]['diff_chunk']['data'], true));
        self::assertTrue($lines[1]['diff_chunk']['eof']);

        self::assertSame('commit-sha', $result->commitSha);
    }

    public function test_it_accepts_a_psr7_stream(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));

        $this->repo($http)->createCommitFromDiff(
            targetBranch: 'main',
            commitMessage: 'Apply patch',
            author: $this->author(),
            diff: Stream::create(self::DIFF),
        );

        self::assertSame(self::DIFF, base64_decode($this->ndjson($http)[1]['diff_chunk']['data'], true));
    }

    public function test_large_diffs_are_split_into_chunks(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));
        $diff = str_repeat('+', Ndjson::MAX_CHUNK_BYTES + 10);

        $this->repo($http)->createCommitFromDiff('main', 'Apply patch', $this->author(), $diff);

        $chunks = array_slice($this->ndjson($http), 1);
        self::assertCount(2, $chunks);
        self::assertFalse($chunks[0]['diff_chunk']['eof']);
        self::assertTrue($chunks[1]['diff_chunk']['eof']);
    }

    public function test_an_empty_diff_streams_a_single_eof_chunk(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(self::ack()));

        $this->repo($http)->createCommitFromDiff('main', 'Apply patch', $this->author(), '');

        $chunks = array_slice($this->ndjson($http), 1);
        self::assertCount(1, $chunks);
        self::assertSame('', $chunks[0]['diff_chunk']['data']);
        self::assertTrue($chunks[0]['diff_chunk']['eof']);
    }

    public function test_it_rejects_an_unsupported_diff_source(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported content source');

        $this->repo(new MockHttpClient)->createCommitFromDiff('main', 'Apply patch', $this->author(), 42);
    }

    public function test_validation_uses_its_own_labels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('createCommitFromDiff targetBranch is required');

        $this->repo(new MockHttpClient)->createCommitFromDiff(' ', 'Apply patch', $this->author(), self::DIFF);
    }

    public function test_an_error_response_raises_a_ref_update_exception(): void
    {
        $http = new MockHttpClient(MockHttpClient::text('patch does not apply', 422));

        try {
            $this->repo($http)->createCommitFromDiff('main', 'Apply patch', $this->author(), self::DIFF);
            self::fail('expected a RefUpdateException');
        } catch (RefUpdateException $exception) {
            self::assertSame('patch does not apply', $exception->getMessage());
            self::assertSame('failed', $exception->status);
        }
    }
}
