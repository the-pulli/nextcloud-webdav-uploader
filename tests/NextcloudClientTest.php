<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Pulli\NextcloudWebdavUploader\Exceptions\NextcloudException;
use Pulli\NextcloudWebdavUploader\NextcloudClient;

it('rawurlencodes every path segment but keeps slashes', function () {
    expect(NextcloudClient::encodePath('Photos/2026 Trip/img one.jpg'))
        ->toBe('Photos/2026%20Trip/img%20one.jpg');
});

it('strips leading and trailing slashes before encoding', function () {
    expect(NextcloudClient::encodePath('/Documents/'))->toBe('Documents');
});

it('encodes umlauts and special characters in path segments', function () {
    expect(NextcloudClient::encodePath('Bestellungsübersicht/Rechnung.pdf'))
        ->toBe('Bestellungs%C3%BCbersicht/Rechnung.pdf');
});

it('returns an empty string for an empty or root path', function () {
    expect(NextcloudClient::encodePath(''))->toBe('')
        ->and(NextcloudClient::encodePath('/'))->toBe('');
});

it('uses the configured chunk size when it stays under the 10000-chunk cap', function () {
    $client = new NextcloudClient('https://cloud.test', 'user', 'pass', chunkThreshold: 100, chunkSize: 10, timeoutSeconds: 30);

    expect($client->effectiveChunkSize(1000))->toBe(10);
});

it('bumps the chunk size up when the configured size would exceed 10000 chunks', function () {
    $client = new NextcloudClient('https://cloud.test', 'user', 'pass', chunkThreshold: 100, chunkSize: 1, timeoutSeconds: 30);

    $chunkSize = $client->effectiveChunkSize(50_000);

    expect($chunkSize)->toBeGreaterThan(1)
        ->and((int) ceil(50_000 / $chunkSize))->toBeLessThanOrEqual(10000);
});

it('exposes the configured chunk threshold', function () {
    $client = new NextcloudClient('https://cloud.test', 'user', 'pass', chunkThreshold: 4_294_967_296, chunkSize: 10, timeoutSeconds: 30);

    expect($client->chunkThreshold())->toBe(4_294_967_296);
});

it('allows overriding the chunk size and threshold after construction', function () {
    $client = new NextcloudClient('https://cloud.test', 'user', 'pass', chunkThreshold: 100, chunkSize: 10, timeoutSeconds: 30);

    $client->setChunkSize(20);
    $client->setChunkThreshold(200);

    expect($client->effectiveChunkSize(40))->toBe(20)
        ->and($client->chunkThreshold())->toBe(200);
});

it('creates nested remote folders before uploading, tolerating an already-exists 405', function () {
    $history = [];
    $client = fakeNextcloudClient([
        new Response(405), // Documents already exists
        new Response(201), // Documents/2026 created
    ], $history);

    $client->ensureRemoteDirectory('Documents/2026');

    expect($history)->toHaveCount(2)
        ->and((string) $history[0]['request']->getUri())->toBe('https://cloud.test/remote.php/dav/files/testuser/Documents')
        ->and((string) $history[1]['request']->getUri())->toBe('https://cloud.test/remote.php/dav/files/testuser/Documents/2026')
        ->and($history[0]['request']->getMethod())->toBe('MKCOL');
});

it('throws when creating a remote folder fails for a reason other than already-exists', function () {
    $client = fakeNextcloudClient([new Response(500, [], 'boom')]);

    expect(fn () => $client->ensureRemoteDirectory('Documents'))
        ->toThrow(NextcloudException::class, 'boom');
});

it('skips uploading a file whose remote checksum already matches the local one', function () {
    $file = tempnam(sys_get_temp_dir(), 'nwu-');
    file_put_contents($file, 'hello world');
    $checksum = hash_file('sha1', $file);

    $history = [];
    $client = fakeNextcloudClient([checksumPropfindResponse($checksum)], $history);

    $result = $client->upload($file, 'Documents');

    expect($result)->toBe(['path' => 'Documents/'.basename($file), 'skipped' => true])
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('PROPFIND');

    unlink($file);
});

it('sends an OC-Checksum header on a simple upload and verifies it afterwards', function () {
    $file = tempnam(sys_get_temp_dir(), 'nwu-');
    file_put_contents($file, 'hello world');
    $checksum = hash_file('sha1', $file);

    $history = [];
    $client = fakeNextcloudClient([
        noRemoteChecksum(),                    // pre-check: nothing there yet
        new Response(201),                     // PUT
        checksumPropfindResponse($checksum),   // post-verify
    ], $history);

    $result = $client->upload($file, 'Documents');

    expect($result['skipped'])->toBeFalse()
        ->and($history[1]['request']->getMethod())->toBe('PUT')
        ->and($history[1]['request']->getHeaderLine('OC-Checksum'))->toBe("SHA1:{$checksum}");

    unlink($file);
});

