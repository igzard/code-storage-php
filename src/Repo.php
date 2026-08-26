<?php

declare(strict_types=1);

namespace Igzard\CodeStorage;

use Igzard\CodeStorage\Enum\MergeStrategy;
use Igzard\CodeStorage\Enum\Permission;
use Igzard\CodeStorage\Exception\ApiException;
use Igzard\CodeStorage\Exception\RefUpdateException;
use Igzard\CodeStorage\Internal\ApiFetcher;
use Igzard\CodeStorage\Internal\Arr;
use Igzard\CodeStorage\Internal\Json;
use Igzard\CodeStorage\Model\BlameResult;
use Igzard\CodeStorage\Model\BranchDiffResult;
use Igzard\CodeStorage\Model\CommitDiffResult;
use Igzard\CodeStorage\Model\CommitInfo;
use Igzard\CodeStorage\Model\CommitResult;
use Igzard\CodeStorage\Model\CommitSignature;
use Igzard\CodeStorage\Model\CreateBranchResult;
use Igzard\CodeStorage\Model\CreateTagResult;
use Igzard\CodeStorage\Model\DeleteBranchResult;
use Igzard\CodeStorage\Model\DeleteTagResult;
use Igzard\CodeStorage\Model\FileMetadata;
use Igzard\CodeStorage\Model\FileRequestHeaders;
use Igzard\CodeStorage\Model\GetNoteResult;
use Igzard\CodeStorage\Model\GrepResult;
use Igzard\CodeStorage\Model\ListBranchesResult;
use Igzard\CodeStorage\Model\ListCommitsResult;
use Igzard\CodeStorage\Model\ListFilesResult;
use Igzard\CodeStorage\Model\ListFilesWithMetadataResult;
use Igzard\CodeStorage\Model\ListNotesRefsResult;
use Igzard\CodeStorage\Model\ListTagsResult;
use Igzard\CodeStorage\Model\MergeResult;
use Igzard\CodeStorage\Model\NoteWriteResult;
use Igzard\CodeStorage\Model\PreviewMergeResult;
use Igzard\CodeStorage\Model\RefPolicy;
use Igzard\CodeStorage\Model\RefUpdate;
use Igzard\CodeStorage\Model\RestoreCommitResult;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** A repository handle. */
final class Repo
{
    /** Statuses the restore-commit and note-write endpoints answer with a structured body. */
    private const WRITE_ALLOWED_STATUS = [400, 401, 403, 404, 408, 409, 412, 422, 429, 499, 500, 502, 503, 504];

    /** @internal Use Client::repo(), Client::createRepo() or Client::findOne(). */
    public function __construct(
        public readonly string $id,
        public readonly string $defaultBranch,
        public readonly string $createdAt,
        private readonly Client $client,
    ) {}

    // ---------------------------------------------------------------- remotes

    /**
     * Authenticated git remote URL.
     *
     * @param  list<Permission>  $permissions
     * @param  list<RefPolicy>  $refPolicies
     * @param  list<\Igzard\CodeStorage\Enum\Op>  $ops  Deprecated; prefer $refPolicies.
     */
    public function remoteUrl(array $permissions = [], ?int $ttl = null, array $refPolicies = [], array $ops = []): string
    {
        return $this->buildRemoteUrl('', $permissions, $ttl, $refPolicies, $ops);
    }

    /** Remote URL for the ephemeral namespace. */
    public function ephemeralRemoteUrl(array $permissions = [], ?int $ttl = null, array $refPolicies = [], array $ops = []): string
    {
        return $this->buildRemoteUrl('+ephemeral', $permissions, $ttl, $refPolicies, $ops);
    }

    /** Remote URL used to import an existing repository. */
    public function importRemoteUrl(array $permissions = [], ?int $ttl = null, array $refPolicies = [], array $ops = []): string
    {
        return $this->buildRemoteUrl('+import', $permissions, $ttl, $refPolicies, $ops);
    }

    // ------------------------------------------------------------------ files

    /**
     * Raw response for streaming file contents.
     *
     * Status codes 206, 304, 412 and 416 pass through to the caller.
     */
    public function fileStream(
        string $path,
        ?string $ref = null,
        ?bool $ephemeral = null,
        ?bool $ephemeralBase = null,
        ?FileRequestHeaders $headers = null,
        ?int $ttl = null,
    ): ResponseInterface {
        if (trim($path) === '') {
            throw new InvalidArgumentException('getFileStream path is required');
        }

        return $this->client->api()->get(
            'repos/file',
            self::fileQuery($path, $ref, $ephemeral, $ephemeralBase),
            $this->readJwt($ttl),
            [304, 412, 416],
            $headers?->toHeaders() ?? [],
        );
    }

