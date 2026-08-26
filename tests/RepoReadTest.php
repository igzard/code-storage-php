<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Tests;

use Igzard\CodeStorage\Enum\DiffFileState;
use Igzard\CodeStorage\Enum\TreeEntryType;
use Igzard\CodeStorage\Exception\ApiException;
use Igzard\CodeStorage\Model\FileRequestHeaders;
use Igzard\CodeStorage\Repo;
use Igzard\CodeStorage\Tests\Support\MockHttpClient;
use Igzard\CodeStorage\Tests\Support\TestCase;
use InvalidArgumentException;

final class RepoReadTest extends TestCase
{
    private function repo(MockHttpClient $http): Repo
    {
        return $this->client($http)->repo('repo-1');
    }

    public function test_read_endpoints_mint_a_git_read_token(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(['paths' => []]));
        $this->repo($http)->listFiles();

        $claims = $this->claims($this->bearer($http));
        self::assertSame(['git:read'], $claims['scopes']);
        self::assertSame('repo-1', $claims['repo']);
        self::assertSame(3600, $claims['exp'] - $claims['iat']);
    }

    public function test_list_files(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'paths' => ['README.md', 'src/main.go'],
            'ref' => 'main',
            'entries' => [
                ['path' => 'README.md', 'type' => 'blob', 'mode' => '100644'],
                ['path' => 'src', 'type' => 'tree', 'mode' => '040000'],
            ],
            'next_cursor' => 'cursor-2',
            'has_more' => true,
        ]));

        $result = $this->repo($http)->listFiles(
            ref: 'main',
            ephemeral: false,
            path: 'src',
            recursive: true,
            cursor: 'cursor-1',
            limit: 50,
        );

        self::assertSame('https://api.acme.code.storage/api/v1/repos/files', (string) $http->lastRequest()->getUri()->withQuery(''));
        self::assertSame([
            'ref' => 'main',
            'ephemeral' => 'false',
            'path' => 'src',
            'recursive' => 'true',
            'cursor' => 'cursor-1',
            'limit' => '50',
        ], $http->lastQuery());

        self::assertSame(['README.md', 'src/main.go'], $result->paths);
        self::assertSame('main', $result->ref);
        self::assertSame(TreeEntryType::Tree, $result->entries[1]->type);
        self::assertTrue($result->hasMore);
        self::assertSame('cursor-2', $result->nextCursor);
    }

    public function test_list_files_with_metadata(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'files' => [[
                'path' => 'README.md',
                'mode' => '100644',
                'size' => 1234,
                'last_commit_sha' => 'abc123',
                'type' => 'blob',
            ]],
            'commits' => ['abc123' => [
                'author' => 'Docs Bot',
                'date' => '2024-06-15T12:00:00Z',
                'message' => 'Update docs',
            ]],
            'ref' => 'feature/demo',
        ]));

        $result = $this->repo($http)->listFilesWithMetadata(ref: 'feature/demo', ephemeral: true);

        self::assertSame(['ref' => 'feature/demo', 'ephemeral' => 'true'], $http->lastQuery());
        self::assertSame('feature/demo', $result->ref);
        self::assertSame(1234, $result->files[0]->size);
        self::assertSame('Docs Bot', $result->commits['abc123']->author);
        self::assertSame('2024-06-15T12:00:00+00:00', $result->commits['abc123']->date?->format('c'));
        self::assertSame('2024-06-15T12:00:00Z', $result->commits['abc123']->rawDate);
    }

    public function test_file_stream_forwards_conditional_headers(): void
    {
        $http = new MockHttpClient(MockHttpClient::text('', 304));
        $response = $this->repo($http)->fileStream(
            path: 'README.md',
            ref: 'main',
            headers: new FileRequestHeaders(range: 'bytes=0-1023', ifNoneMatch: '"b10b5ha"'),
        );

        self::assertSame(304, $response->getStatusCode(), '304 passes through to the caller');
        self::assertSame('bytes=0-1023', $http->lastRequest()->getHeaderLine('Range'));
        self::assertSame('"b10b5ha"', $http->lastRequest()->getHeaderLine('If-None-Match'));
        self::assertSame(['path' => 'README.md', 'ref' => 'main'], $http->lastQuery());
    }

    public function test_file_stream_requires_a_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('getFileStream path is required');

        $this->repo(new MockHttpClient)->fileStream(' ');
    }

    public function test_head_file_parses_response_headers(): void
    {
        $http = new MockHttpClient(MockHttpClient::text('', 206, [
            'X-Blob-Sha' => 'blob-sha',
            'X-Last-Commit-Sha' => 'commit-sha',
            'Content-Length' => '2048',
            'ETag' => '"b10b5ha"',
            'Last-Modified' => 'Sat, 15 Jun 2024 12:00:00 GMT',
            'Accept-Ranges' => 'bytes',
            'Content-Range' => 'bytes 0-1023/2048',
            'Content-Type' => 'text/markdown',
        ]));

        $meta = $this->repo($http)->headFile(path: 'README.md', ref: 'main');

        self::assertSame('HEAD', $http->lastRequest()->getMethod());
        self::assertSame(206, $meta->statusCode);
        self::assertSame('blob-sha', $meta->blobSha);
        self::assertSame('commit-sha', $meta->lastCommitSha);
        self::assertSame(2048, $meta->size);
        self::assertSame('"b10b5ha"', $meta->etag);
        self::assertSame('bytes 0-1023/2048', $meta->contentRange);
        self::assertSame('2024-06-15T12:00:00+00:00', $meta->lastModified?->format('c'));
    }

    public function test_archive_stream_builds_a_request_body(): void
    {
        $http = new MockHttpClient(MockHttpClient::text('PK', 200));
        $this->repo($http)->archiveStream(
            ref: 'main',
            includeGlobs: ['README.md'],
            excludeGlobs: ['vendor/**'],
            maxBlobSize: 1048576,
            archivePrefix: 'repo/',
        );

        self::assertSame([
            'ref' => 'main',
            'include_globs' => ['README.md'],
            'exclude_globs' => ['vendor/**'],
            'max_blob_size' => 1048576,
            'archive' => ['prefix' => 'repo/'],
        ], $http->lastJsonBody());
    }

    public function test_archive_stream_omits_an_empty_body(): void
    {
        $http = new MockHttpClient(MockHttpClient::text('PK', 200));
        $this->repo($http)->archiveStream();

        self::assertSame('', $http->lastBody());
        self::assertFalse($http->lastRequest()->hasHeader('Content-Type'));
    }

    public function test_list_branches_and_tags(): void
    {
        $http = new MockHttpClient(
            MockHttpClient::json([
                'branches' => [['cursor' => 'c1', 'name' => 'main', 'head_sha' => 'abc', 'created_at' => '2024-06-15T12:00:00Z']],
                'next_cursor' => 'c2',
                'has_more' => true,
            ]),
            MockHttpClient::json(['tags' => [['cursor' => 'c1', 'name' => 'v1.0.0', 'sha' => 'abc']]]),
        );
        $repo = $this->repo($http);

        $branches = $repo->listBranches(limit: 5, ephemeral: true);
        self::assertSame(['limit' => '5', 'ephemeral' => 'true'], $http->lastQuery());
        self::assertSame('main', $branches->branches[0]->name);
        self::assertTrue($branches->hasMore);

        $tags = $repo->listTags(limit: 10);
        self::assertSame(['limit' => '10'], $http->lastQuery());
        self::assertSame('v1.0.0', $tags->tags[0]->name);
        self::assertFalse($tags->hasMore);
    }

    public function test_list_commits_exposes_parent_shas(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'commits' => [
                [
                    'sha' => 'abc',
                    'parent_shas' => ['def', 'ghi'],
                    'message' => 'Merge',
                    'author_name' => 'Bot',
                    'author_email' => 'bot@example.com',
                    'committer_name' => 'Bot',
                    'committer_email' => 'bot@example.com',
                    'date' => '2024-06-15T12:00:00Z',
                ],
                ['sha' => 'root', 'message' => 'Initial'],
            ],
        ]));

        $result = $this->repo($http)->listCommits(branch: 'main', limit: 20, path: 'src');

        self::assertSame(['branch' => 'main', 'limit' => '20', 'path' => 'src'], $http->lastQuery());
        self::assertSame(['def', 'ghi'], $result->commits[0]->parentShas);
        self::assertSame([], $result->commits[1]->parentShas, 'root commits have no parents');
        self::assertSame('2024-06-15T12:00:00+00:00', $result->commits[0]->date?->format('c'));
    }

    public function test_get_commit_surfaces_signature_details(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'commit' => [
                'sha' => 'abc',
                'message' => 'Signed',
                'signature' => '-----BEGIN PGP SIGNATURE-----',
                'payload' => 'tree ...',
            ],
        ]));

        $commit = $this->repo($http)->getCommit('abc');

        self::assertSame(['sha' => 'abc'], $http->lastQuery());
        self::assertSame('-----BEGIN PGP SIGNATURE-----', $commit->signature);
        self::assertSame('tree ...', $commit->payload);
    }

    public function test_get_commit_treats_a_half_signed_commit_as_unsigned(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'commit' => ['sha' => 'abc', 'signature' => '-----BEGIN PGP SIGNATURE-----'],
        ]));

        $commit = $this->repo($http)->getCommit('abc');

        self::assertSame('', $commit->signature);
        self::assertSame('', $commit->payload);
    }

    public function test_get_commit_requires_a_sha(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('getCommit sha is required');

        $this->repo(new MockHttpClient)->getCommit(' ');
    }

    public function test_get_blame_repeats_range_parameters(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'ref' => 'main',
            'path' => 'src/main.go',
            'commit_sha' => 'abc',
            'lines' => [[
                'line_number' => 10,
                'commit_sha' => 'def',
                'original_line_number' => 4,
                'original_path' => 'main.go',
                'previous_commit_sha' => '',
                'author_name' => 'Bot',
                'author_time' => '2024-06-15T12:00:00Z',
                'committer_time' => '2024-06-15T12:00:00Z',
                'summary' => 'Extract helper',
            ]],
        ]));

        $blame = $this->repo($http)->getBlame(
            path: 'src/main.go',
            ref: 'main',
            ranges: ['10,30', ':funcname'],
            detectMoves: true,
        );

        self::assertSame([
            'path' => 'src/main.go',
            'ref' => 'main',
            'range' => ['10,30', ':funcname'],
            'detect_moves' => 'true',
        ], $http->lastQuery());

        self::assertSame('abc', $blame->commitSha);
        self::assertSame(10, $blame->lines[0]->lineNumber);
        self::assertSame('', $blame->lines[0]->previousCommitSha);
        self::assertSame('Extract helper', $blame->lines[0]->summary);
    }

    public function test_get_branch_diff_normalizes_states(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'branch' => 'feature',
            'base' => 'main',
            'stats' => ['files' => 2, 'additions' => 10, 'deletions' => 3, 'changes' => 13],
            'files' => [
                ['path' => 'a.txt', 'state' => 'A', 'raw' => 'diff', 'additions' => 10],
                ['path' => 'b.txt', 'state' => 'R100', 'old_path' => ' old.txt '],
            ],
            'filtered_files' => [['path' => 'big.bin', 'state' => 'M', 'bytes' => 999]],
        ]));

        $diff = $this->repo($http)->getBranchDiff(
            branch: 'feature',
            base: 'main',
            paths: ['a.txt', '  ', 'b.txt'],
        );

        self::assertSame([
            'branch' => 'feature',
            'base' => 'main',
            'path' => ['a.txt', 'b.txt'],
        ], $http->lastQuery(), 'blank paths are dropped');

        self::assertSame(13, $diff->stats->changes);
        self::assertSame(DiffFileState::Added, $diff->files[0]->state);
        self::assertSame(DiffFileState::Renamed, $diff->files[1]->state);
        self::assertSame('R100', $diff->files[1]->rawState);
        self::assertSame('old.txt', $diff->files[1]->oldPath);
        self::assertSame(DiffFileState::Modified, $diff->filteredFiles[0]->state);
    }

    public function test_get_commit_diff(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'sha' => 'head-sha',
            'stats' => ['files' => 1],
            'files' => [['path' => 'a.txt', 'state' => 'M', 'raw' => '--- a', 'is_eof' => true]],
        ]));

        $diff = $this->repo($http)->getCommitDiff(
            sha: 'head-sha',
            baseSha: 'base-sha',
            gitApplyCompatible: true,
        );

        self::assertSame([
            'sha' => 'head-sha',
            'baseSha' => 'base-sha',
            'gitApplyCompatible' => 'true',
        ], $http->lastQuery());
        self::assertSame('--- a', $diff->files[0]->raw);
        self::assertTrue($diff->files[0]->isEof);
    }

    public function test_grep_builds_a_nested_request_body(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'query' => ['pattern' => 'TODO', 'case_sensitive' => true],
            'repo' => ['ref' => 'main', 'commit' => 'abc'],
            'matches' => [['path' => 'a.txt', 'lines' => [['line_number' => 3, 'text' => 'TODO', 'type' => 'match']]]],
            'has_more' => false,
        ]));

        $result = $this->repo($http)->grep(
            pattern: '  TODO  ',
            caseSensitive: true,
            ref: 'main',
            paths: ['src'],
            includeGlobs: ['**/*.php'],
            contextBefore: 2,
            maxLines: 100,
            cursor: 'cursor-1',
            limit: 20,
        );

        self::assertSame([
            'query' => ['pattern' => 'TODO', 'case_sensitive' => true],
            'ref' => 'main',
            'paths' => ['src'],
            'file_filters' => ['include_globs' => ['**/*.php']],
            'context' => ['before' => 2],
            'limits' => ['max_lines' => 100],
            'pagination' => ['cursor' => 'cursor-1', 'limit' => 20],
        ], $http->lastJsonBody());

        self::assertSame('TODO', $result->query->pattern);
        self::assertTrue($result->query->caseSensitive);
        self::assertSame('abc', $result->repo->commit);
        self::assertSame(3, $result->matches[0]->lines[0]->lineNumber);
    }

    public function test_grep_requires_a_pattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('grep pattern is required');

        $this->repo(new MockHttpClient)->grep(' ');
    }

    public function test_get_note(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'sha' => 'abc',
            'note' => 'LGTM',
            'ref_sha' => 'note-sha',
        ]));

        $note = $this->repo($http)->getNote(sha: 'abc', ref: 'reviews');

        self::assertSame(['sha' => 'abc', 'ref' => 'reviews'], $http->lastQuery());
        self::assertSame('LGTM', $note->note);
        self::assertSame('note-sha', $note->refSha);
    }

    public function test_list_notes_refs(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'refs' => [['cursor' => 'c1', 'ref' => 'refs/notes/reviews/1', 'sha' => 'abc']],
            'next_cursor' => 'c2',
            'has_more' => true,
            'prefix' => 'refs/notes/reviews/',
        ]));

        $result = $this->repo($http)->listNotesRefs(prefix: 'reviews/', limit: 20);

        self::assertSame(['prefix' => 'reviews/', 'limit' => '20'], $http->lastQuery());
        self::assertSame('refs/notes/reviews/', $result->prefix);
        self::assertSame('refs/notes/reviews/1', $result->refs[0]->ref);
        self::assertTrue($result->hasMore);
    }

    public function test_api_errors_carry_the_server_message(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(['error' => 'custom notes refs are disabled'], 400));

        try {
            $this->repo($http)->listNotesRefs();
            self::fail('expected an ApiException');
        } catch (ApiException $exception) {
            self::assertSame('custom notes refs are disabled', $exception->getMessage());
            self::assertSame(400, $exception->status);
            self::assertSame('GET', $exception->method);
            self::assertSame('https://api.acme.code.storage/api/v1/repos/notes/refs', $exception->url);
            self::assertSame(['error' => 'custom notes refs are disabled'], $exception->body);
        }
    }

    public function test_api_errors_fall_back_to_the_raw_body(): void
    {
        $http = new MockHttpClient(MockHttpClient::text('  upstream unavailable  ', 502));

        try {
            $this->repo($http)->listBranches();
            self::fail('expected an ApiException');
        } catch (ApiException $exception) {
            self::assertSame('upstream unavailable', $exception->getMessage());
            self::assertSame(502, $exception->status);
        }
    }

    public function test_api_errors_fall_back_to_a_generated_message(): void
    {
        $http = new MockHttpClient(MockHttpClient::text('', 500));

        try {
            $this->repo($http)->listBranches();
            self::fail('expected an ApiException');
        } catch (ApiException $exception) {
            self::assertStringContainsString('failed with status 500', $exception->getMessage());
        }
    }
}
