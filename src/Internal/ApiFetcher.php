<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Internal;

use Igzard\CodeStorage\Exception\ApiException;
use Igzard\CodeStorage\Version;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Thin JSON transport over PSR-18.
 *
 * @internal
 */
final class ApiFetcher
{
    private readonly string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly int $version,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function basePath(): string
    {
        return $this->baseUrl.'/api/v'.$this->version;
    }

    /**
     * @param  array<string, string|list<string>>  $query
     * @param  array<string, mixed>|null  $body
     * @param  list<int>  $allowedStatus  Non-2xx statuses returned to the caller instead of throwing.
     * @param  array<string, string>  $extraHeaders
     *
     * @throws ApiException
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        string $jwt = '',
        array $allowedStatus = [],
        array $extraHeaders = [],
    ): ResponseInterface {
        $url = $this->buildUrl($path, $query);
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Authorization', 'Bearer '.$jwt)
            ->withHeader('Code-Storage-Agent', Version::userAgent());

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(Json::encode($body)));
        }
        foreach ($extraHeaders as $name => $value) {
            if ($value !== '') {
                $request = $request->withHeader($name, $value);
            }
        }

        $response = $this->httpClient->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return $response;
        }
        if (in_array($status, $allowedStatus, true)) {
            return $response;
        }

        throw self::error($response, $method, $url);
    }

    /** @param array<string, string|list<string>> $query */
    public function get(string $path, array $query = [], string $jwt = '', array $allowedStatus = [], array $extraHeaders = []): ResponseInterface
    {
        return $this->request('GET', $path, $query, null, $jwt, $allowedStatus, $extraHeaders);
    }

    /** @param array<string, string|list<string>> $query */
    public function head(string $path, array $query = [], string $jwt = '', array $allowedStatus = [], array $extraHeaders = []): ResponseInterface
    {
        return $this->request('HEAD', $path, $query, null, $jwt, $allowedStatus, $extraHeaders);
    }

    /** @param array<string, mixed>|null $body */
    public function post(string $path, ?array $body = null, string $jwt = '', array $allowedStatus = []): ResponseInterface
    {
        return $this->request('POST', $path, [], $body, $jwt, $allowedStatus);
    }

    /** @param array<string, mixed>|null $body */
    public function put(string $path, ?array $body = null, string $jwt = '', array $allowedStatus = []): ResponseInterface
    {
        return $this->request('PUT', $path, [], $body, $jwt, $allowedStatus);
    }

    /** @param array<string, mixed>|null $body */
    public function delete(string $path, ?array $body = null, string $jwt = '', array $allowedStatus = []): ResponseInterface
    {
        return $this->request('DELETE', $path, [], $body, $jwt, $allowedStatus);
    }

    /** Sends an NDJSON stream; non-2xx responses are returned, not thrown. */
    public function stream(string $method, string $url, string $jwt, StreamInterface $body): ResponseInterface
    {
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Authorization', 'Bearer '.$jwt)
            ->withHeader('Content-Type', 'application/x-ndjson')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Code-Storage-Agent', Version::userAgent())
            ->withBody($body);

        return $this->httpClient->sendRequest($request);
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory;
    }

    /** Decodes a JSON response body into an array. */
    public static function json(ResponseInterface $response): array
    {
        return Json::decode((string) $response->getBody()) ?? [];
    }

    /** Builds the ApiException for a non-2xx response, mirroring the server's error envelope. */
    public static function error(ResponseInterface $response, string $method, string $url): ApiException
    {
        $raw = (string) $response->getBody();
        $parsed = null;
        $message = '';

        if (str_contains($response->getHeaderLine('Content-Type'), 'application/json')) {
            $payload = Json::decode($raw);
            if ($payload !== null) {
                $parsed = $payload;
                $error = $payload['error'] ?? null;
                if (is_string($error) && trim($error) !== '') {
                    $message = trim($error);
                }
            }
        }
        if ($message === '' && $raw !== '') {
            $message = trim($raw);
            if ($message !== '') {
                $parsed = $message;
            }
        }
        if ($message === '') {
            $message = sprintf(
                'request %s %s failed with status %d %s',
                $method,
                $url,
                $response->getStatusCode(),
                $response->getReasonPhrase(),
            );
        }

        return new ApiException(
            $message,
            $response->getStatusCode(),
            $response->getReasonPhrase(),
            $method,
            $url,
            $parsed,
        );
    }

    /** @param array<string, string|list<string>> $query */
    private function buildUrl(string $path, array $query): string
    {
        $url = $this->basePath().'/'.$path;
        $pairs = [];
        foreach ($query as $key => $value) {
            foreach ((array) $value as $item) {
                $pairs[] = rawurlencode($key).'='.rawurlencode((string) $item);
            }
        }

        return $pairs === [] ? $url : $url.'?'.implode('&', $pairs);
    }
}
