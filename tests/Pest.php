<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Pulli\NextcloudWebdavUploader\NextcloudClient;

/**
 * Build a NextcloudClient backed by a Guzzle MockHandler instead of a real
 * connection. $responses is handed straight to MockHandler, and every
 * request made through the client is appended to $history (if given).
 *
 * @param  array<int, Response|callable>  $responses
 * @param  array<int, array{request: RequestInterface}>|null  $history
 */
function fakeNextcloudClient(array $responses, ?array &$history = [], array $options = []): NextcloudClient
{
    $handlerStack = HandlerStack::create(new MockHandler($responses));
    $handlerStack->push(Middleware::history($history));

    return new NextcloudClient(
        baseUrl: $options['baseUrl'] ?? 'https://cloud.test',
        username: $options['username'] ?? 'testuser',
        password: $options['password'] ?? 'testpass',
        chunkThreshold: $options['chunkThreshold'] ?? 4 * 1024 ** 3,
        chunkSize: $options['chunkSize'] ?? 512 * 1024 ** 2,
        timeoutSeconds: $options['timeoutSeconds'] ?? 30,
        httpClient: new Client(['handler' => $handlerStack, 'http_errors' => false]),
    );
}

function checksumPropfindResponse(string $sha1, int $status = 207): Response
{
    return new Response($status, [], <<<XML
        <?xml version="1.0"?>
        <d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
          <d:response>
            <d:propstat>
              <d:prop>
                <oc:checksums>
                  <oc:checksum>SHA1:{$sha1}</oc:checksum>
                </oc:checksums>
              </d:prop>
              <d:status>HTTP/1.1 200 OK</d:status>
            </d:propstat>
          </d:response>
        </d:multistatus>
        XML);
}

function noRemoteChecksum(): Response
{
    return new Response(404);
}
