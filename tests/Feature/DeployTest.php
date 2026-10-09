<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Sidetower\Laravel\Buffer;
use Sidetower\Laravel\Release;

require_once __DIR__.'/../Support/helpers.php';

const SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
const OTHER_SHA = 'ffffffffffffffffffffffffffffffffffffffff';

beforeEach(function () {
    Carbon::setTestNow('2026-10-09T18:00:00Z');
    config()->set('sidetower.health.enabled', false);
    fakeServer();
});

function deploysSent(): array
{
    return collect(sentEnvelopes())->flatMap(fn (array $envelope) => $envelope['items'])->where('type', 'deploy')->values()->all();
}

function expectedDeployId(string $environment, string $release): string
{
    return 'deploy-'.substr(hash('sha256', "{$environment}|{$release}"), 0, 32);
}

describe('the release', function () {
    it('is the 7-character commit read from .git', function () {
        checkout('ref: refs/heads/main', ['refs/heads/main' => SHA]);

        expect(app(Release::class)->name())->toBe('a1b2c3d')
            ->and(app(Release::class)->commit())->toBe(SHA)
            ->and(app(Release::class)->branch())->toBe('main');
    });

    it('is the release setting when there is one', function () {
        checkout('ref: refs/heads/main', ['refs/heads/main' => SHA]);
        config()->set('sidetower.release', '2026.10.09-1');

        expect(app(Release::class)->name())->toBe('2026.10.09-1')
            ->and(app(Release::class)->commit())->toBe(SHA);
    });

    it('is at most 100 characters', function () {
        config()->set('sidetower.release', str_repeat('r', 150));

        expect(app(Release::class)->name())->toHaveLength(100);
    });

    it('reads a packed ref', function () {
        checkout('ref: refs/heads/main', ['packed-refs' => "# pack-refs with: peeled fully-peeled sorted\n".OTHER_SHA." refs/heads/other\n".SHA." refs/heads/main\n"]);

        expect(app(Release::class)->commit())->toBe(SHA);
    });

    it('reads a detached HEAD, which has no branch', function () {
        checkout(SHA);

        expect(app(Release::class)->commit())->toBe(SHA)
            ->and(app(Release::class)->branch())->toBeNull();
    });

    it('keeps the slashes of a branch name', function () {
        checkout('ref: refs/heads/feature/billing', ['refs/heads/feature/billing' => SHA]);

        expect(app(Release::class)->branch())->toBe('feature/billing');
    });

    it('is the name of a releases/<sha> directory when there is no .git', function () {
        $path = sys_get_temp_dir().'/sidetower-release-'.bin2hex(random_bytes(4)).'/releases/'.SHA;
        mkdir($path, 0777, true);
        app()->setBasePath($path);

        expect(app(Release::class)->commit())->toBe(SHA)
            ->and(app(Release::class)->name())->toBe('a1b2c3d')
            ->and(app(Release::class)->branch())->toBeNull();

        exec('rm -rf '.escapeshellarg(dirname($path, 2)));
    });

    it('follows a current symlink into the release directory', function () {
        $root = sys_get_temp_dir().'/sidetower-symlink-'.bin2hex(random_bytes(4));
        mkdir("{$root}/releases/".SHA, 0777, true);
        symlink("{$root}/releases/".SHA, "{$root}/current");
        app()->setBasePath("{$root}/current");

        expect(app(Release::class)->commit())->toBe(SHA);

        exec('rm -rf '.escapeshellarg($root));
    });

    it('is unknown without .git and without a release directory', function () {
        app()->setBasePath(sys_get_temp_dir());

        expect(app(Release::class)->name())->toBeNull()
            ->and(app(Release::class)->commit())->toBeNull();
    });

    it('does not follow a HEAD that points outside .git/refs', function () {
        checkout('ref: refs/../../../etc/passwd');

        expect(app(Release::class)->commit())->toBeNull();
    });

    it('does not trust a ref that is not a commit', function () {
        checkout('ref: refs/heads/main', ['refs/heads/main' => 'not a sha']);

        expect(app(Release::class)->commit())->toBeNull();
    });
});