    /** Issues HEAD /repos/file and returns the parsed response metadata. */
    public function headFile(
        string $path,
        ?string $ref = null,
        ?bool $ephemeral = null,
        ?bool $ephemeralBase = null,
        ?FileRequestHeaders $headers = null,
        ?int $ttl = null,
    ): FileMetadata {
        if (trim($path) === '') {
            throw new InvalidArgumentException('headFile path is required');
        }

        return FileMetadata::fromResponse($this->client->api()->head(
            'repos/file',
            self::fileQuery($path, $ref, $ephemeral, $ephemeralBase),
            $this->readJwt($ttl),
            [304, 412, 416],
            $headers?->toHeaders() ?? [],
        ));
    }

    /**
     * Raw response for streaming a repository archive.
     *
     * @param  list<string>  $includeGlobs
     * @param  list<string>  $excludeGlobs
     * @param  int|null  $maxBlobSize  Maximum file size in bytes.
     */
    public function archiveStream(
        ?string $ref = null,
        array $includeGlobs = [],
        array $excludeGlobs = [],
        ?int $maxBlobSize = null,
        ?string $archivePrefix = null,
        ?int $ttl = null,
    ): ResponseInterface {
        $body = [];
        if (trim((string) $ref) !== '') {
            $body['ref'] = trim((string) $ref);
        }
        if ($includeGlobs !== []) {
            $body['include_globs'] = $includeGlobs;
        }
        if ($excludeGlobs !== []) {
            $body['exclude_globs'] = $excludeGlobs;
        }
        if ($maxBlobSize !== null) {
            $body['max_blob_size'] = $maxBlobSize;
        }
        if (trim((string) $archivePrefix) !== '') {
            $body['archive'] = ['prefix' => trim((string) $archivePrefix)];
        }

        return $this->client->api()->post('repos/archive', $body === [] ? null : $body, $this->readJwt($ttl));
    }

    /** Lists file paths. */
    public function listFiles(
        ?string $ref = null,
        ?bool $ephemeral = null,
        ?string $path = null,
        ?bool $recursive = null,
        ?string $cursor = null,
        ?int $limit = null,
        ?int $ttl = null,
    ): ListFilesResult {
        $query = self::listingQuery($ref, $ephemeral, $path, $recursive, $cursor, $limit);

        return ListFilesResult::fromArray(ApiFetcher::json(
            $this->client->api()->get('repos/files', $query, $this->readJwt($ttl)),
        ));
    }

    /**
     * Lists files with mode/size and last commit metadata.
     *
     * @param  bool|null  $recursive  Accepted for symmetry with listFiles(); listings are always recursive.
     */
    public function listFilesWithMetadata(
        ?string $ref = null,
        ?bool $ephemeral = null,
        ?string $path = null,
        ?bool $recursive = null,
        ?string $cursor = null,
        ?int $limit = null,
        ?int $ttl = null,
    ): ListFilesWithMetadataResult {
        $query = self::listingQuery($ref, $ephemeral, $path, $recursive, $cursor, $limit);

        return ListFilesWithMetadataResult::fromArray(ApiFetcher::json(
            $this->client->api()->get('repos/files/metadata', $query, $this->readJwt($ttl)),
        ));
    }

    // --------------------------------------------------------------- branches

    /** Lists branches. */
    public function listBranches(
        ?string $cursor = null,
        ?int $limit = null,
        ?bool $ephemeral = null,
        ?int $ttl = null,
    ): ListBranchesResult {
        $query = [];
        self::addString($query, 'cursor', $cursor);
        self::addLimit($query, $limit);
        self::addBool($query, 'ephemeral', $ephemeral);

        return ListBranchesResult::fromArray(ApiFetcher::json(
            $this->client->api()->get('repos/branches', $query, $this->readJwt($ttl)),
        ));
    }

    /**
     * Creates a new branch.
     *
     * @param  string  $baseBranch  Deprecated; use $baseRef.
     * @param  list<RefPolicy>  $refPolicies
     */
    public function createBranch(
        string $targetBranch,
        string $baseRef = '',
        string $baseBranch = '',
        bool $baseIsEphemeral = false,
        bool $targetIsEphemeral = false,
        array $refPolicies = [],
        ?int $ttl = null,
    ): CreateBranchResult {
        $baseRef = trim($baseRef);
        $baseBranch = trim($baseBranch);
        $targetBranch = trim($targetBranch);

        if ($baseRef === '' && $baseBranch === '') {
            throw new InvalidArgumentException('createBranch baseRef or baseBranch is required');
        }
        if ($targetBranch === '') {
            throw new InvalidArgumentException('createBranch targetBranch is required');
        }

        $body = ['target_branch' => $targetBranch];
        if ($baseIsEphemeral) {
            $body['base_is_ephemeral'] = true;
        }
        if ($targetIsEphemeral) {
            $body['target_is_ephemeral'] = true;
        }
        if ($baseRef !== '') {
            $body['base_ref'] = $baseRef;
        } else {
            $body['base_branch'] = $baseBranch;
        }

        return CreateBranchResult::fromArray(ApiFetcher::json($this->client->api()->post(
            'repos/branches/create',
            $body,
            $this->writeJwt($ttl, $refPolicies),
        )));
    }

