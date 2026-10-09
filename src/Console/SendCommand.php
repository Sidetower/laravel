<?php

declare(strict_types=1);

namespace Sidetower\Laravel\Console;

use Illuminate\Console\Command;
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
    public function handle(Sidetower $sidetower, Sender $sender): int
    {
        if (! $sidetower->enabled()) {
            $this->components->info('Sidetower is off: it needs SIDETOWER_KEY and SIDETOWER_ENDPOINT, and is off in tests unless SIDETOWER_ENABLED=true.');

            return self::SUCCESS;
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
