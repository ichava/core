<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

describe('Package Exceptions', function () {
    describe('PackageRegistrationException', function () {
        it('creates exception for missing metadata', function () {
            $exception = IchavaException::missingPackageMetadata(
                'test/package',
                ['Icon set name', 'Prefix']
            );

            expect($exception)->toBeInstanceOf(IchavaException::class);
            expect($exception->getMessage())->toContain('Package registration failed');
            expect($exception->getMessage())->toContain('test/package');
            expect($exception->getMessage())->toContain('Icon set name');
            expect($exception->getMessage())->toContain('Prefix');
        });

        it('creates exception for class not found', function () {
            $exception = IchavaException::packageClassNotFound(
                'test/package',
                'NonExistentClass'
            );

            expect($exception)->toBeInstanceOf(IchavaException::class);
            expect($exception->getMessage())->toContain('class does not exist');
            expect($exception->getMessage())->toContain('NonExistentClass');
        });

        it('creates exception for path not found', function () {
            $exception = IchavaException::packagePathNotFound(
                'test/package',
                '/non/existent/path'
            );

            expect($exception)->toBeInstanceOf(IchavaException::class);
            expect($exception->getMessage())->toContain('base path does not exist');
            expect($exception->getMessage())->toContain('/non/existent/path');
        });

        it('creates exception for icon set conflict', function () {
            $exception = IchavaException::iconSetNameConflict(
                'test-icons',
                'package1',
                'package2',
                'TestProvider'
            );

            expect($exception)->toBeInstanceOf(IchavaException::class);
            expect($exception->getMessage())->toContain('Icon set name conflict');
            expect($exception->getMessage())->toContain('test-icons');
            expect($exception->getMessage())->toContain('package1');
            expect($exception->getMessage())->toContain('package2');
        });
    });

    describe('IconSetConfigurationException', function () {
        it('creates exception for invalid configuration', function () {
            $exception = IchavaException::configurationValidationFailed(
                'TestProvider',
                ['Missing base path', 'Invalid prefix']
            );

            expect($exception)->toBeInstanceOf(IchavaException::class);
            expect($exception->getMessage())->toContain('Ichava package configuration failed');
            expect($exception->getMessage())->toContain('TestProvider');
            expect($exception->getMessage())->toContain('Missing base path');
        });

        it('creates exception for build failed', function () {
            $exception = IchavaException::configurationBuildFailed(
                'TestIconSet',
                'Invalid path structure'
            );

            expect($exception)->toBeInstanceOf(IchavaException::class);
            expect($exception->getMessage())->toContain('Icon set configuration build failed');
            expect($exception->getMessage())->toContain('TestIconSet');
            expect($exception->getMessage())->toContain('Invalid path structure');
        });

        it('creates exception for missing method', function () {
            $exception = IchavaException::configurationMissingMethod(
                'TestProvider',
                'getIconSetName'
            );

            expect($exception)->toBeInstanceOf(IchavaException::class);
            expect($exception->getMessage())->toContain('Configuration method missing');
            expect($exception->getMessage())->toContain('getIconSetName');
        });
    });

    describe('PackageConflictException', function () {
        it('creates exception for prefix conflict', function () {
            $exception = IchavaException::packagePrefixConflict(
                'test',
                ['package1', 'package2']
            );

            expect($exception)->toBeInstanceOf(IchavaException::class);
            expect($exception->getMessage())->toContain('Package prefix conflict');
            expect($exception->getMessage())->toContain('test');
            expect($exception->getMessage())->toContain('package1');
            expect($exception->getMessage())->toContain('package2');
        });

        it('creates exception for blade component conflict', function () {
            $exception = IchavaException::packageBladeComponentConflict(
                'test-icon',
                ['package1', 'package2']
            );

            expect($exception)->toBeInstanceOf(IchavaException::class);
            expect($exception->getMessage())->toContain('Blade component conflict');
            expect($exception->getMessage())->toContain('test-icon');
        });
    });
});