    /**
     * Deletes a branch.
     *
     * @param  bool|null  $ephemeral  Delete from the ephemeral namespace.
     * @param  list<RefPolicy>  $refPolicies
     */
    public function deleteBranch(
        string $name,
        ?bool $ephemeral = null,
        array $refPolicies = [],
        ?int $ttl = null,
    ): DeleteBranchResult {
        $name = self::plainRefName($name, 'deleteBranch');

        $body = ['name' => $name];
        if ($ephemeral !== null) {
            $body['ephemeral'] = $ephemeral;
        }

        return DeleteBranchResult::fromArray(ApiFetcher::json($this->client->api()->delete(
            'repos/branches',
            $body,
            $this->writeJwt($ttl, $refPolicies),
        )));
    }

    // ------------------------------------------------------------------- tags

    /** Lists tags. */
    public function listTags(?string $cursor = null, ?int $limit = null, ?int $ttl = null): ListTagsResult
    {
        $query = [];
        self::addString($query, 'cursor', $cursor);
        self::addLimit($query, $limit);

        return ListTagsResult::fromArray(ApiFetcher::json(
            $this->client->api()->get('repos/tags', $query, $this->readJwt($ttl)),
        ));
    }

    /** @param list<RefPolicy> $refPolicies */
    public function createTag(string $name, string $target, array $refPolicies = [], ?int $ttl = null): CreateTagResult
    {
        $name = self::plainRefName($name, 'createTag');
        $target = trim($target);
        if ($target === '') {
            throw new InvalidArgumentException('createTag target is required');
        }

        return CreateTagResult::fromArray(ApiFetcher::json($this->client->api()->post(
            'repos/tags',
            ['name' => $name, 'target' => $target],
            $this->writeJwt($ttl, $refPolicies),
        )));
    }

    /** @param list<RefPolicy> $refPolicies */
    public function deleteTag(string $name, array $refPolicies = [], ?int $ttl = null): DeleteTagResult
    {
        $name = self::plainRefName($name, 'deleteTag');
        $jwt = $this->client->generateJwt(
            $this->id,
            [Permission::GitRead, Permission::GitWrite],
            $this->client->invocationTtl($ttl),
            $refPolicies,
        );

        return DeleteTagResult::fromArray(ApiFetcher::json(
            $this->client->api()->delete('repos/tags', ['name' => $name], $jwt),
        ));
    }

    // ---------------------------------------------------------------- commits

    /** Lists commits. */
    public function listCommits(
        ?string $branch = null,
        ?string $cursor = null,
        ?int $limit = null,
        ?bool $ephemeral = null,
        ?string $path = null,
        ?int $ttl = null,
    ): ListCommitsResult {
        $query = [];
        self::addString($query, 'branch', $branch);
        self::addString($query, 'cursor', $cursor);
        self::addLimit($query, $limit);
        self::addBool($query, 'ephemeral', $ephemeral);
        self::addString($query, 'path', $path);

        return ListCommitsResult::fromArray(ApiFetcher::json(
            $this->client->api()->get('repos/commits', $query, $this->readJwt($ttl)),
        ));
    }

    /**
     * Metadata for a single commit, without computing its diff.
     *
     * Signed commits additionally carry the armored signature and signed payload.
     */
    public function getCommit(string $sha, ?int $ttl = null): CommitInfo
    {
        $sha = trim($sha);
        if ($sha === '') {
            throw new InvalidArgumentException('getCommit sha is required');
        }

        $payload = ApiFetcher::json(
            $this->client->api()->get('repos/commit', ['sha' => $sha], $this->readJwt($ttl)),
        );

        return CommitInfo::fromArray(Arr::arr($payload, 'commit'));
    }

    /**
     * Per-line authorship for a file at a ref.
     *
     * @param  list<string>  $ranges  Repeated `git blame -L` specs ("10,30", "/getUser/,/^}/", ":funcname", ...).
     *                                Up to 16 per request; omit to blame the whole file.
     */
    public function getBlame(
        string $path,
        ?string $ref = null,
        bool $ephemeral = false,
        array $ranges = [],
        bool $detectMoves = false,
        ?int $ttl = null,
    ): BlameResult {
        if (trim($path) === '') {
            throw new InvalidArgumentException('getBlame path is required');
        }

        $query = ['path' => $path];
        if (trim((string) $ref) !== '') {
            $query['ref'] = trim((string) $ref);
        }
        if ($ephemeral) {
            $query['ephemeral'] = 'true';
        }
        if ($ranges !== []) {
            $query['range'] = array_values($ranges);
        }
        if ($detectMoves) {
            $query['detect_moves'] = 'true';
        }

        return BlameResult::fromArray(ApiFetcher::json(
            $this->client->api()->get('repos/blame', $query, $this->readJwt($ttl)),
        ));
    }

    // ------------------------------------------------------------------ notes