it('throws when the checksum after upload does not match the local file', function () {
    $file = tempnam(sys_get_temp_dir(), 'nwu-');
    file_put_contents($file, 'hello world');

    $client = fakeNextcloudClient([
        noRemoteChecksum(),
        new Response(201),
        checksumPropfindResponse('0000000000000000000000000000000000000d'),
    ]);

    expect(fn () => $client->upload($file, 'Documents'))
        ->toThrow(NextcloudException::class, 'Checksum mismatch');

    unlink($file);
});

it('chunks a file larger than the threshold via MKCOL, numbered PUTs, and a checksummed MOVE', function () {
    $file = tempnam(sys_get_temp_dir(), 'nwu-');
    file_put_contents($file, '0123456789A'); // 11 bytes

    $checksum = hash_file('sha1', $file);

    // Chunk bodies must be read synchronously as each mock request comes in
    // — the file handle is closed as soon as upload() returns, so reading
    // them back from $history afterwards would hit an already-closed stream.
    $bodies = [];
    $captureBody = function ($request) use (&$bodies) {
        $bodies[] = (string) $request->getBody();

        return new Response(201);
    };

    $history = [];
    $client = fakeNextcloudClient([
        noRemoteChecksum(),                  // pre-check
        new Response(201),                   // MKCOL uploads/<id>
        $captureBody,                        // chunk 1
        $captureBody,                        // chunk 2
        $captureBody,                        // chunk 3
        new Response(201),                   // MOVE
        checksumPropfindResponse($checksum), // post-verify
    ], $history, ['chunkThreshold' => 10, 'chunkSize' => 4]);

    $progressed = [];
    $result = $client->upload($file, 'Backups', function (int $chunk, int $total) use (&$progressed) {
        $progressed[] = [$chunk, $total];
    });

    expect($result['skipped'])->toBeFalse()
        ->and($progressed)->toBe([[1, 3], [2, 3], [3, 3]])
        ->and($bodies)->toBe(['0123', '4567', '89A']);

    $move = $history[5]['request'];
    expect($move->getMethod())->toBe('MOVE')
        ->and($move->getHeaderLine('OC-Total-Length'))->toBe('11')
        ->and($move->getHeaderLine('OC-Checksum'))->toBe("SHA1:{$checksum}");

    unlink($file);
});

it('aborts the chunked upload (DELETE) when a chunk PUT fails', function () {
    $file = tempnam(sys_get_temp_dir(), 'nwu-');
    file_put_contents($file, '0123456789A');

    $history = [];
    $client = fakeNextcloudClient([
        noRemoteChecksum(),
        new Response(201), // MKCOL uploads/<id>
        new Response(500, [], 'nope'), // chunk 1 fails
        new Response(201), // DELETE (abort)
    ], $history, ['chunkThreshold' => 10, 'chunkSize' => 4]);

    expect(fn () => $client->upload($file, 'Backups'))
        ->toThrow(NextcloudException::class, 'Chunk 1/3 failed');

    expect(end($history)['request']->getMethod())->toBe('DELETE');

    unlink($file);
});

it('reuses an existing public link share instead of creating a duplicate', function () {
    $history = [];
    $client = fakeNextcloudClient([
        new Response(200, [], json_encode(['ocs' => ['data' => [
            ['share_type' => 3, 'url' => 'https://cloud.test/s/existing123'],
        ]]])),
    ], $history);

    expect($client->shareLink('Backups'))->toBe('https://cloud.test/s/existing123')
        ->and($history)->toHaveCount(1);
});

it('ignores non-public shares and creates a new link when none is public', function () {
    $client = fakeNextcloudClient([
        new Response(200, [], json_encode(['ocs' => ['data' => [
            ['share_type' => 0, 'url' => null],
        ]]])),
        new Response(200, [], json_encode(['ocs' => ['data' => ['url' => 'https://cloud.test/s/new456']]])),
    ]);

    expect($client->shareLink('Backups'))->toBe('https://cloud.test/s/new456');
});

it('throws when looking up existing shares fails', function () {
    $client = fakeNextcloudClient([new Response(500)]);

    expect(fn () => $client->shareLink('Backups'))->toThrow(NextcloudException::class);
});

it('throws when the share is created but no url is returned', function () {
    $client = fakeNextcloudClient([
        new Response(200, [], json_encode(['ocs' => ['data' => []]])),
        new Response(200, [], json_encode(['ocs' => ['data' => []]])),
    ]);

    expect(fn () => $client->shareLink('Backups'))->toThrow(NextcloudException::class, 'no URL was returned');
});
