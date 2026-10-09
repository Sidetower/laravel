<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Sidetower\Laravel\Buffer;
use Sidetower\Laravel\Release;

require_once __DIR__.'/../Support/helpers.php';

const SDK_SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
const SDK_OTHER_SHA = 'ffffffffffffffffffffffffffffffffffffffff';

beforeEach(function () {
    Carbon::setTestNow('2026-10-09T18:00:00Z');
    config()->set('sidetower.health.enabled', false);
    sdkFakeServer();
});

function sdkDeploysSent(): array
{
    return collect(sdkSentEnvelopes())->flatMap(fn (array $envelope) => $envelope['items'])->where('type', 'deploy')->values()->all();
}

function sdkExpectedDeployId(string $environment, string $release): string
{
    return 'deploy-'.substr(hash('sha256', "{$environment}|{$release}"), 0, 32);
}

describe('the release', function () {
    it('is the 7-character commit read from .git', function () {
        sdkCheckout('ref: refs/heads/main', ['refs/heads/main' => SDK_SHA]);

        expect(app(Release::class)->name())->toBe('a1b2c3d')
            ->and(app(Release::class)->commit())->toBe(SDK_SHA)
            ->and(app(Release::class)->branch())->toBe('main');
    });

    it('is the release setting when there is one', function () {
        sdkCheckout('ref: refs/heads/main', ['refs/heads/main' => SDK_SHA]);
        config()->set('sidetower.release', '2026.10.09-1');

        expect(app(Release::class)->name())->toBe('2026.10.09-1')
            ->and(app(Release::class)->commit())->toBe(SDK_SHA);
    });

    it('is at most 100 characters', function () {
        config()->set('sidetower.release', str_repeat('r', 150));

        expect(app(Release::class)->name())->toHaveLength(100);
    });

    it('reads a packed ref', function () {
        sdkCheckout('ref: refs/heads/main', ['packed-refs' => "# pack-refs with: peeled fully-peeled sorted\n".SDK_OTHER_SHA." refs/heads/other\n".SDK_SHA." refs/heads/main\n"]);

        expect(app(Release::class)->commit())->toBe(SDK_SHA);
    });

    it('reads a detached HEAD, which has no branch', function () {
        sdkCheckout(SDK_SHA);

        expect(app(Release::class)->commit())->toBe(SDK_SHA)
            ->and(app(Release::class)->branch())->toBeNull();
    });

    it('keeps the slashes of a branch name', function () {
        sdkCheckout('ref: refs/heads/feature/billing', ['refs/heads/feature/billing' => SDK_SHA]);

        expect(app(Release::class)->branch())->toBe('feature/billing');
    });

    it('is the name of a releases/<sha> directory when there is no .git', function () {
        $path = sys_get_temp_dir().'/sidetower-release-'.bin2hex(random_bytes(4)).'/releases/'.SDK_SHA;
        mkdir($path, 0777, true);
        app()->setBasePath($path);

        expect(app(Release::class)->commit())->toBe(SDK_SHA)
            ->and(app(Release::class)->name())->toBe('a1b2c3d')
            ->and(app(Release::class)->branch())->toBeNull();

        exec('rm -rf '.escapeshellarg(dirname($path, 2)));
    });

    it('follows a current symlink into the release directory', function () {
        $root = sys_get_temp_dir().'/sidetower-symlink-'.bin2hex(random_bytes(4));
        mkdir("{$root}/releases/".SDK_SHA, 0777, true);
        symlink("{$root}/releases/".SDK_SHA, "{$root}/current");
        app()->setBasePath("{$root}/current");

        expect(app(Release::class)->commit())->toBe(SDK_SHA);

        exec('rm -rf '.escapeshellarg($root));
    });

    it('is unknown without .git and without a release directory', function () {
        app()->setBasePath(sys_get_temp_dir());

        expect(app(Release::class)->name())->toBeNull()
            ->and(app(Release::class)->commit())->toBeNull();
    });

    it('does not follow a HEAD that points outside .git/refs', function () {
        sdkCheckout('ref: refs/../../../etc/passwd');

        expect(app(Release::class)->commit())->toBeNull();
    });

    it('does not trust a ref that is not a commit', function () {
        sdkCheckout('ref: refs/heads/main', ['refs/heads/main' => 'not a sha']);

        expect(app(Release::class)->commit())->toBeNull();
    });
});

