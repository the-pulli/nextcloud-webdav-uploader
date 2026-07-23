<?php

declare(strict_types=1);

namespace Pulli\NextcloudWebdavUploader;

use const STR_PAD_LEFT;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Pulli\NextcloudWebdavUploader\Exceptions\NextcloudException;

use function array_map;
use function basename;
use function ceil;
use function explode;
use function fclose;
use function filemtime;
use function filesize;
use function fopen;
use function getenv;
use function hash_file;
use function implode;
use function is_file;
use function is_resource;
use function json_decode;
use function libxml_use_internal_errors;
use function max;
use function mb_strlen;
use function mb_substr;
use function min;
use function rawurlencode;
use function rtrim;
use function simplexml_load_string;
use function sprintf;
use function str_pad;
use function str_starts_with;
use function substr;
use function trim;

class NextcloudClient
{
    private const int MAX_CHUNKS = 10000;

    private const string CHECKSUM_PROPFIND_BODY = <<<'XML'
        <?xml version="1.0"?>
        <d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
          <d:prop>
            <oc:checksums/>
          </d:prop>
        </d:propfind>
        XML;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private int $chunkThreshold,
        private int $chunkSize,
        private readonly int $timeoutSeconds,
        private ?ClientInterface $httpClient = null,
    ) {}

    /**
     * Build a client from the NEXTCLOUD_URL / NEXTCLOUD_USERNAME /
     * NEXTCLOUD_PASSWORD environment variables (e.g. loaded from a .env
     * file), with optional NEXTCLOUD_CHUNK_THRESHOLD / NEXTCLOUD_CHUNK_SIZE
     * / NEXTCLOUD_TIMEOUT overrides.
     */
    public static function fromEnv(): self
    {
        $url = self::env('NEXTCLOUD_URL');
        $username = self::env('NEXTCLOUD_USERNAME');
        $password = self::env('NEXTCLOUD_PASSWORD');

        if ($url === null || $username === null || $password === null) {
            throw new NextcloudException(
                'NEXTCLOUD_URL, NEXTCLOUD_USERNAME and NEXTCLOUD_PASSWORD must be set '.
                '(as environment variables or in a .env file).'
            );
        }

        return new self(
            baseUrl: $url,
            username: $username,
            password: $password,
            chunkThreshold: (int) (self::env('NEXTCLOUD_CHUNK_THRESHOLD') ?? 4 * 1024 ** 3),
            chunkSize: (int) (self::env('NEXTCLOUD_CHUNK_SIZE') ?? 512 * 1024 ** 2),
            timeoutSeconds: (int) (self::env('NEXTCLOUD_TIMEOUT') ?? 300),
        );
    }

    /**
     * Read an environment variable from whichever source has it: a real
     * process environment variable (getenv()), or one populated by
     * vlucas/phpdotenv, which — as of v5.6 — only writes to $_ENV/$_SERVER
     * and no longer calls putenv() by default.
     */
    private static function env(string $key): ?string
    {
        $value = getenv($key);

        if ($value !== false) {
            return $value;
        }

        return $_ENV[$key] ?? $_SERVER[$key] ?? null;
    }

    public function setChunkSize(int $bytes): void
    {
        $this->chunkSize = $bytes;
    }

    /**
     * Override the chunking threshold, e.g. to force the chunked upload path
     * for a small file when testing it without a real >4 GiB file on hand.
     */
    public function setChunkThreshold(int $bytes): void
    {
        $this->chunkThreshold = $bytes;
    }

    public function chunkThreshold(): int
    {
        return $this->chunkThreshold;
    }

    /**
     * Ensure every path segment of $folder exists on the Nextcloud files root,
     * creating whichever segments are missing. Idempotent — a 405 (segment
     * already exists) is not an error.
     */
    public function ensureRemoteDirectory(string $folder): void
    {
        $folder = trim($folder, '/');

        if ($folder === '') {
            return;
        }

        $built = '';

        foreach (explode('/', $folder) as $segment) {
            $built .= '/'.$segment;
            $response = $this->request('MKCOL', $this->filesUrl($built));

            if (! $this->successful($response) && $response->getStatusCode() !== 405) {
                throw new NextcloudException(sprintf(
                    "Failed to create remote folder '%s': %d %s",
                    $built,
                    $response->getStatusCode(),
                    $this->bodyExcerpt($response)
                ));
            }
        }
    }

    /**
     * Create (or reuse) a public link share for $folder and return its URL.
     * Idempotent — an existing public link share for the same path is reused
     * rather than creating a duplicate.
     */
    public function shareLink(string $folder): string
    {
        $path = '/'.trim($folder, '/');

        $existing = $this->request('GET', $this->ocsSharesUrl(), [
            'query' => ['path' => $path],
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (! $this->successful($existing)) {
            throw new NextcloudException(sprintf(
                "Failed to look up existing shares for '%s': %d %s",
                $path,
                $existing->getStatusCode(),
                $this->bodyExcerpt($existing)
            ));
        }

        $shares = json_decode((string) $existing->getBody(), true)['ocs']['data'] ?? [];

        foreach ($shares as $share) {
            if ((int) ($share['share_type'] ?? -1) === 3 && ! empty($share['url'])) {
                return $share['url'];
            }
        }

        $created = $this->request('POST', $this->ocsSharesUrl(), [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => [
                'path' => $path,
                'shareType' => 3, // public link
                'permissions' => 1, // read-only
            ],
        ]);

        if (! $this->successful($created)) {
            throw new NextcloudException(sprintf(
                "Failed to create a share link for '%s': %d %s",
                $path,
                $created->getStatusCode(),
                $this->bodyExcerpt($created)
            ));
        }

        $url = json_decode((string) $created->getBody(), true)['ocs']['data']['url'] ?? null;

        if (empty($url)) {
            throw new NextcloudException('Share was created but no URL was returned.');
        }

        return $url;
    }

    /**
     * Upload $localPath into $remoteFolder (relative to the user's files
     * root), automatically switching to Nextcloud's NG chunking API for
     * files above the configured threshold. Skips the transfer when the
     * destination already holds a file with an identical SHA1 checksum, and
     * verifies the remote checksum afterwards to confirm the upload landed
     * intact.
     *
     * @param  (callable(int $chunk, int $totalChunks): void)|null  $onChunkUploaded
     * @return array{path: string, skipped: bool}
     */
    public function upload(string $localPath, string $remoteFolder, ?callable $onChunkUploaded = null): array
    {
        if (! is_file($localPath)) {
            throw new NextcloudException("Local file not found: {$localPath}");
        }

        $size = (int) filesize($localPath);
        $remotePath = trim(sprintf('%s/%s', trim($remoteFolder, '/'), basename($localPath)), '/');
        $checksum = (string) hash_file('sha1', $localPath);

        if ($this->remoteChecksum($remotePath) === $checksum) {
            return ['path' => $remotePath, 'skipped' => true];
        }

        if ($size > $this->chunkThreshold) {
            $this->chunkedUpload($localPath, $remotePath, $size, $checksum, $onChunkUploaded);
        } else {
            $this->simpleUpload($localPath, $remotePath, $checksum);
        }

        $uploaded = $this->remoteChecksum($remotePath);

        if ($uploaded !== null && $uploaded !== $checksum) {
            throw new NextcloudException(sprintf(
                "Checksum mismatch after uploading '%s': expected SHA1 %s, got %s",
                $remotePath,
                $checksum,
                $uploaded
            ));
        }

        return ['path' => $remotePath, 'skipped' => false];
    }

    /**
     * The chunk size to actually use for a file of $totalSize bytes: the
     * configured chunk size, bumped up if it would otherwise exceed
     * Nextcloud's 10000-chunk limit.
     */
    public function effectiveChunkSize(int $totalSize): int
    {
        $chunkSize = $this->chunkSize;

        if ($chunkSize > 0 && (int) ceil($totalSize / $chunkSize) > self::MAX_CHUNKS) {
            $chunkSize = (int) ceil($totalSize / self::MAX_CHUNKS);
        }

        return $chunkSize;
    }

    /**
     * rawurlencode() every path segment individually so the slashes survive.
     */
    public static function encodePath(string $path): string
    {
        $path = trim($path, '/');

        if ($path === '') {
            return '';
        }

        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    private function simpleUpload(string $localPath, string $remotePath, string $checksum): void
    {
        $handle = fopen($localPath, 'rb');

        if ($handle === false) {
            throw new NextcloudException("Could not open file for reading: {$localPath}");
        }

        try {
            $response = $this->request('PUT', $this->filesUrl($remotePath), [
                'headers' => ['OC-Checksum' => sprintf('SHA1:%s', $checksum)],
                'body' => Utils::streamFor($handle),
            ]);

            if (! $this->successful($response)) {
                throw new NextcloudException(sprintf(
                    'Upload failed: %d %s',
                    $response->getStatusCode(),
                    $this->bodyExcerpt($response)
                ));
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @param  (callable(int $chunk, int $totalChunks): void)|null  $onChunkUploaded
     */
    private function chunkedUpload(string $localPath, string $remotePath, int $totalSize, string $checksum, ?callable $onChunkUploaded): void
    {
        $transferId = sprintf('kbm-%s', bin2hex(random_bytes(16)));
        $uploadsBase = $this->uploadsUrl($transferId);
        $destination = $this->filesUrl($remotePath);

        $mkcol = $this->request('MKCOL', $uploadsBase, [
            'headers' => ['Destination' => $destination],
        ]);

        if (! $this->successful($mkcol)) {
            throw new NextcloudException(sprintf(
                'Failed to start chunked upload: %d %s',
                $mkcol->getStatusCode(),
                $this->bodyExcerpt($mkcol)
            ));
        }

        $chunkSize = $this->effectiveChunkSize($totalSize);
        $chunkCount = (int) max(1, ceil($totalSize / $chunkSize));

        $handle = fopen($localPath, 'rb');

        if ($handle === false) {
            $this->abortChunkedUpload($uploadsBase);

            throw new NextcloudException("Could not open file for reading: {$localPath}");
        }

        // Wrapped once and reused for every chunk: a LimitStream's destructor
        // closes its decorated stream, so re-wrapping the raw handle inside
        // the loop would close $handle out from under the next iteration
        // once that chunk's LimitStream is garbage-collected.
        $stream = Utils::streamFor($handle);

        try {
            for ($chunk = 1; $chunk <= $chunkCount; $chunk++) {
                $offset = ($chunk - 1) * $chunkSize;
                $length = min($chunkSize, $totalSize - $offset);

                // A fresh LimitStream re-seeks the shared stream to $offset,
                // so each chunk reads the correct bytes regardless of where
                // the previous PUT left the file pointer.
                $body = new LimitStream($stream, $length, $offset);

                $response = $this->request('PUT', sprintf('%s/%s', $uploadsBase, str_pad((string) $chunk, 5, '0', STR_PAD_LEFT)), [
                    'headers' => [
                        'Destination' => $destination,
                        'OC-Total-Length' => (string) $totalSize,
                    ],
                    'body' => $body,
                ]);

                if (! $this->successful($response)) {
                    $this->abortChunkedUpload($uploadsBase);

                    throw new NextcloudException(sprintf(
                        'Chunk %d/%d failed: %d %s',
                        $chunk,
                        $chunkCount,
                        $response->getStatusCode(),
                        $this->bodyExcerpt($response)
                    ));
                }

                if ($onChunkUploaded !== null) {
                    $onChunkUploaded($chunk, $chunkCount);
                }
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $move = $this->request('MOVE', sprintf('%s/.file', $uploadsBase), [
            'headers' => [
                'Destination' => $destination,
                'OC-Total-Length' => (string) $totalSize,
                'X-OC-Mtime' => (string) filemtime($localPath),
                'OC-Checksum' => sprintf('SHA1:%s', $checksum),
            ],
        ]);

        if (! $this->successful($move)) {
            $this->abortChunkedUpload($uploadsBase);

            throw new NextcloudException(sprintf(
                'Failed to assemble chunks: %d %s',
                $move->getStatusCode(),
                $this->bodyExcerpt($move)
            ));
        }
    }

    private function abortChunkedUpload(string $uploadsBase): void
    {
        $this->request('DELETE', $uploadsBase);
    }

    /**
     * The SHA1 checksum Nextcloud has stored for $remotePath, or null when
     * the file doesn't exist remotely, or exists but has no stored checksum
     * (e.g. it was never uploaded with an OC-Checksum header).
     */
    private function remoteChecksum(string $remotePath): ?string
    {
        $response = $this->request('PROPFIND', $this->filesUrl($remotePath), [
            'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
            'body' => self::CHECKSUM_PROPFIND_BODY,
        ]);

        if ($response->getStatusCode() === 404) {
            return null;
        }

        if (! $this->successful($response)) {
            throw new NextcloudException(sprintf(
                "Failed to check the remote checksum for '%s': %d %s",
                $remotePath,
                $response->getStatusCode(),
                $this->bodyExcerpt($response)
            ));
        }

        $previousXmlErrorSetting = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) $response->getBody());
        libxml_use_internal_errors($previousXmlErrorSetting);

        if ($xml === false) {
            return null;
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $xml->registerXPathNamespace('oc', 'http://owncloud.org/ns');

        foreach ($xml->xpath('//d:prop/oc:checksums/oc:checksum') ?: [] as $node) {
            $value = (string) $node;

            if (str_starts_with($value, 'SHA1:')) {
                return substr($value, 5);
            }
        }

        return null;
    }

    private function filesUrl(string $path): string
    {
        return sprintf(
            '%s/remote.php/dav/files/%s/%s',
            rtrim($this->baseUrl, '/'),
            rawurlencode($this->username),
            static::encodePath($path)
        );
    }

    private function uploadsUrl(string $transferId): string
    {
        return sprintf(
            '%s/remote.php/dav/uploads/%s/%s',
            rtrim($this->baseUrl, '/'),
            rawurlencode($this->username),
            rawurlencode($transferId)
        );
    }

    private function ocsSharesUrl(): string
    {
        return sprintf('%s/ocs/v2.php/apps/files_sharing/api/v1/shares', rtrim($this->baseUrl, '/'));
    }

    private function request(string $method, string $uri, array $options = []): ResponseInterface
    {
        try {
            return $this->client()->request($method, $uri, $options);
        } catch (ConnectException $e) {
            throw new NextcloudException($e->getMessage(), previous: $e);
        }
    }

    private function successful(ResponseInterface $response): bool
    {
        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    private function bodyExcerpt(ResponseInterface $response, int $limit = 300): string
    {
        $body = (string) $response->getBody();

        return mb_strlen($body) > $limit ? mb_substr($body, 0, $limit).'...' : $body;
    }

    private function client(): ClientInterface
    {
        return $this->httpClient ??= new Client([
            'auth' => [$this->username, $this->password],
            'timeout' => $this->timeoutSeconds,
            'http_errors' => false,
            'headers' => ['OCS-APIRequest' => 'true'],
        ]);
    }
}
