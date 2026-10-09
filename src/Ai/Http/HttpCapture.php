<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Sidetower\Laravel\Ai\AiUsage;
use Sidetower\Laravel\Ai\Debug;
use Sidetower\Laravel\Ai\Setting;
use Sidetower\Laravel\Sidetower;
use Throwable;

/**
 * Records AI usage from the Http client's events. Prism, laravel/ai and hand-written calls to a provider all go through the
 * client, so one listener sees them.
 *
 * The listener runs for every response the application gets, so the first thing it does is look the host up in a table and
 * leave when it is not an AI provider. Whatever happens after that stays inside: it never throws and never reads a body it
 * should not (see HttpExchange).
 */
class HttpCapture
{
    public function __construct(
        protected Sidetower $sidetower,
        protected AiUsage $usage,
        protected ProviderRegistry $providers,
    ) {}

    public function responseReceived(ResponseReceived $event): void
    {
        $this->capture($event->request, $event->response, null);
    }

    public function connectionFailed(ConnectionFailed $event): void
    {
        $this->capture($event->request, null, $event->exception);
    }

    protected function capture(Request $request, ?Response $response, ?ConnectionException $exception): void
    {
        try {
            if (! Setting::enabled('http')) {
                return;
            }

            $provider = $this->providers->providerFor(strtolower($request->toPsrRequest()->getUri()->getHost()));

            if ($provider === null || ! $this->sidetower->enabled()) {
                return;
            }

            $call = new HttpExchange($provider, $request, $response, $exception);
            $parser = $this->providers->parserFor($provider);

            if (! $parser->handles($call)) {
                return;
            }

            $fields = $parser->parse($call);

            if ($fields !== null) {
                $this->usage->record($fields);
            }
        } catch (Throwable $e) {
            Debug::caught($e);
        }
    }
}