    /**
     * Reads a git note.
     *
     * @param  string|null  $ref  Notes ref to read from. A bare name like "reviews" is placed under
     *                            refs/notes/; a fully-qualified refs/notes/* ref also works.
     *                            Defaults to refs/notes/commits.
     */
    public function getNote(string $sha, ?string $ref = null, ?int $ttl = null): GetNoteResult
    {
        $sha = trim($sha);
        if ($sha === '') {
            throw new InvalidArgumentException('getNote sha is required');
        }

        $query = ['sha' => $sha];
        if (trim((string) $ref) !== '') {
            $query['ref'] = trim((string) $ref);
        }

        return GetNoteResult::fromArray(ApiFetcher::json(
            $this->client->api()->get('repos/notes', $query, $this->readJwt($ttl)),
        ));
    }

    /**
     * Adds a git note.
     *
     * @param  list<RefPolicy>  $refPolicies
     */
    public function createNote(
        string $sha,
        string $note,
        string $expectedRefSha = '',
        ?CommitSignature $author = null,
        ?string $ref = null,
        array $refPolicies = [],
        ?int $ttl = null,
    ): NoteWriteResult {
        return $this->writeNote('add', $sha, $note, $expectedRefSha, $ref, $author, $refPolicies, $ttl);
    }

    /**
     * Appends to a git note.
     *
     * @param  list<RefPolicy>  $refPolicies
     */
    public function appendNote(
        string $sha,
        string $note,
        string $expectedRefSha = '',
        ?CommitSignature $author = null,
        ?string $ref = null,
        array $refPolicies = [],
        ?int $ttl = null,
    ): NoteWriteResult {
        return $this->writeNote('append', $sha, $note, $expectedRefSha, $ref, $author, $refPolicies, $ttl);
    }

    /**
     * Deletes a git note.
     *
     * @param  list<RefPolicy>  $refPolicies
     */
    public function deleteNote(
        string $sha,
        string $expectedRefSha = '',
        ?CommitSignature $author = null,
        ?string $ref = null,
        array $refPolicies = [],
        ?int $ttl = null,
    ): NoteWriteResult {
        $sha = trim($sha);
        if ($sha === '') {
            throw new InvalidArgumentException('deleteNote sha is required');
        }

        $body = ['sha' => $sha];
        if (trim($expectedRefSha) !== '') {
            $body['expected_ref_sha'] = $expectedRefSha;
        }
        if (trim((string) $ref) !== '') {
            $body['ref'] = trim((string) $ref);
        }
        if ($author !== null) {
            $body['author'] = $author->toPayload('deleteNote author');
        }

        $response = $this->client->api()->delete(
            'repos/notes',
            $body,
            $this->writeJwt($ttl, $refPolicies),
            self::WRITE_ALLOWED_STATUS,
        );

        return $this->finishNoteWrite($response, 'DELETE', 'deleteNote');
    }

    /**
     * Lists git notes refs under a prefix, with cursor pagination.
     *
     * Requires the custom notes refs feature server-side; otherwise the request
     * fails with an ApiException (HTTP 400).
     *
     * @param  string|null  $prefix  Defaults to refs/notes/.
     * @param  int|null  $limit  Defaults to 20 server-side.
     */
    public function listNotesRefs(
        ?string $prefix = null,
        ?string $cursor = null,
        ?int $limit = null,
        ?int $ttl = null,
    ): ListNotesRefsResult {
        $query = [];
        if (trim((string) $prefix) !== '') {
            $query['prefix'] = trim((string) $prefix);
        }
        self::addString($query, 'cursor', $cursor);
        self::addLimit($query, $limit);

        return ListNotesRefsResult::fromArray(ApiFetcher::json(
            $this->client->api()->get('repos/notes/refs', $query, $this->readJwt($ttl)),
        ));
    }

    // ------------------------------------------------------------------ diffs

    /** @param list<string> $paths */
    public function getBranchDiff(
        string $branch,
        ?string $base = null,
        ?bool $ephemeral = null,
        ?bool $ephemeralBase = null,
        array $paths = [],
        ?int $ttl = null,
    ): BranchDiffResult {
        if (trim($branch) === '') {
            throw new InvalidArgumentException('getBranchDiff branch is required');
        }

        $query = ['branch' => $branch];
        if (trim((string) $base) !== '') {
            $query['base'] = (string) $base;
        }
        self::addBool($query, 'ephemeral', $ephemeral);
        self::addBool($query, 'ephemeral_base', $ephemeralBase);
        self::addPaths($query, $paths);

        return BranchDiffResult::fromArray(ApiFetcher::json(
            $this->client->api()->get('repos/branches/diff', $query, $this->readJwt($ttl)),
        ));
    }

    /**
     * Diff introduced by a commit.
     *
     * @param  bool  $gitApplyCompatible  Generate raw diffs applicable with `git apply`. When no files
     *                                    are filtered and every changed file has a non-empty raw diff,
     *                                    concatenating them in response order patches the exact base tree.
     * @param  list<string>  $paths
     */
    public function getCommitDiff(
        string $sha,
        ?string $baseSha = null,
        bool $gitApplyCompatible = false,
        array $paths = [],
        ?int $ttl = null,
    ): CommitDiffResult {
        if (trim($sha) === '') {
            throw new InvalidArgumentException('getCommitDiff sha is required');
        }

        $query = ['sha' => $sha];
        if (trim((string) $baseSha) !== '') {
            $query['baseSha'] = (string) $baseSha;
        }
        if ($gitApplyCompatible) {
            $query['gitApplyCompatible'] = 'true';
        }
        self::addPaths($query, $paths);

        return CommitDiffResult::fromArray(ApiFetcher::json(
            $this->client->api()->get('repos/diff', $query, $this->readJwt($ttl)),
        ));
    }

