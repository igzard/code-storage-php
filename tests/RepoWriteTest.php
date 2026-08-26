<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Tests;

use Igzard\CodeStorage\Enum\MergeResultStatus;
use Igzard\CodeStorage\Enum\MergeStrategy;
use Igzard\CodeStorage\Enum\Op;
use Igzard\CodeStorage\Enum\PreviewMergeResultStatus;
use Igzard\CodeStorage\Enum\PreviewMergeStatus;
use Igzard\CodeStorage\Enum\RefUpdateReason;
use Igzard\CodeStorage\Exception\ApiException;
use Igzard\CodeStorage\Exception\RefUpdateException;
use Igzard\CodeStorage\Model\CommitSignature;
use Igzard\CodeStorage\Model\RefPolicy;
use Igzard\CodeStorage\Repo;
use Igzard\CodeStorage\Tests\Support\MockHttpClient;
use Igzard\CodeStorage\Tests\Support\TestCase;
use InvalidArgumentException;
use RuntimeException;

final class RepoWriteTest extends TestCase
{
    private function repo(MockHttpClient $http): Repo
    {
        return $this->client($http)->repo('repo-1');
    }

    public function test_write_endpoints_mint_a_git_write_token_with_ref_policies(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(['name' => 'v1.0.0']));
        $this->repo($http)->createTag(
            name: 'v1.0.0',
            target: 'abc',
            refPolicies: [new RefPolicy('refs/tags/*', [Op::NoForcePush])],
        );

