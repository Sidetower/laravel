<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Ai;

use Closure;
use Sidetower\Laravel\Sidetower;
use Throwable;

/**
 * AI usage: operation scopes and explicit records. Calls that go through Laravel's Http client are captured on their own
 * (HttpCapture); everything captured here carries the operation name and attributes of the scope it was made in.
 */
class AiUsage
{
    /**
     * Open scopes, outermost first. Keyed by a token so a scope that ends out of order (fibers, Octane coroutines) removes
     * itself and nothing else.
     *
     * @var array<int, array{name: string, attributes: array<string, mixed>}>
     */
    protected array $scopes = [];

    protected int $lastScope = 0;

    public function __construct(
        protected Sidetower $sidetower,
        protected OperationResolver $defaults,
    ) {}

    /**
     * Runs the callback in a named scope: every AI item recorded inside gets this operation name and these attributes. In a
     * nested scope the innermost name wins and the attributes of all open scopes merge, the inner ones overriding. The scope
     * ends when the callback returns or throws.
     *
     * Attributes are flat: at most 20 entries, keys up to 40 characters, values text up to 200 characters, numbers or
     * booleans. No prompts, responses or personal data.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function operation(string $name, Closure $callback, array $attributes = []): mixed
    {
        $token = ++$this->lastScope;
        $this->scopes[$token] = ['name' => $name, 'attributes' => $attributes];

        try {
            return $callback();
        } finally {
            unset($this->scopes[$token]);
        }
    }

    /**
     * Records the usage of a call that does not go through the Http client. The fields are the protocol's `ai.usage`
     * fields; `operation` defaults to the current one and the attributes of the open scopes are merged under `attributes`.
     * `provider` and `model` should be given; `status` defaults to `ok`. Never throws.
     *
     * @param  array<string, mixed>  $usage
     */
    public function record(array $usage): void
    {
        try {
            $usage['operation'] = filled($usage['operation'] ?? null) ? $usage['operation'] : $this->currentOperation();
            $usage['attributes'] = $this->merge($this->currentAttributes(), is_array($usage['attributes'] ?? null) ? $usage['attributes'] : []);

            $this->sidetower->record(UsageItem::make($usage));
        } catch (Throwable $e) {
            Debug::caught($e);
        }
    }

    /**
     * The operation name items get right now: the innermost scope, else the running job, route or command, else `unknown`.
     */
    public function currentOperation(): string
    {
        $scope = end($this->scopes);

        if ($scope !== false && trim($scope['name']) !== '') {
            return $scope['name'];
        }

        return $this->defaults->resolve();
    }

    /**
     * The attributes of all open scopes, merged.
     *
     * @return array<string, mixed>
     */
    public function currentAttributes(): array
    {
        return $this->merge(...array_column($this->scopes, 'attributes'));
    }

    /**
     * @param  array<array-key, mixed>  ...$sets
     * @return array<array-key, mixed>
     */
    protected function merge(array ...$sets): array
    {
        $merged = [];

        foreach ($sets as $set) {
            foreach ($set as $key => $value) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