describe('the deploy item', function () {
    it('is recorded the first time a release is seen', function () {
        sdkCheckout('ref: refs/heads/main', ['refs/heads/main' => SDK_SHA]);

        $this->artisan('sidetower:send')->assertSuccessful();

        expect(sdkDeploysSent())->toHaveCount(1)
            ->and(sdkDeploysSent()[0])->toMatchArray([
                'type' => 'deploy',
                'id' => sdkExpectedDeployId('testing', 'a1b2c3d'),
                'ts' => '2026-10-09T18:00:00.000Z',
                'release' => 'a1b2c3d',
                'commit' => SDK_SHA,
                'branch' => 'main',
            ]);
    });

    it('is recorded once per release, not at every run', function () {
        sdkCheckout('ref: refs/heads/main', ['refs/heads/main' => SDK_SHA]);

        $this->artisan('sidetower:send');
        $this->artisan('sidetower:send');
        $this->artisan('sidetower:send');

        expect(sdkDeploysSent())->toHaveCount(1)
            ->and(Cache::get(Buffer::RELEASE))->toBe('a1b2c3d');
    });

    it('is recorded again when the release changes', function () {
        $path = sdkCheckout('ref: refs/heads/main', ['refs/heads/main' => SDK_SHA]);

        $this->artisan('sidetower:send');

        file_put_contents("{$path}/.git/refs/heads/main", SDK_OTHER_SHA);
        $this->artisan('sidetower:send');

        expect(array_column(sdkDeploysSent(), 'release'))->toBe(['a1b2c3d', 'fffffff']);
    });

    it('uses the release setting for the release, and the commit for the commit', function () {
        sdkCheckout('ref: refs/heads/main', ['refs/heads/main' => SDK_SHA]);
        config()->set('sidetower.release', 'v1.4.0');

        $this->artisan('sidetower:send');

        expect(sdkDeploysSent()[0])->toMatchArray(['release' => 'v1.4.0', 'commit' => SDK_SHA, 'id' => sdkExpectedDeployId('testing', 'v1.4.0')]);
    });

    it('has only the release when nothing else is known', function () {
        app()->setBasePath(sys_get_temp_dir());
        config()->set('sidetower.release', 'v1.4.0');

        $this->artisan('sidetower:send');

        expect(sdkDeploysSent()[0])->not->toHaveKeys(['commit', 'branch']);
    });

    it('gets the same id from every server that notices the deploy', function () {
        sdkCheckout('ref: refs/heads/main', ['refs/heads/main' => SDK_SHA]);

        $this->artisan('sidetower:send');
        Cache::flush();
        Carbon::setTestNow('2026-10-09T18:00:45Z');
        $this->artisan('sidetower:send');

        $ids = array_column(sdkDeploysSent(), 'id');

        expect($ids)->toHaveCount(2)
            ->and($ids[0])->toBe($ids[1])
            ->and($ids[0])->toMatch('/^deploy-[0-9a-f]{32}$/');
    });

    it('has an id that differs between environments', function () {
        sdkCheckout('ref: refs/heads/main', ['refs/heads/main' => SDK_SHA]);
        config()->set('sidetower.environment', 'staging');

        $this->artisan('sidetower:send');

        expect(sdkDeploysSent()[0]['id'])->toBe(sdkExpectedDeployId('staging', 'a1b2c3d'))
            ->and(sdkExpectedDeployId('staging', 'a1b2c3d'))->not->toBe(sdkExpectedDeployId('production', 'a1b2c3d'));
    });

    it('is not recorded when the release is unknown', function () {
        app()->setBasePath(sys_get_temp_dir());

        $this->artisan('sidetower:send');

        expect(sdkDeploysSent())->toBe([]);
    });

    it('is not recorded when deploys are off', function () {
        sdkCheckout('ref: refs/heads/main', ['refs/heads/main' => SDK_SHA]);
        config()->set('sidetower.deploys', false);

        $this->artisan('sidetower:send');

        expect(sdkDeploysSent())->toBe([]);
    });

    it('is kept for the next run when the server cannot be reached, and not recorded twice', function () {
        sdkCheckout('ref: refs/heads/main', ['refs/heads/main' => SDK_SHA]);
        sdkFakeServer(Http::response([], 503));

        $this->artisan('sidetower:send');

        expect(sdkBuffered('deploy'))->toHaveCount(1)
            ->and(Cache::get(Buffer::RELEASE))->toBe('a1b2c3d');

        $this->artisan('sidetower:send');

        expect(sdkBuffered('deploy'))->toHaveCount(1);
    });

    it('goes out in the source of the envelope as the release', function () {
        sdkCheckout('ref: refs/heads/main', ['refs/heads/main' => SDK_SHA]);

        $this->artisan('sidetower:send');

        expect(sdkSentEnvelopes()[0]['source']['release'])->toBe('a1b2c3d');
    });
});