describe('the deploy item', function () {
    it('is recorded the first time a release is seen', function () {
        checkout('ref: refs/heads/main', ['refs/heads/main' => SHA]);

        $this->artisan('sidetower:send')->assertSuccessful();

        expect(deploysSent())->toHaveCount(1)
            ->and(deploysSent()[0])->toMatchArray([
                'type' => 'deploy',
                'id' => expectedDeployId('testing', 'a1b2c3d'),
                'ts' => '2026-10-09T18:00:00.000Z',
                'release' => 'a1b2c3d',
                'commit' => SHA,
                'branch' => 'main',
            ]);
    });

    it('is recorded once per release, not at every run', function () {
        checkout('ref: refs/heads/main', ['refs/heads/main' => SHA]);

        $this->artisan('sidetower:send');
        $this->artisan('sidetower:send');
        $this->artisan('sidetower:send');

        expect(deploysSent())->toHaveCount(1)
            ->and(Cache::get(Buffer::RELEASE))->toBe('a1b2c3d');
    });

    it('is recorded again when the release changes', function () {
        $path = checkout('ref: refs/heads/main', ['refs/heads/main' => SHA]);

        $this->artisan('sidetower:send');

        file_put_contents("{$path}/.git/refs/heads/main", OTHER_SHA);
        $this->artisan('sidetower:send');

        expect(array_column(deploysSent(), 'release'))->toBe(['a1b2c3d', 'fffffff']);
    });

    it('uses the release setting for the release, and the commit for the commit', function () {
        checkout('ref: refs/heads/main', ['refs/heads/main' => SHA]);
        config()->set('sidetower.release', 'v1.4.0');

        $this->artisan('sidetower:send');

        expect(deploysSent()[0])->toMatchArray(['release' => 'v1.4.0', 'commit' => SHA, 'id' => expectedDeployId('testing', 'v1.4.0')]);
    });

    it('has only the release when nothing else is known', function () {
        app()->setBasePath(sys_get_temp_dir());
        config()->set('sidetower.release', 'v1.4.0');

        $this->artisan('sidetower:send');

        expect(deploysSent()[0])->not->toHaveKeys(['commit', 'branch']);
    });

    it('gets the same id from every server that notices the deploy', function () {
        checkout('ref: refs/heads/main', ['refs/heads/main' => SHA]);

        $this->artisan('sidetower:send');
        Cache::flush();
        Carbon::setTestNow('2026-10-09T18:00:45Z');
        $this->artisan('sidetower:send');

        $ids = array_column(deploysSent(), 'id');

        expect($ids)->toHaveCount(2)
            ->and($ids[0])->toBe($ids[1])
            ->and($ids[0])->toMatch('/^deploy-[0-9a-f]{32}$/');
    });

    it('has an id that differs between environments', function () {
        checkout('ref: refs/heads/main', ['refs/heads/main' => SHA]);
        config()->set('sidetower.environment', 'staging');

        $this->artisan('sidetower:send');

        expect(deploysSent()[0]['id'])->toBe(expectedDeployId('staging', 'a1b2c3d'))
            ->and(expectedDeployId('staging', 'a1b2c3d'))->not->toBe(expectedDeployId('production', 'a1b2c3d'));
    });

    it('is not recorded when the release is unknown', function () {
        app()->setBasePath(sys_get_temp_dir());

        $this->artisan('sidetower:send');

        expect(deploysSent())->toBe([]);
    });

    it('is not recorded when deploys are off', function () {
        checkout('ref: refs/heads/main', ['refs/heads/main' => SHA]);
        config()->set('sidetower.deploys', false);

        $this->artisan('sidetower:send');

        expect(deploysSent())->toBe([]);
    });

    it('is kept for the next run when the server cannot be reached, and not recorded twice', function () {
        checkout('ref: refs/heads/main', ['refs/heads/main' => SHA]);
        fakeServer(Http::response([], 503));

        $this->artisan('sidetower:send');

        expect(buffered('deploy'))->toHaveCount(1)
            ->and(Cache::get(Buffer::RELEASE))->toBe('a1b2c3d');

        $this->artisan('sidetower:send');

        expect(buffered('deploy'))->toHaveCount(1);
    });

    it('goes out in the source of the envelope as the release', function () {
        checkout('ref: refs/heads/main', ['refs/heads/main' => SHA]);

        $this->artisan('sidetower:send');

        expect(sentEnvelopes()[0]['source']['release'])->toBe('a1b2c3d');
    });
});
