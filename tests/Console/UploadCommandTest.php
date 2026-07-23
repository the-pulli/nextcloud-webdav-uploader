<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Pulli\NextcloudWebdavUploader\Console\UploadCommand;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/nwu-command-'.bin2hex(random_bytes(6));
    mkdir($this->tmpDir);
});

afterEach(function () {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tmpDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($this->tmpDir);
});

it('fails with a clear error when no --file option is given', function () {
    $tester = new CommandTester(new UploadCommand(fakeNextcloudClient([])));

    $tester->execute(['folder' => 'Documents']);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('No files given');
});

it('fails when a given local file does not exist', function () {
    $tester = new CommandTester(new UploadCommand(fakeNextcloudClient([])));

    $tester->execute(['folder' => 'Documents', '--file' => [$this->tmpDir.'/missing.txt']]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('File not found');
});

it('uploads a file end to end and reports it as verified', function () {
    $file = $this->tmpDir.'/report.txt';
    file_put_contents($file, 'hello world');
    $checksum = hash_file('sha1', $file);

    $client = fakeNextcloudClient([
        new Response(201),                     // MKCOL Documents
        noRemoteChecksum(),                     // pre-check
        new Response(201),                      // PUT
        checksumPropfindResponse($checksum),    // post-verify
    ]);

    $tester = new CommandTester(new UploadCommand($client));
    $tester->execute(['folder' => 'Documents', '--file' => [$file]]);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('verified');
});

it('reports a skipped file as unchanged without failing the command', function () {
    $file = $this->tmpDir.'/report.txt';
    file_put_contents($file, 'hello world');
    $checksum = hash_file('sha1', $file);

    $client = fakeNextcloudClient([
        new Response(201),                    // MKCOL Documents
        checksumPropfindResponse($checksum),  // pre-check: already up to date
    ]);

    $tester = new CommandTester(new UploadCommand($client));
    $tester->execute(['folder' => 'Documents', '--file' => [$file]]);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('unchanged, skipped');
});

it('uploads only a directory\'s direct files by default, as a subfolder named after it', function () {
    mkdir($this->tmpDir.'/Project');
    mkdir($this->tmpDir.'/Project/Sub');
    file_put_contents($this->tmpDir.'/Project/top.txt', 'top');
    file_put_contents($this->tmpDir.'/Project/Sub/nested.txt', 'nested');

    $history = [];
    $client = fakeNextcloudClient([
        new Response(201), new Response(201), new Response(201), // MKCOL Backups, Backups/Project, Backups (again, bare folder arg)
        noRemoteChecksum(), new Response(201), checksumPropfindResponse(hash('sha1', 'top')),
    ], $history);

    $tester = new CommandTester(new UploadCommand($client));
    $tester->execute(['folder' => 'Backups', '--file' => [$this->tmpDir.'/Project']]);

    expect($tester->getStatusCode())->toBe(0);

    $uris = array_map(fn ($h) => (string) $h['request']->getUri(), $history);
    expect($uris)->not->toContain('https://cloud.test/remote.php/dav/files/testuser/Backups/Project/Sub/nested.txt');
});

it('includes nested subdirectories when --include-subdirs is passed, preserving structure', function () {
    mkdir($this->tmpDir.'/Project');
    mkdir($this->tmpDir.'/Project/Sub');
    file_put_contents($this->tmpDir.'/Project/top.txt', 'top');
    file_put_contents($this->tmpDir.'/Project/Sub/nested.txt', 'nested');

    $history = [];
    $client = fakeNextcloudClient(array_fill(0, 20, new Response(201)), $history);

    $tester = new CommandTester(new UploadCommand($client));
    $tester->execute(['folder' => 'Backups', '--file' => [$this->tmpDir.'/Project'], '--include-subdirs' => true]);

    expect($tester->getStatusCode())->toBe(0);

    $mkcolFolders = array_values(array_map(
        fn ($h) => (string) $h['request']->getUri(),
        array_filter($history, fn ($h) => $h['request']->getMethod() === 'MKCOL')
    ));

    expect($mkcolFolders)->toContain('https://cloud.test/remote.php/dav/files/testuser/Backups/Project/Sub');
});

it('creates a share link for the target folder and prints it with --share-dir', function () {
    $file = $this->tmpDir.'/f.txt';
    file_put_contents($file, 'x');
    $checksum = hash_file('sha1', $file);

    $history = [];
    $client = fakeNextcloudClient([
        new Response(201),                   // MKCOL Shared
        noRemoteChecksum(),                  // pre-check
        new Response(201),                   // PUT
        checksumPropfindResponse($checksum), // post-verify
        new Response(200, [], json_encode(['ocs' => ['data' => []]])), // share lookup
        new Response(200, [], json_encode(['ocs' => ['data' => ['url' => 'https://cloud.test/s/abc123']]])), // share create
    ], $history);

    $tester = new CommandTester(new UploadCommand($client));
    $tester->execute(['folder' => 'Shared', '--file' => [$file], '--share-dir' => true]);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Share link: https://cloud.test/s/abc123');

    $shareLookup = $history[4]['request'];
    expect($shareLookup->getUri()->getQuery())->toContain('path=%2FShared');
});

it('creates a share link for the uploaded file and prints it with --share-file', function () {
    $file = $this->tmpDir.'/f.txt';
    file_put_contents($file, 'x');
    $checksum = hash_file('sha1', $file);

    $history = [];
    $client = fakeNextcloudClient([
        new Response(201),                   // MKCOL Shared
        noRemoteChecksum(),                  // pre-check
        new Response(201),                   // PUT
        checksumPropfindResponse($checksum), // post-verify
        new Response(200, [], json_encode(['ocs' => ['data' => []]])), // share lookup
        new Response(200, [], json_encode(['ocs' => ['data' => ['url' => 'https://cloud.test/s/def456']]])), // share create
    ], $history);

    $tester = new CommandTester(new UploadCommand($client));
    $tester->execute(['folder' => 'Shared', '--file' => [$file], '--share-file' => true]);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Share link: https://cloud.test/s/def456');

    $shareLookup = $history[4]['request'];
    expect($shareLookup->getUri()->getQuery())->toContain('path=%2FShared%2Ff.txt');
});

it('fails when --share-file is passed with more than one uploaded file', function () {
    $fileA = $this->tmpDir.'/a.txt';
    $fileB = $this->tmpDir.'/b.txt';
    file_put_contents($fileA, 'a');
    file_put_contents($fileB, 'b');

    $client = fakeNextcloudClient(array_fill(0, 20, new Response(201)));

    $tester = new CommandTester(new UploadCommand($client));
    $tester->execute(['folder' => 'Shared', '--file' => [$fileA, $fileB], '--share-file' => true]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('--share-file requires exactly one file');
});

it('fails when both --share-dir and --share-file are passed', function () {
    $file = $this->tmpDir.'/f.txt';
    file_put_contents($file, 'x');

    $client = fakeNextcloudClient([]);

    $tester = new CommandTester(new UploadCommand($client));
    $tester->execute(['folder' => 'Shared', '--file' => [$file], '--share-dir' => true, '--share-file' => true]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('Pass either --share-dir or --share-file, not both');
});
