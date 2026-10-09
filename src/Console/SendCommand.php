<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Console;

use Illuminate\Console\Command;
use Sidetower\Laravel\Buffer;
use Sidetower\Laravel\Sender;
use Sidetower\Laravel\Sidetower;
use Throwable;

class SendCommand extends Command
{
    protected $signature = 'sidetower:send';

    protected $description = 'Send the items waiting in the Sidetower buffer, with this server\'s health and check-ins';

    /**
     * Always succeeds: an endpoint that cannot be reached is not a failure of the scheduler.
     */
    public function handle(Sidetower $sidetower, Sender $sender, Buffer $buffer): int
    {
        if (($reason = $sidetower->offBecause()) !== null) {
            $this->components->info("Sidetower is off: {$reason}.");

            return self::SUCCESS;
        }

        if (! $buffer->persistent()) {
            $this->components->warn('The cache store of the buffer does not keep items between processes (array or null). Set SIDETOWER_CACHE_STORE to a shared store such as redis, database or file.');
        }

        try {
            $drain = $sender->run();
        } catch (Throwable $exception) {
            $sidetower->log('debug', 'sidetower:send failed: '.$exception->getMessage());

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Sidetower: %d sent, %d rejected, %d dropped, %d waiting (%d %s).',
            $drain->sent,
            $drain->rejected,
            $drain->dropped,
            $drain->remaining,
            $drain->requests,
            $drain->requests === 1 ? 'request' : 'requests',
        ));

        return self::SUCCESS;
    }
}