    // ------------------------------------------------------------------- grep

    /**
     * Runs a grep query.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $includeGlobs
     * @param  list<string>  $excludeGlobs
     * @param  list<string>  $extensionFilters
     */
    public function grep(
        string $pattern,
        ?bool $caseSensitive = null,
        ?string $ref = null,
        ?bool $ephemeral = null,
        array $paths = [],
        array $includeGlobs = [],
        array $excludeGlobs = [],
        array $extensionFilters = [],
        ?int $contextBefore = null,
        ?int $contextAfter = null,
        ?int $maxLines = null,
        ?int $maxMatchesPerFile = null,
        ?string $cursor = null,
        ?int $limit = null,
        ?int $ttl = null,
    ): GrepResult {
        $pattern = trim($pattern);
        if ($pattern === '') {
            throw new InvalidArgumentException('grep pattern is required');
        }

        $query = ['pattern' => $pattern];
        if ($caseSensitive !== null) {
            $query['case_sensitive'] = $caseSensitive;
        }
        $body = ['query' => $query];

        if (trim((string) $ref) !== '') {
            $body['ref'] = trim((string) $ref);
        }
        if ($ephemeral !== null) {
            $body['ephemeral'] = $ephemeral;
        }
        if ($paths !== []) {
            $body['paths'] = array_values($paths);
        }

        $filters = self::compact([
            'include_globs' => $includeGlobs === [] ? null : array_values($includeGlobs),
            'exclude_globs' => $excludeGlobs === [] ? null : array_values($excludeGlobs),
            'extension_filters' => $extensionFilters === [] ? null : array_values($extensionFilters),
        ]);
        if ($filters !== []) {
            $body['file_filters'] = $filters;
        }

        $context = self::compact(['before' => $contextBefore, 'after' => $contextAfter]);
        if ($context !== []) {
            $body['context'] = $context;
        }

        $limits = self::compact(['max_lines' => $maxLines, 'max_matches_per_file' => $maxMatchesPerFile]);
        if ($limits !== []) {
            $body['limits'] = $limits;
        }

        $pagination = self::compact([
            'cursor' => trim((string) $cursor) === '' ? null : (string) $cursor,
            'limit' => $limit,
        ]);
        if ($pagination !== []) {
            $body['pagination'] = $pagination;
        }

        return GrepResult::fromArray(ApiFetcher::json(
            $this->client->api()->post('repos/grep', $body, $this->readJwt($ttl)),
        ));
    }

    // ----------------------------------------------------------------- writes

    /**
     * Triggers a pull-upstream operation.
     *
     * @param  list<RefPolicy>  $refPolicies
     */
    public function pullUpstream(?string $ref = null, array $refPolicies = [], ?int $ttl = null): void
    {
        $body = [];
        if (trim((string) $ref) !== '') {
            $body['ref'] = (string) $ref;
        }

        $response = $this->client->api()->post('repos/pull-upstream', $body, $this->writeJwt($ttl, $refPolicies));
        if ($response->getStatusCode() !== 202) {
            throw new RuntimeException('pull upstream failed: '.$response->getStatusCode().' '.$response->getReasonPhrase());
        }
    }

    /**
     * Merges a source branch into a target branch.
     *
     * @param  string  $expectedTargetSha  When non-empty, requires the target branch to still point at
     *                                     that commit; the server answers 409 if it moved. Leave empty
     *                                     to merge into the current target tip.
     * @param  bool  $squash  Incompatible with MergeStrategy::FfOnly.
     * @param  list<RefPolicy>  $refPolicies
     */
    public function merge(
        string $sourceBranch,
        string $targetBranch,
        MergeStrategy $strategy,
        bool $sourceIsEphemeral = false,
        bool $targetIsEphemeral = false,
        string $expectedTargetSha = '',
        string $commitMessage = '',
        ?CommitSignature $author = null,
        ?CommitSignature $committer = null,
        bool $allowUnrelatedHistories = false,
        bool $squash = false,
        array $refPolicies = [],
        ?int $ttl = null,
    ): MergeResult {
        $sourceBranch = trim($sourceBranch);
        if ($sourceBranch === '') {
            throw new InvalidArgumentException('merge sourceBranch is required');
        }
        $targetBranch = trim($targetBranch);
        if ($targetBranch === '') {
            throw new InvalidArgumentException('merge targetBranch is required');
        }
        if ($squash && $strategy === MergeStrategy::FfOnly) {
            throw new InvalidArgumentException('merge squash is incompatible with the ff_only strategy');
        }

        $body = [
            'source_branch' => $sourceBranch,
            'target_branch' => $targetBranch,
            'strategy' => $strategy->value,
        ];
        if ($sourceIsEphemeral) {
            $body['source_is_ephemeral'] = true;
        }
        if ($targetIsEphemeral) {
            $body['target_is_ephemeral'] = true;
        }
        if (trim($expectedTargetSha) !== '') {
            $body['expected_target_sha'] = trim($expectedTargetSha);
        }
        if (trim($commitMessage) !== '') {
            $body['commit_message'] = trim($commitMessage);
        }
        if ($author !== null) {
            $body['author'] = $author->toPayload('merge author');
        }
        if ($committer !== null) {
            $body['committer'] = $committer->toPayload('merge committer');
        }
        if ($allowUnrelatedHistories) {
            $body['allow_unrelated_histories'] = true;
        }
        if ($squash) {
            $body['squash'] = true;
        }

        return MergeResult::fromArray(ApiFetcher::json(
            $this->client->api()->post('repos/merge', $body, $this->writeJwt($ttl, $refPolicies)),
        ));
    }

