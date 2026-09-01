<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Support\FtsLanguageHelper;

describe('FtsLanguageHelper static config readers', function () {
    it('returns the configured strategy', function () {
        config(['ichava.core.database.search.strategy' => 'multilingual']);
        expect(FtsLanguageHelper::getStrategy())->toBe('multilingual');

        config(['ichava.core.database.search.strategy' => 'single']);
        expect(FtsLanguageHelper::getStrategy())->toBe('single');
    });

    it('rejects unknown languages and falls back to simple', function () {
        config(['ichava.core.database.search.language' => 'klingon']);
        expect(FtsLanguageHelper::getPrimaryLanguage())->toBe('simple');

        config(['ichava.core.database.search.language' => 'english']);
        expect(FtsLanguageHelper::getPrimaryLanguage())->toBe('english');
    });

    it('exposes isMultilingual / isSimple booleans driven by strategy', function () {
        config(['ichava.core.database.search.strategy' => 'multilingual']);
        expect(FtsLanguageHelper::isMultilingual())->toBeTrue();
        expect(FtsLanguageHelper::isSimple())->toBeFalse();

        config(['ichava.core.database.search.strategy' => 'simple']);
        expect(FtsLanguageHelper::isMultilingual())->toBeFalse();
        expect(FtsLanguageHelper::isSimple())->toBeTrue();
    });
});

describe('FtsLanguageHelper search-scope helpers', function () {
    it('reports configured scopes as enabled / disabled', function () {
        config([
            'ichava.core.database.search.scope' => [
                'name' => true,
                'category' => false,
                'variant' => true,
            ],
        ]);
        expect(FtsLanguageHelper::isScopeEnabled('name'))->toBeTrue();
        expect(FtsLanguageHelper::isScopeEnabled('category'))->toBeFalse();
        expect(FtsLanguageHelper::isScopeEnabled('variant'))->toBeTrue();
        // Missing keys default off.
        expect(FtsLanguageHelper::isScopeEnabled('whatever-else'))->toBeFalse();
    });
});

describe('FtsLanguageHelper getStemExample', function () {
    it('returns a deterministic example for english', function () {
        $sample = FtsLanguageHelper::getStemExample('english');
        expect($sample)->toBeArray();
    });

    it('returns an array even for an unknown language', function () {
        $sample = FtsLanguageHelper::getStemExample('does-not-exist');
        expect($sample)->toBeArray();
    });
});
