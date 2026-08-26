<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Records outgoing requests and replays queued responses. */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<string> */
    public array $bodies = [];

    /** @var list<ResponseInterface> */
    private array $responses;

    public function __construct(ResponseInterface ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public static function json(array $payload, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public static function text(string $body, int $status = 200, array $headers = []): ResponseInterface
    {
        return new Response($status, $headers, $body);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->bodies[] = (string) $request->getBody();

        $response = array_shift($this->responses);
        if ($response === null) {
            throw new RuntimeException('no queued response for '.$request->getMethod().' '.$request->getUri());
        }

        return $response;
    }

    public function lastRequest(): RequestInterface
    {
        $request = end($this->requests);
        if ($request === false) {
            throw new RuntimeException('no request was sent');
        }

        return $request;
    }

    public function lastBody(): string
    {
        $body = end($this->bodies);

        return $body === false ? '' : $body;
    }

    /** @return array<string, mixed> */
    public function lastJsonBody(): array
    {
        return json_decode($this->lastBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string|list<string>> Query parameters of the last request, repeated keys as lists. */
    public function lastQuery(): array
    {
        $query = [];
        foreach (explode('&', $this->lastRequest()->getUri()->getQuery()) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = rawurldecode($key);
            $value = rawurldecode($value);

            if (! array_key_exists($key, $query)) {
                $query[$key] = $value;

                continue;
            }
            $query[$key] = is_array($query[$key]) ? [...$query[$key], $value] : [$query[$key], $value];
        }

        return $query;
    }
}