    /** Previews a branch merge without creating commits or updating refs. */
    public function previewMerge(
        string $sourceBranch,
        string $targetBranch,
        ?bool $includeContent = null,
        ?int $ttl = null,
    ): PreviewMergeResult {
        $sourceBranch = trim($sourceBranch);
        if ($sourceBranch === '') {
            throw new InvalidArgumentException('previewMerge sourceBranch is required');
        }
        $targetBranch = trim($targetBranch);
        if ($targetBranch === '') {
            throw new InvalidArgumentException('previewMerge targetBranch is required');
        }

        $query = ['source_branch' => $sourceBranch, 'target_branch' => $targetBranch];
        self::addBool($query, 'include_content', $includeContent);

        return PreviewMergeResult::fromArray(ApiFetcher::json(
            $this->client->api()->get('repos/merge/preview', $query, $this->readJwt($ttl)),
        ));
    }

    /**
     * Restores a commit into a branch.
     *
     * @param  list<RefPolicy>  $refPolicies
     */
    public function restoreCommit(
        string $targetBranch,
        string $targetCommitSha,
        CommitSignature $author,
        string $commitMessage = '',
        string $expectedHeadSha = '',
        ?CommitSignature $committer = null,
        array $refPolicies = [],
        ?int $ttl = null,
    ): RestoreCommitResult {
        $targetBranch = trim($targetBranch);
        if ($targetBranch === '') {
            throw new InvalidArgumentException('restoreCommit targetBranch is required');
        }
        if (str_starts_with($targetBranch, 'refs/')) {
            throw new InvalidArgumentException('restoreCommit targetBranch must not include refs/ prefix');
        }
        $targetCommitSha = trim($targetCommitSha);
        if ($targetCommitSha === '') {
            throw new InvalidArgumentException('restoreCommit targetCommitSha is required');
        }
        if ($author->name === '' || $author->email === '') {
            throw new InvalidArgumentException('restoreCommit author name and email are required');
        }

        $metadata = [
            'target_branch' => $targetBranch,
            'target_commit_sha' => $targetCommitSha,
            'author' => $author->toPayload('restoreCommit author'),
        ];
        if (trim($commitMessage) !== '') {
            $metadata['commit_message'] = $commitMessage;
        }
        if (trim($expectedHeadSha) !== '') {
            $metadata['expected_head_sha'] = $expectedHeadSha;
        }
        if ($committer !== null) {
            $metadata['committer'] = $committer->toPayload('restoreCommit committer');
        }

        $response = $this->client->api()->post(
            'repos/restore-commit',
            ['metadata' => $metadata],
            $this->writeJwt($ttl, $refPolicies),
            self::WRITE_ALLOWED_STATUS,
        );

        $payload = Json::decode((string) $response->getBody()) ?? [];
        $result = Arr::arr($payload, 'result');

        if (Arr::bool($result, 'success')) {
            $commit = Arr::arr($payload, 'commit');

            return new RestoreCommitResult(
                Arr::str($commit, 'commit_sha'),
                Arr::str($commit, 'tree_sha'),
                Arr::str($commit, 'target_branch'),
                Arr::int($commit, 'pack_bytes'),
                RefUpdate::fromArray($result),
            );
        }

        $status = trim(Arr::str($result, 'status'));
        $message = trim(Arr::str($result, 'message'));
        $refUpdate = RefUpdate::partial(
            Arr::str($result, 'branch'),
            Arr::str($result, 'old_sha'),
            Arr::str($result, 'new_sha'),
        );

        if ($status === '') {
            $status = match ($response->getStatusCode()) {
                409 => 'conflict',
                412 => 'precondition_failed',
                default => (string) $response->getStatusCode(),
            };
        }
        if ($message === '') {
            $message = 'restore commit failed with HTTP '.$response->getStatusCode();
        }

        throw new RefUpdateException($message, $status, $refUpdate);
    }

