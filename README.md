# code-storage-php

Pierre Code Storage SDK for PHP — a port of
[`code-storage-go`](https://github.com/pierrecomputer/sdk/tree/main/packages/code-storage-go).

## Installation

```bash
composer require igzard/code-storage-php
```

The SDK talks HTTP through any [PSR-18](https://www.php-fig.org/psr/psr-18/) client.
If you already have one installed (Guzzle, Symfony HttpClient, ...) it is discovered
automatically; otherwise pass your own:

```php
$client = new Client(name: 'your-name', key: $pem, httpClient: $guzzle);
```

## Usage

```php
use Igzard\CodeStorage\Client;

$client = new Client(
    name: 'your-name',
    key: "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----",
);

$repo = $client->createRepo();

echo $repo->remoteUrl(), PHP_EOL;
echo $repo->importRemoteUrl(), PHP_EOL;
```

Every option is a named argument, every response is a readonly object. TTLs are
integer seconds.

### Inspect file metadata

```php
use Igzard\CodeStorage\Model\FileRequestHeaders;

$meta = $repo->headFile(
    path: 'README.md',
    ref: 'main',
    headers: new FileRequestHeaders(range: 'bytes=0-1023', ifNoneMatch: '"b10b5ha"'),
);

echo $meta->statusCode, $meta->etag, $meta->contentRange;
```

### Download an archive

`archiveStream()` and `fileStream()` return the raw PSR-7 response, so you can pipe
the body wherever you need it.

```php
$response = $repo->archiveStream(
    ref: 'main',
    includeGlobs: ['README.md'],
    excludeGlobs: ['vendor/**'],
    maxBlobSize: 1024 * 1024,
    archivePrefix: 'repo/',
);

$body = $response->getBody();
while (! $body->eof()) {
    fwrite($out, $body->read(65536));
}
```

### List files with metadata

```php
$result = $repo->listFilesWithMetadata(ref: 'feature/demo', ephemeral: true);

echo $result->ref;
echo $result->files[0]->lastCommitSha;
echo $result->commits[$result->files[0]->lastCommitSha]->author;
```

### Blame a file

```php
$blame = $repo->getBlame(
    path: 'src/main.php',
    ref: 'main',
    ranges: ['10,30'],
    detectMoves: true,
);

foreach ($blame->lines as $line) {
    printf("%d (%s): %s — %s\n", $line->lineNumber, substr($line->commitSha, 0, 7), $line->authorName, $line->summary);
}
```

`ranges` accepts repeated `git blame -L` specs (`"10,30"`, `"/getUser/,/^}/"`,
`"10,+5"`, `"10,"`, `",30"`, `"10"`, `":funcname"`). Up to 16 per request; omit to
blame the whole file. The top-level `commitSha` is the SHA `ref` resolved to; each
`BlameLine` carries its authoring commit's metadata inline, with
`previousCommitSha` empty when the line has no prior version.

### Manage tags and branches

```php
$tags = $repo->listTags(limit: 10);
$repo->createTag(name: 'v1.0.0', target: '0123456789abcdef0123456789abcdef01234567');
$repo->deleteTag(name: 'v1.0.0');

$repo->deleteBranch(name: 'feature/old-onboarding');

// Set ephemeral to delete a branch from the ephemeral namespace
$deleted = $repo->deleteBranch(name: 'merge/123e4567-e89b-12d3-a456-426614174000', ephemeral: true);
echo $deleted->ephemeral;
```

### Manage notes

Notes default to `refs/notes/commits`. Set `ref` to target another notes ref; a bare
name like `reviews` is placed under `refs/notes/` (a fully-qualified `refs/notes/*`
ref also works). Custom refs must be enabled server-side.

```php
$repo->createNote(sha: $sha, note: 'LGTM', ref: 'reviews');

$note = $repo->getNote(sha: $sha, ref: 'reviews');
echo $note->note;

// Discover custom notes namespaces with cursor pagination.
$refs = $repo->listNotesRefs(prefix: 'reviews/', limit: 20);
foreach ($refs->refs as $entry) {
    echo $entry->ref, ' ', $entry->sha, PHP_EOL;
}
if ($refs->hasMore) {
    $repo->listNotesRefs(prefix: 'reviews/', cursor: $refs->nextCursor);
}
```

### Preview and perform merges

```php
use Igzard\CodeStorage\Enum\MergeStrategy;
use Igzard\CodeStorage\Model\CommitSignature;

$preview = $repo->previewMerge(sourceBranch: 'feature', targetBranch: 'main', includeContent: true);
echo $preview->status?->value, $preview->result?->value, implode(',', $preview->conflictPaths);

$result = $repo->merge(
    sourceBranch: 'feature',
    sourceIsEphemeral: true,
    targetBranch: 'main',
    // Leave expectedTargetSha empty to merge into the current target tip.
    // Set it to require targetBranch to still point at that commit; moved targets return 409.
    strategy: MergeStrategy::Merge,
    author: new CommitSignature('Merge Bot', 'merge@example.com'),
);

echo $result->result->value, $result->target->newSha;
```

### Create a commit

```php
$result = $repo
    ->createCommit(
        targetBranch: 'main',
        commitMessage: 'Update docs',
        author: new CommitSignature('Docs Bot', 'docs@example.com'),
    )
    ->addFileFromString('docs/readme.md', "# Updated\n")
    ->send();

echo $result->commitSha;
```

`addFile()` also accepts a stream resource or a PSR-7 `StreamInterface`, so large
blobs never have to be held in memory. A builder cannot be reused after `send()`.

### Inspect commit parents

`listCommits()` and `getCommit()` expose parent SHAs in Git parent order. Root
commits return an empty array.

```php
foreach ($repo->listCommits(branch: 'main', limit: 20)->commits as $commit) {
    echo $commit->sha, ' ', implode(' ', $commit->parentShas), PHP_EOL;
}
```

### Get an applicable commit diff

```php
$diff = $repo->getCommitDiff(
    sha: 'head-commit-sha',
    baseSha: 'base-commit-sha',
    gitApplyCompatible: true,
);
```

`gitApplyCompatible` generates raw diffs for use with `git apply`. When no files are
filtered and every changed file has a non-empty `raw`, concatenate each
`$diff->files[$i]->raw` in response order to produce a patch for the exact base tree.

### Hydrate a repo without an API request

```php
$repo = $client->repo(
    id: 'repo-id',
    defaultBranch: 'main',
    createdAt: '2024-06-15T12:00:00Z',
);

echo $repo->remoteUrl();
```

### Sync from a public GitHub base repository

```php
use Igzard\CodeStorage\Enum\GitHubAuthType;
use Igzard\CodeStorage\Model\GitHubBaseRepo;

$repo = $client->createRepo(baseRepo: new GitHubBaseRepo(
    owner: 'octocat',
    name: 'hello-world',
    authType: GitHubAuthType::Public,
));

echo $repo->id;
```

### Validate webhooks

```php
use Igzard\CodeStorage\Model\WebhookPushEvent;
use Igzard\CodeStorage\Webhook;

$validation = Webhook::validate($rawBody, getallheaders(), $secret);

if (! $validation->valid) {
    http_response_code(400);
    exit($validation->error);
}

if ($validation->payload instanceof WebhookPushEvent) {
    echo $validation->payload->ref, $validation->payload->after;
}
```

## Errors

| Exception | Raised when |
| --- | --- |
| `InvalidArgumentException` | Client-side option validation fails. |
| `Exception\ApiException` | The API answers with a non-2xx status. Carries `status`, `method`, `url` and the decoded `body`. |
| `Exception\RefUpdateException` | A commit, note write or restore was refused. Carries `status`, a normalized `reason` and the partial `refUpdate`. |

## Features

- Create, list, find, and delete repositories.
- Generate authenticated git remote URLs, including import and ephemeral variants.
- Read files, read file metadata, download archives, list branches/commits, and run grep queries.
- Create commits via streaming commit-pack or diff-commit endpoints.
- Restore commits, merge branches, manage git notes, create branches, and manage tags.
- Validate webhook signatures and parse push events.

## Development

```bash
composer install
composer test
```
