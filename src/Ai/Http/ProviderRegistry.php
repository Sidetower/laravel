<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai\Http;

use Sidetower\Laravel\Ai\Http\Parsers\AnthropicParser;
use Sidetower\Laravel\Ai\Http\Parsers\GoogleParser;
use Sidetower\Laravel\Ai\Http\Parsers\OpenAiCompatibleParser;
use Sidetower\Laravel\Ai\Http\Parsers\ProviderParser;

/**
 * Which hosts are AI providers, and which parser reads each provider's answers.
 */
class ProviderRegistry
{
    /**
     * Host to provider name. `sidetower.ai.hosts` is added to this table and overrides it.
     */
    public const HOSTS = [
        'api.openai.com' => 'openai',
        'openrouter.ai' => 'openrouter',
        'api.anthropic.com' => 'anthropic',
        'generativelanguage.googleapis.com' => 'google',
        'api.mistral.ai' => 'mistral',
        'api.groq.com' => 'groq',
        'api.deepseek.com' => 'deepseek',
        'api.x.ai' => 'xai',
        'fal.run' => 'fal',
        'queue.fal.run' => 'fal',
        'api.elevenlabs.io' => 'elevenlabs',
    ];

    /**
     * @var array<string, ProviderParser>
     */
    protected array $parsers = [];

    /**
     * The provider name of a host (lower case), or null when the host is not an AI provider. This runs for every response
     * the application gets, so it stays a table lookup.
     */
    public function providerFor(string $host): ?string
    {
        $hosts = $this->hosts();

        if (isset($hosts[$host])) {
            return $hosts[$host];
        }

        foreach ($hosts as $pattern => $provider) {
            if (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1))) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * The default hosts with `sidetower.ai.hosts` applied. A configured host maps to a provider name; `*.example.com` matches
     * every subdomain; a null or empty provider removes a default host.
     *
     * @return array<string, string>
     */
    public function hosts(): array
    {
        $hosts = self::HOSTS;
        $configured = config('sidetower.ai.hosts', []);

        foreach (is_array($configured) ? $configured : [] as $host => $provider) {
            $host = strtolower(trim((string) $host));
            $provider = is_string($provider) ? strtolower(trim($provider)) : '';

            if ($host === '') {
                continue;
            }

            if ($provider === '') {
                unset($hosts[$host]);
            } else {
                $hosts[$host] = $provider;
            }
        }

        return $hosts;
    }

    /**
     * Providers without a parser of their own speak the OpenAI wire format, as most hosted and self-hosted models do.
     */
    public function parserFor(string $provider): ProviderParser
    {
        return $this->parsers[$provider] ??= match ($provider) {
            'anthropic' => new AnthropicParser,
            'google', 'gemini' => new GoogleParser,
            default => new OpenAiCompatibleParser,
        };
    }
}
