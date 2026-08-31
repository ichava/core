<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Support\IchavaSessionManager;

beforeEach(function () {
    $this->session = app(IchavaSessionManager::class);
});

describe('IchavaSessionManager::isAvailable', function () {
    it('returns a boolean reflecting session availability', function () {
        expect($this->session->isAvailable())->toBeBool();
    });

    it('reports a tier label that is one of the supported strings', function () {
        $tier = $this->session->getTier();
        expect($tier)->toBeIn(['session', 'browser']);
    });
});

describe('IchavaSessionManager::put / get / has / forget', function () {
    beforeEach(function () {
        // Tests that need a session push the testbench session driver into
        // the array store; if the host doesn't expose one, every put() is
        // a no-op and we assert that contract instead.
    });

    it('round-trips when a session is available', function () {
        if (! $this->session->isAvailable()) {
            $this->markTestSkipped('Host has no session; nothing to round-trip.');
        }

        $stored = $this->session->put('theme', 'dark');
        expect($stored)->toBeTrue();
        expect($this->session->has('theme'))->toBeTrue();
        expect($this->session->get('theme'))->toBe('dark');

        $forgot = $this->session->forget('theme');
        expect($forgot)->toBeTrue();
        expect($this->session->has('theme'))->toBeFalse();
    });

    it('falls back gracefully when no session is available', function () {
        if ($this->session->isAvailable()) {
            $this->markTestSkipped('Session is available; gated path covered elsewhere.');
        }

        // put() returns false, get() returns the default, has() is false.
        expect($this->session->put('x', 1))->toBeFalse();
        expect($this->session->get('x', 'fallback'))->toBe('fallback');
        expect($this->session->has('x'))->toBeFalse();
    });
});

describe('IchavaSessionManager::all', function () {
    it('returns an array', function () {
        expect($this->session->all())->toBeArray();
    });
});

describe('IchavaSessionManager::clear', function () {
    it('returns a boolean', function () {
        expect($this->session->clear())->toBeBool();
    });
});

describe('IchavaSessionManager::putAll', function () {
    it('writes every key when session is available', function () {
        if (! $this->session->isAvailable()) {
            $this->markTestSkipped('Host has no session.');
        }
        $ok = $this->session->putAll(['a' => 1, 'b' => 2]);
        expect($ok)->toBeTrue();
        expect($this->session->get('a'))->toBe(1);
        expect($this->session->get('b'))->toBe(2);
    });
});

describe('IchavaSessionManager::getBrowserId', function () {
    it('returns null or a string identifier', function () {
        $id = $this->session->getBrowserId();
        expect($id === null || is_string($id))->toBeTrue();
    });
});
