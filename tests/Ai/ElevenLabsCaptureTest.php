<?php

use GuzzleHttp\Psr7\PumpStream;
use Illuminate\Support\Facades\Http;
use Sidetower\Laravel\Tests\Ai\AiTestHelpers;

beforeEach(fn () => Http::preventStrayRequests());

const MP3_BYTES = "ID3\x04\x00\x00\x00\x00\x00\x23TSSE\x00\x00\x00\x0f\x00\x00\x03Lavf60.16.100\x00\xff\xfb\x90\x64\x00\x00\x00";

it('counts the characters of the text of a speech request', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response(MP3_BYTES, 200, ['Content-Type' => 'audio/mpeg', 'request-id' => 'req_abc'])]);

    Http::withHeaders(['xi-api-key' => 'xi-secret'])->post('https://api.elevenlabs.io/v1/text-to-speech/JBFqnCBsd6RMkjVDRZzb?output_format=mp3_44100_128', [
        'text' => 'Merhaba dünya, bu gizli bir metin.',
        'model_id' => 'eleven_flash_v2_5',
    ]);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage',
        'operation' => 'unknown',
        'provider' => 'elevenlabs',
        'model' => 'eleven_flash_v2_5',
        'status' => 'ok',
        'units' => 34,
        'unitType' => 'character',
    ]);

    expect(json_encode(AiTestHelpers::items()))->not->toContain('gizli')->not->toContain('JBFq')->not->toContain('xi-secret');
});

it('prefers the character cost the response reports', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response(MP3_BYTES, 200, ['Content-Type' => 'audio/mpeg', 'character-cost' => '17'])]);

    Http::post('https://api.elevenlabs.io/v1/text-to-speech/voice123', ['text' => 'Hello there, friend!', 'model_id' => 'eleven_multilingual_v2']);

    expect(AiTestHelpers::only())->toMatchArray(['units' => 17, 'unitType' => 'character']);
});

it('assumes the default model when the request names none', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response(MP3_BYTES, 200, ['Content-Type' => 'audio/mpeg'])]);

    Http::post('https://api.elevenlabs.io/v1/text-to-speech/voice123', ['text' => 'Hi']);

    expect(AiTestHelpers::only())->toMatchArray(['model' => 'eleven_multilingual_v2', 'units' => 2]);
});

it('never reads the audio of the response or a stream of it', function () {
    $touched = false;
    $audio = new PumpStream(function () use (&$touched) {
        $touched = true;

        return false;
    });
    Http::fake(['api.elevenlabs.io/*' => Http::response($audio, 200, ['Content-Type' => 'audio/mpeg'])]);

    Http::post('https://api.elevenlabs.io/v1/text-to-speech/voice123/stream', ['text' => 'Hello', 'model_id' => 'eleven_turbo_v2_5']);

    expect($touched)->toBeFalse()
        ->and(AiTestHelpers::only())->toMatchArray(['units' => 5, 'status' => 'ok']);
});

it('counts the text of every input of a dialogue', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response(MP3_BYTES, 200, ['Content-Type' => 'audio/mpeg'])]);

    Http::post('https://api.elevenlabs.io/v1/text-to-dialogue', [
        'inputs' => [['text' => 'Hello', 'voice_id' => 'a'], ['text' => 'Hi there', 'voice_id' => 'b']],
        'model_id' => 'eleven_v3',
    ]);

    expect(AiTestHelpers::only())->toMatchArray(['model' => 'eleven_v3', 'units' => 13, 'unitType' => 'character']);
});

it('handles the timestamps form', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response(['audio_base64' => base64_encode(MP3_BYTES), 'alignment' => ['characters' => ['H', 'i']]])]);

    Http::post('https://api.elevenlabs.io/v1/text-to-speech/voice123/with-timestamps', ['text' => 'Hi']);

    expect(AiTestHelpers::only())->toMatchArray(['units' => 2]);
});

it('records a failed speech request as an error without units', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => ['status' => 'quota_exceeded', 'message' => 'You have run out of credits']], 401)]);

    Http::post('https://api.elevenlabs.io/v1/text-to-speech/voice123', ['text' => 'Hello', 'model_id' => 'eleven_flash_v2_5']);

    expect(AiTestHelpers::only())->toBe([
        'type' => 'ai.usage', 'operation' => 'unknown', 'provider' => 'elevenlabs', 'model' => 'eleven_flash_v2_5', 'status' => 'error',
    ]);
});

it('records a 2xx for a request without text as an invalid response', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response(MP3_BYTES, 200, ['Content-Type' => 'audio/mpeg'])]);

    Http::post('https://api.elevenlabs.io/v1/text-to-speech/voice123', ['model_id' => 'eleven_flash_v2_5']);

    expect(AiTestHelpers::only())->toMatchArray(['status' => 'invalid_response'])
        ->and(AiTestHelpers::only())->not->toHaveKey('units');
});

it('ignores the endpoints of ElevenLabs that are not text to speech', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response(['voices' => []])]);

    Http::get('https://api.elevenlabs.io/v1/voices');
    Http::get('https://api.elevenlabs.io/v1/text-to-speech/voice123');
    Http::post('https://api.elevenlabs.io/v1/speech-to-text', ['model_id' => 'scribe_v1']);

    expect(AiTestHelpers::items())->toBe([]);
});