    /**
     * Starts a commit builder. Nothing is sent until CommitBuilder::send().
     *
     * @param  string  $targetRef  Deprecated; a refs/heads/* ref accepted instead of $targetBranch.
     * @param  list<RefPolicy>  $refPolicies
     */
    public function createCommit(
        string $targetBranch = '',
        string $commitMessage = '',
        ?CommitSignature $author = null,
        string $targetRef = '',
        string $expectedHeadSha = '',
        string $baseBranch = '',
        bool $ephemeral = false,
        bool $ephemeralBase = false,
        ?CommitSignature $committer = null,
        array $refPolicies = [],
        ?int $ttl = null,
    ): CommitBuilder {
        return new CommitBuilder(
            $this->client,
            $this->id,
            self::commitTarget($targetBranch, $targetRef, 'createCommit'),
            self::requiredMessage($commitMessage, 'createCommit'),
            self::requiredAuthor($author, 'createCommit'),
            trim($expectedHeadSha),
            self::commitBaseBranch($baseBranch, $ephemeralBase, 'createCommit'),
            $ephemeral,
            $ephemeralBase,
            $committer,
            $refPolicies,
            $ttl,
        );
    }

    /**
     * Applies a pre-generated diff as a commit.
     *
     * @param  string|resource|StreamInterface  $diff
     * @param  list<RefPolicy>  $refPolicies
     */
    public function createCommitFromDiff(
        string $targetBranch,
        string $commitMessage,
        CommitSignature $author,
        mixed $diff,
        string $expectedHeadSha = '',
        string $baseBranch = '',
        bool $ephemeral = false,
        bool $ephemeralBase = false,
        ?CommitSignature $committer = null,
        array $refPolicies = [],
        ?int $ttl = null,
    ): CommitResult {
        if ($diff === null) {
            throw new InvalidArgumentException('createCommitFromDiff diff is required');
        }

        $builder = new CommitBuilder(
            $this->client,
            $this->id,
            self::commitTarget($targetBranch, '', 'createCommitFromDiff'),
            self::requiredMessage($commitMessage, 'createCommitFromDiff'),
            self::requiredAuthor($author, 'createCommitFromDiff'),
            trim($expectedHeadSha),
            self::commitBaseBranch($baseBranch, $ephemeralBase, 'createCommitFromDiff'),
            $ephemeral,
            $ephemeralBase,
            $committer,
            $refPolicies,
            $ttl,
        );

        return $builder->sendDiff($diff);
    }

    // ---------------------------------------------------------------- helpers

    private function buildRemoteUrl(string $suffix, array $permissions, ?int $ttl, array $refPolicies, array $ops): string
    {
        $jwt = $this->client->generateJwt($this->id, $permissions, $ttl, $refPolicies, $ops);

        return sprintf('https://t:%s@%s/%s%s.git', $jwt, $this->client->storageBaseUrl, $this->id, $suffix);
    }

    private function readJwt(?int $ttl): string
    {
        return $this->client->generateJwt($this->id, [Permission::GitRead], $this->client->invocationTtl($ttl));
    }

    /** @param list<RefPolicy> $refPolicies */
    private function writeJwt(?int $ttl, array $refPolicies = []): string
    {
        return $this->client->generateJwt(
            $this->id,
            [Permission::GitWrite],
            $this->client->invocationTtl($ttl),
            $refPolicies,
        );
    }

    /** @param list<RefPolicy> $refPolicies */
    private function writeNote(
        string $action,
        string $sha,
        string $note,
        string $expectedRefSha,
        ?string $ref,
        ?CommitSignature $author,
        array $refPolicies,
        ?int $ttl,
    ): NoteWriteResult {
        $sha = trim($sha);
        if ($sha === '') {
            throw new InvalidArgumentException('note sha is required');
        }
        $note = trim($note);
        if ($note === '') {
            throw new InvalidArgumentException('note content is required');
        }

        $body = ['sha' => $sha, 'action' => $action, 'note' => $note];
        if (trim($expectedRefSha) !== '') {
            $body['expected_ref_sha'] = $expectedRefSha;
        }
        if (trim((string) $ref) !== '') {
            $body['ref'] = trim((string) $ref);
        }
        if ($author !== null) {
            $body['author'] = $author->toPayload('note author');
        }

        $response = $this->client->api()->post(
            'repos/notes',
            $body,
            $this->writeJwt($ttl, $refPolicies),
            self::WRITE_ALLOWED_STATUS,
        );

        return $this->finishNoteWrite($response, 'POST', $action === 'append' ? 'appendNote' : 'createNote');
    }

