<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * One call of the Http client as the parsers see it: the request, and the response or the connection failure. It reads
 * bodies carefully, because the application may be using them.
 *
 *  - A body is read only when it is seekable and of known size under a limit, and the stream is put back where it was.
 *    A body that is not seekable is a live stream (the `stream` option); it is never touched.
 *  - Only JSON and event streams are decoded, never audio or images.
 *  - Nothing here exposes text: parsers get decoded payloads and pick numbers out of them.
 */
final class HttpExchange
{
    private const MAX_REQUEST_BYTES = 1_048_576;

    private const MAX_RESPONSE_BYTES = 4_194_304;

    /**
     * @var array<string, mixed>|false|null
     */
    private array|false|null $requestJson = null;

    private ?ResponseBody $body = null;

    public function __construct(
        public readonly string $provider,
        public readonly Request $request,
        public readonly ?Response $response,
        public readonly ?ConnectionException $exception = null,
    ) {}

    public function method(): string
    {
        return strtoupper($this->request->method());
    }

    public function host(): string
    {
        return strtolower($this->request->toPsrRequest()->getUri()->getHost());
    }

    /**
     * The path of the request, decoded (Gemini writes `models/gemini-2.5-flash:generateContent`, sometimes with `%3A`).
     */
    public function path(): string
    {
        return rawurldecode($this->request->toPsrRequest()->getUri()->getPath());
    }

    /**
     * True for a 2xx response. False for any other status and for a connection failure.
     */
    public function successful(): bool
    {
        return $this->response?->successful() ?? false;
    }

    public function header(string $name): ?string
    {
        $value = $this->response?->header($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * From Guzzle's transfer statistics; a failed connection may carry its handler's total time.
     */
    public function durationMs(): ?int
    {
        $seconds = $this->response?->transferStats?->getTransferTime();

        if ($seconds === null) {
            $previous = $this->exception?->getPrevious();
            $context = $previous !== null && method_exists($previous, 'getHandlerContext') ? $previous->getHandlerContext() : [];
            $seconds = $context['total_time'] ?? null;
        }

        return is_numeric($seconds) ? max(0, (int) round((float) $seconds * 1000)) : null;
    }

    /**
     * The JSON body of the request, when it is JSON and small. Null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public function requestJson(): ?array
    {
        if ($this->requestJson === null) {
            $this->requestJson = $this->readRequestJson() ?? false;
        }

        return $this->requestJson === false ? null : $this->requestJson;
    }

    public function body(): ResponseBody
    {
        return $this->body ??= $this->readBody();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readRequestJson(): ?array
    {
        $psr = $this->request->toPsrRequest();

        if (! str_contains(strtolower($psr->getHeaderLine('Content-Type')), 'json')) {
            return null;
        }

        $text = $this->readStream($psr->getBody(), self::MAX_REQUEST_BYTES);
        $decoded = $text === null ? null : json_decode($text, true, 64);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    private function readBody(): ResponseBody
    {
        if ($this->response === null) {
            return ResponseBody::unreadable();
        }

        $psr = $this->response->toPsrResponse();
        $type = strtolower($psr->getHeaderLine('Content-Type'));
        $isStream = str_contains($type, 'event-stream');

        if ($type !== '' && ! $isStream && ! str_contains($type, 'json') && ! str_contains($type, 'text/plain')) {
            return ResponseBody::invalid();
        }

        $text = $this->readStream($psr->getBody(), self::MAX_RESPONSE_BYTES);

        if ($text === null) {
            return ResponseBody::unreadable();
        }

        $text = ltrim($text, "\xEF\xBB\xBF \t\r\n");

        if ($isStream || str_starts_with($text, 'data:') || str_starts_with($text, 'event:') || str_starts_with($text, ':')) {
            return ResponseBody::stream($this->eventPayloads($text));
        }

        return $this->jsonBody($text);
    }

    private function jsonBody(string $text): ResponseBody
    {
        $decoded = json_decode($text, true, 64);

        if (! is_array($decoded) || $decoded === []) {
            return ResponseBody::invalid();
        }

        if (! array_is_list($decoded)) {
            return ResponseBody::json([$decoded]);
        }

        $objects = array_values(array_filter($decoded, fn (mixed $payload): bool => is_array($payload) && $payload !== [] && ! array_is_list($payload)));

        return $objects === [] ? ResponseBody::invalid() : ResponseBody::json($objects);
    }

    /**
     * The JSON of the `data:` lines that mention usage. A stream can be megabytes of text deltas; those are skipped
     * without decoding.
     *
     * @return list<array<string, mixed>>
     */
    private function eventPayloads(string $text): array
    {
        $payloads = [];

        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            if (! str_starts_with($line, 'data:') || ! str_contains($line, 'usage')) {
                continue;
            }

            $payload = json_decode(ltrim(substr($line, 5)), true, 64);

            if (is_array($payload) && $payload !== [] && ! array_is_list($payload)) {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * The whole stream as text, or null when it must be left alone: not readable, not seekable (a live stream), of unknown
     * size or over the limit. The position is restored, so the application reads the body as if nobody had.
     */
    private function readStream(StreamInterface $stream, int $limit): ?string
    {
        try {
            if (! $stream->isReadable() || ! $stream->isSeekable()) {
                return null;
            }

            $size = $stream->getSize();

            if ($size === null || $size > $limit) {
                return null;
            }

            $position = $stream->tell();

            try {
                $stream->rewind();

                return $stream->getContents();
            } finally {
                $stream->seek($position);
            }
        } catch (Throwable) {
            return null;
        }
    }
}