        $claims = $this->claims($this->bearer($http));
        self::assertSame(['git:write'], $claims['scopes']);
        self::assertSame([['refs/tags/*', ['no-force-push']]], $claims['refs']);
    }

    public function test_create_branch_prefers_base_ref(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'message' => 'created',
            'target_branch' => 'feature',
            'target_is_ephemeral' => true,
            'commit_sha' => 'abc',
        ]));

        $result = $this->repo($http)->createBranch(
            targetBranch: 'feature',
            baseRef: 'main',
            baseBranch: 'ignored',
            targetIsEphemeral: true,
        );

        self::assertSame([
            'target_branch' => 'feature',
            'target_is_ephemeral' => true,
            'base_ref' => 'main',
        ], $http->lastJsonBody());
        self::assertTrue($result->targetIsEphemeral);
        self::assertSame('abc', $result->commitSha);
    }

    public function test_create_branch_falls_back_to_the_deprecated_base_branch(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(['target_branch' => 'feature']));
        $this->repo($http)->createBranch(targetBranch: 'feature', baseBranch: 'main', baseIsEphemeral: true);

        self::assertSame([
            'target_branch' => 'feature',
            'base_is_ephemeral' => true,
            'base_branch' => 'main',
        ], $http->lastJsonBody());
    }

    public function test_create_branch_validation(): void
    {
        $repo = $this->repo(new MockHttpClient);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('createBranch baseRef or baseBranch is required');

        $repo->createBranch(targetBranch: 'feature');
    }

    public function test_delete_branch(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'name' => 'merge/123',
            'message' => 'deleted',
            'ephemeral' => true,
        ]));

        $result = $this->repo($http)->deleteBranch(name: 'merge/123', ephemeral: true);

        self::assertSame('DELETE', $http->lastRequest()->getMethod());
        self::assertSame(['name' => 'merge/123', 'ephemeral' => true], $http->lastJsonBody());
        self::assertTrue($result->ephemeral);
    }

    public function test_delete_branch_rejects_fully_qualified_refs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('deleteBranch name must not start with refs/');

        $this->repo(new MockHttpClient)->deleteBranch('refs/heads/main');
    }

    public function test_create_tag_requires_a_target(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('createTag target is required');

        $this->repo(new MockHttpClient)->createTag(name: 'v1.0.0', target: ' ');
    }

    public function test_delete_tag_requests_read_and_write_scopes(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(['name' => 'v1.0.0', 'message' => 'deleted']));
        $result = $this->repo($http)->deleteTag('v1.0.0');

        self::assertSame(['name' => 'v1.0.0'], $http->lastJsonBody());
        self::assertSame(['git:read', 'git:write'], $this->claims($this->bearer($http))['scopes']);
        self::assertSame('deleted', $result->message);
    }

    public function test_create_note(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'sha' => 'abc',
            'target_ref' => 'refs/notes/reviews',
            'base_commit' => 'base',
            'new_ref_sha' => 'new',
            'result' => ['success' => true, 'status' => 'ok'],
        ]));

        $result = $this->repo($http)->createNote(
            sha: 'abc',
            note: '  LGTM  ',
            ref: 'reviews',
            author: new CommitSignature('Review Bot', 'review@example.com'),
        );

        self::assertSame([
            'sha' => 'abc',
            'action' => 'add',
            'note' => 'LGTM',
            'ref' => 'reviews',
            'author' => ['name' => 'Review Bot', 'email' => 'review@example.com'],
        ], $http->lastJsonBody());
        self::assertSame('refs/notes/reviews', $result->targetRef);
        self::assertTrue($result->result->success);
    }

    public function test_append_note_uses_the_append_action(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'sha' => 'abc',
            'result' => ['success' => true],
        ]));

        $this->repo($http)->appendNote(sha: 'abc', note: 'More', expectedRefSha: 'ref-sha');

        self::assertSame([
            'sha' => 'abc',
            'action' => 'append',
            'note' => 'More',
            'expected_ref_sha' => 'ref-sha',
        ], $http->lastJsonBody());
    }

    public function test_delete_note(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'sha' => 'abc',
            'result' => ['success' => true],
        ]));

        $this->repo($http)->deleteNote(sha: 'abc', ref: 'reviews');

        self::assertSame('DELETE', $http->lastRequest()->getMethod());
        self::assertSame(['sha' => 'abc', 'ref' => 'reviews'], $http->lastJsonBody());
    }

    public function test_note_content_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('note content is required');

        $this->repo(new MockHttpClient)->createNote(sha: 'abc', note: '   ');
    }

    public function test_a_partial_note_author_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('note author name and email are required when provided');

        $this->repo(new MockHttpClient)->createNote(
            sha: 'abc',
            note: 'LGTM',
            author: new CommitSignature('Review Bot', ' '),
        );
    }

    public function test_a_failed_note_write_raises_a_ref_update_exception(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'sha' => 'abc',
            'target_ref' => 'refs/notes/commits',
            'base_commit' => 'base',
            'new_ref_sha' => 'new',
            'result' => ['success' => false, 'status' => 'precondition_failed', 'message' => 'ref moved'],
        ], 412));

        try {
            $this->repo($http)->createNote(sha: 'abc', note: 'LGTM');
            self::fail('expected a RefUpdateException');
        } catch (RefUpdateException $exception) {
            self::assertSame('ref moved', $exception->getMessage());
            self::assertSame(RefUpdateReason::PreconditionFailed, $exception->reason);
            self::assertSame('refs/notes/commits', $exception->refUpdate?->branch);
            self::assertSame('base', $exception->refUpdate?->oldSha);
            self::assertSame('new', $exception->refUpdate?->newSha);
        }
    }

    public function test_a_failed_note_write_without_a_message_is_labelled(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'sha' => 'abc',
            'result' => ['success' => false, 'status' => 'conflict'],
        ], 409));

        $this->expectException(RefUpdateException::class);
        $this->expectExceptionMessage('createNote failed with status conflict');

        $this->repo($http)->createNote(sha: 'abc', note: 'LGTM');
    }

    public function test_a_note_error_envelope_becomes_an_api_exception(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(['error' => 'notes are disabled'], 400));

        try {
            $this->repo($http)->createNote(sha: 'abc', note: 'LGTM');
            self::fail('expected an ApiException');
        } catch (ApiException $exception) {
            self::assertSame('notes are disabled', $exception->getMessage());
            self::assertSame(400, $exception->status);
            self::assertSame('POST', $exception->method);
        }
    }

    public function test_a_non_json_note_response_becomes_an_api_exception(): void
    {
        $http = new MockHttpClient(MockHttpClient::text('gateway timeout', 504));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('gateway timeout');

        $this->repo($http)->createNote(sha: 'abc', note: 'LGTM');
    }

    public function test_merge(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'result' => 'merge_commit',
            'commit_sha' => 'merge-sha',
            'tree_sha' => 'tree-sha',
            'source' => ['branch' => 'feature', 'ephemeral' => true, 'sha' => 'src-sha'],
            'target' => ['branch' => 'main', 'ephemeral' => false, 'old_sha' => 'old', 'new_sha' => 'new'],
            'merge_base_sha' => 'base-sha',
            'promoted_commits' => 3,
        ]));

        $result = $this->repo($http)->merge(
            sourceBranch: 'feature',
            targetBranch: 'main',
            strategy: MergeStrategy::Merge,
            sourceIsEphemeral: true,
            expectedTargetSha: 'old',
            commitMessage: 'Merge feature',
            author: new CommitSignature('Merge Bot', 'merge@example.com'),
        );

        self::assertSame([
            'source_branch' => 'feature',
            'target_branch' => 'main',
            'strategy' => 'merge',
            'source_is_ephemeral' => true,
            'expected_target_sha' => 'old',
            'commit_message' => 'Merge feature',
            'author' => ['name' => 'Merge Bot', 'email' => 'merge@example.com'],
        ], $http->lastJsonBody());

        self::assertSame(MergeResultStatus::MergeCommit, $result->result);
        self::assertSame('new', $result->target->newSha);
        self::assertTrue($result->source->ephemeral);
        self::assertSame(3, $result->promotedCommits);
    }

    public function test_merge_maps_unknown_result_statuses(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(['result' => 'something_new']));
        $result = $this->repo($http)->merge('feature', 'main', MergeStrategy::FfPrefer);

        self::assertSame(MergeResultStatus::Unknown, $result->result);
    }

    public function test_merge_rejects_squash_with_ff_only(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('merge squash is incompatible with the ff_only strategy');

        $this->repo(new MockHttpClient)->merge(
            sourceBranch: 'feature',
            targetBranch: 'main',
            strategy: MergeStrategy::FfOnly,
            squash: true,
        );
    }

    public function test_merge_requires_both_branches(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('merge sourceBranch is required');

        $this->repo(new MockHttpClient)->merge(' ', 'main', MergeStrategy::Merge);
    }

    public function test_preview_merge(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'status' => 'conflicted',
            'result' => 'merge_commit',
            'source_branch' => 'feature',
            'target_branch' => 'main',
            'conflict_paths' => ['a.txt'],
            'conflicts' => [[
                'path' => 'a.txt',
                'result' => ['oid' => 'r', 'content' => '<<<<<<<', 'truncated' => false, 'binary' => false],
                'base' => ['oid' => 'b'],
                'ours' => ['oid' => 'o'],
                'theirs' => ['oid' => 't'],
            ]],
            'filtered_conflicts' => [['path' => 'big.bin', 'reason' => 'binary']],
        ]));

        $preview = $this->repo($http)->previewMerge(
            sourceBranch: 'feature',
            targetBranch: 'main',
            includeContent: true,
        );

        self::assertSame([
            'source_branch' => 'feature',
            'target_branch' => 'main',
            'include_content' => 'true',
        ], $http->lastQuery());

        self::assertSame(PreviewMergeStatus::Conflicted, $preview->status);
        self::assertSame(PreviewMergeResultStatus::MergeCommit, $preview->result);
        self::assertSame(['a.txt'], $preview->conflictPaths);
        self::assertSame('<<<<<<<', $preview->conflicts[0]->result->content);
        self::assertSame('binary', $preview->filteredConflicts[0]->reason);
    }

    public function test_pull_upstream_accepts_202(): void
    {
        $http = new MockHttpClient(MockHttpClient::text('', 202));
        $this->repo($http)->pullUpstream(ref: 'main');

        self::assertSame(['ref' => 'main'], $http->lastJsonBody());
    }

    public function test_pull_upstream_rejects_other_success_statuses(): void
    {
        $http = new MockHttpClient(MockHttpClient::text('', 200));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pull upstream failed');

        $this->repo($http)->pullUpstream();
    }

    public function test_restore_commit(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'commit' => [
                'commit_sha' => 'restored',
                'tree_sha' => 'tree',
                'target_branch' => 'main',
                'pack_bytes' => 512,
            ],
            'result' => ['branch' => 'main', 'old_sha' => 'old', 'new_sha' => 'new', 'success' => true, 'status' => 'ok'],
        ]));

        $result = $this->repo($http)->restoreCommit(
            targetBranch: 'main',
            targetCommitSha: 'abc',
            author: new CommitSignature('Bot', 'bot@example.com'),
            commitMessage: 'Restore',
        );

        self::assertSame([
            'metadata' => [
                'target_branch' => 'main',
                'target_commit_sha' => 'abc',
                'author' => ['name' => 'Bot', 'email' => 'bot@example.com'],
                'commit_message' => 'Restore',
            ],
        ], $http->lastJsonBody());

        self::assertSame('restored', $result->commitSha);
        self::assertSame(512, $result->packBytes);
        self::assertSame('new', $result->refUpdate->newSha);
    }

    public function test_restore_commit_surfaces_a_structured_failure(): void
    {
        $http = new MockHttpClient(MockHttpClient::json([
            'result' => [
                'branch' => 'main',
                'old_sha' => 'old',
                'success' => false,
                'status' => 'conflict',
                'message' => 'target moved',
            ],
        ], 409));

        try {
            $this->repo($http)->restoreCommit('main', 'abc', new CommitSignature('Bot', 'bot@example.com'));
            self::fail('expected a RefUpdateException');
        } catch (RefUpdateException $exception) {
            self::assertSame('target moved', $exception->getMessage());
            self::assertSame(RefUpdateReason::Conflict, $exception->reason);
            self::assertSame('main', $exception->refUpdate?->branch);
        }
    }

    public function test_restore_commit_falls_back_to_the_http_status(): void
    {
        $http = new MockHttpClient(MockHttpClient::text('', 412));

        try {
            $this->repo($http)->restoreCommit('main', 'abc', new CommitSignature('Bot', 'bot@example.com'));
            self::fail('expected a RefUpdateException');
        } catch (RefUpdateException $exception) {
            self::assertSame('restore commit failed with HTTP 412', $exception->getMessage());
            self::assertSame(RefUpdateReason::PreconditionFailed, $exception->reason);
            self::assertNull($exception->refUpdate);
        }
    }

    public function test_restore_commit_validation(): void
    {
        $repo = $this->repo(new MockHttpClient);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('restoreCommit targetBranch must not include refs/ prefix');

        $repo->restoreCommit('refs/heads/main', 'abc', new CommitSignature('Bot', 'bot@example.com'));
    }
}