    private function finishNoteWrite(ResponseInterface $response, string $method, string $label): NoteWriteResult
    {
        $url = $this->client->api()->basePath().'/repos/notes';
        $raw = (string) $response->getBody();

        $payload = str_contains($response->getHeaderLine('Content-Type'), 'application/json') && $raw !== ''
            ? Json::decode($raw)
            : null;

        if ($payload === null || Arr::str($payload, 'sha') === '') {
            if ($payload !== null && trim(Arr::str($payload, 'error')) !== '') {
                throw new ApiException(
                    trim(Arr::str($payload, 'error')),
                    $response->getStatusCode(),
                    $response->getReasonPhrase(),
                    $method,
                    $url,
                    $payload,
                );
            }

            $message = trim($raw);
            if ($message === '') {
                $message = sprintf(
                    'request %s %s failed with status %d %s',
                    $method,
                    $url,
                    $response->getStatusCode(),
                    $response->getReasonPhrase(),
                );
            }

            throw new ApiException(
                $message,
                $response->getStatusCode(),
                $response->getReasonPhrase(),
                $method,
                $url,
                $raw,
            );
        }

        $result = NoteWriteResult::fromArray($payload);
        if (! $result->result->success) {
            $message = trim($result->result->message);
            if ($message === '') {
                $message = $label.' failed with status '.$result->result->status;
            }

            throw new RefUpdateException(
                $message,
                $result->result->status,
                RefUpdate::partial($result->targetRef, $result->baseCommit, $result->newRefSha),
            );
        }

        return $result;
    }

    /** @return array<string, string> */
    private static function fileQuery(string $path, ?string $ref, ?bool $ephemeral, ?bool $ephemeralBase): array
    {
        $query = ['path' => $path];
        if (trim((string) $ref) !== '') {
            $query['ref'] = (string) $ref;
        }
        self::addBool($query, 'ephemeral', $ephemeral);
        self::addBool($query, 'ephemeral_base', $ephemeralBase);

        return $query;
    }

    /** @return array<string, string> */
    private static function listingQuery(
        ?string $ref,
        ?bool $ephemeral,
        ?string $path,
        ?bool $recursive,
        ?string $cursor,
        ?int $limit,
    ): array {
        $query = [];
        self::addString($query, 'ref', $ref);
        self::addBool($query, 'ephemeral', $ephemeral);
        self::addString($query, 'path', $path);
        self::addBool($query, 'recursive', $recursive);
        self::addString($query, 'cursor', $cursor);
        self::addLimit($query, $limit);

        return $query;
    }

    private static function addString(array &$query, string $key, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $query[$key] = $value;
        }
    }

    private static function addBool(array &$query, string $key, ?bool $value): void
    {
        if ($value !== null) {
            $query[$key] = $value ? 'true' : 'false';
        }
    }

    private static function addLimit(array &$query, ?int $limit): void
    {
        if ($limit !== null && $limit > 0) {
            $query['limit'] = (string) $limit;
        }
    }

    /** @param list<string> $paths */
    private static function addPaths(array &$query, array $paths): void
    {
        $filtered = array_values(array_filter($paths, static fn (string $path): bool => trim($path) !== ''));
        if ($filtered !== []) {
            $query['path'] = $filtered;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function compact(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }

    private static function plainRefName(string $name, string $context): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException($context.' name is required');
        }
        if (str_starts_with($name, 'refs/')) {
            throw new InvalidArgumentException($context.' name must not start with refs/');
        }

        return $name;
    }

    private static function commitTarget(string $targetBranch, string $targetRef, string $context): string
    {
        $targetBranch = trim($targetBranch);
        $targetRef = trim($targetRef);

        if ($targetBranch !== '') {
            if (str_starts_with($targetBranch, 'refs/heads/')) {
                $branch = trim(substr($targetBranch, strlen('refs/heads/')));
                if ($branch === '') {
                    throw new InvalidArgumentException($context.' targetBranch is required');
                }

                return $branch;
            }
            if (str_starts_with($targetBranch, 'refs/')) {
                throw new InvalidArgumentException($context.' targetBranch must not include refs/ prefix');
            }

            return $targetBranch;
        }

        if ($targetRef === '') {
            throw new InvalidArgumentException($context.' targetBranch is required');
        }
        if (! str_starts_with($targetRef, 'refs/heads/')) {
            throw new InvalidArgumentException($context.' targetRef must start with refs/heads/');
        }
        $branch = trim(substr($targetRef, strlen('refs/heads/')));
        if ($branch === '') {
            throw new InvalidArgumentException($context.' targetRef must include a branch name');
        }

        return $branch;
    }

    private static function requiredMessage(string $commitMessage, string $context): string
    {
        $commitMessage = trim($commitMessage);
        if ($commitMessage === '') {
            throw new InvalidArgumentException($context.' commitMessage is required');
        }

        return $commitMessage;
    }

    private static function requiredAuthor(?CommitSignature $author, string $context): CommitSignature
    {
        if ($author === null || $author->name === '' || $author->email === '') {
            throw new InvalidArgumentException($context.' author name and email are required');
        }

        return $author;
    }

    private static function commitBaseBranch(string $baseBranch, bool $ephemeralBase, string $context): string
    {
        $baseBranch = trim($baseBranch);
        if ($baseBranch !== '' && str_starts_with($baseBranch, 'refs/')) {
            throw new InvalidArgumentException($context.' baseBranch must not include refs/ prefix');
        }
        if ($ephemeralBase && $baseBranch === '') {
            throw new InvalidArgumentException($context.' ephemeralBase requires baseBranch');
        }

        return $baseBranch;
    }
}
