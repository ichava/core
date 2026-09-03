<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Exceptions;

use RuntimeException;

/**
 * Base exception for all Ichava-related errors
 *
 * Provides static factory methods for common error scenarios.
 * Consolidates all Ichava exception types into a single, maintainable class.
 */
class IchavaException extends RuntimeException
{
    /**
     * SVG content rejected by the sanitizer (empty, malformed, or non-svg root).
     */
    public static function invalidSvgContent(string $reason): static
    {
        return new static("Invalid SVG content: {$reason}");
    }

    /**
     * Icon set already registered
     */
    public static function iconSetAlreadyRegistered(string $name): static
    {
        return new static(
            "Icon set '{$name}' is already registered. " .
            "Use IconRegistry::package('{$name}') to register a different icon set.",
        );
    }

    /**
     * Icon set not found
     */
    public static function iconSetNotFound(string $name): static
    {
        return new static("Icon set '{$name}' not found.");
    }

    /**
     * Invalid icon set configuration
     */
    public static function invalidConfiguration(string $reason): static
    {
        return new static("Invalid icon set configuration: {$reason}");
    }

    /**
     * Config file not found
     */
    public static function missingConfigFile(string $configPath): static
    {
        return new static(
            "Icon set configuration file not found: '{$configPath}'. " .
            'Each icon set must have a config.json file in its directory.',
        );
    }

    /**
     * Invalid config.json syntax or structure
     */
    public static function invalidConfig(string $reason, string $configPath): static
    {
        return new static(
            "Invalid icon set configuration at '{$configPath}': {$reason}",
        );
    }

    /**
     * Icon set configuration validation failed
     */
    public static function configurationValidationFailed(string $providerClass, array $errors): static
    {
        $errorList = implode("\n  - ", $errors);

        return new static(
            "Ichava package configuration failed for '{$providerClass}'.\n\n" .
            "Missing or invalid configuration:\n  - {$errorList}\n\n" .
            'Fix these issues in your service provider to register this package.',
        );
    }

    /**
     * Icon set configuration build failed
     */
    public static function configurationBuildFailed(string $iconSetClass, string $message): static
    {
        return new static(
            "Icon set configuration build failed for '{$iconSetClass}'.\n\n" .
            "Error: {$message}\n\n" .
            "Check your icon set's buildConfig() method implementation.",
        );
    }

    /**
     * Missing required method implementation
     */
    public static function missingMethodImplementation(string $providerClass, string $methodName): static
    {
        return new static(
            "Ichava package configuration failed for '{$providerClass}'.\n\n" .
            "Required abstract method not implemented: {$methodName}()\n\n" .
            'Implement this method in your service provider.',
        );
    }

    /**
     * Invalid icon set class - doesn't implement required interface
     *
     * @param string $class The class name that failed validation
     * @param string $expectedInterface The required interface (default: IconSetInterface)
     */
    public static function invalidIconSetClass(string $class, string $expectedInterface = 'IconSetInterface'): static
    {
        return new static(
            "Invalid icon set class '{$class}'.\n\n" .
            "The class does not implement {$expectedInterface}.\n\n" .
            "Ensure your icon set class implements {$expectedInterface} and provides all required methods (config(), get(), has(), all()).",
        );
    }

    /**
     * Icon not found
     */
    public static function iconNotFound(string $name, ?string $package = null): static
    {
        $message = "Icon '{$name}' not found";

        if ($package) {
            $message .= " in package '{$package}'";
        }

        return new static($message . '.');
    }

    /**
     * Icon not found in specific set
     */
    public static function iconNotFoundInSet(string $name, string $set): static
    {
        return new static("Icon '{$name}' not found in set '{$set}'.");
    }

    /**
     * Invalid icon path format
     */
    public static function invalidIconPath(string $path, string $expectedFormat = 'vendor/package::variant/category/icon'): static
    {
        return new static(
            "Invalid icon path format: '{$path}'. " .
            "Expected format: '{$expectedFormat}'. Example: 'ichava/tabler-icons::home'",
        );
    }

    /**
     * Icon path missing package identifier
     */
    public static function missingPackageInPath(string $path): static
    {
        return new static(
            "Icon path must include vendor/package: '{$path}'. " .
            "Expected format: vendor/package::icon (e.g., 'ichava/tabler-icons::home')",
        );
    }

    /**
     * Path traversal attempt detected
     */
    public static function pathTraversalAttempt(string $path): static
    {
        return new static(
            "Icon path contains path traversal attempt: '{$path}'. " .
            'Path traversal (../) is not allowed for security reasons.',
        );
    }

    /**
     * Path exceeds maximum length
     */
    public static function pathTooLong(string $path, int $maxLength = 255): static
    {
        $length = strlen($path);

        return new static(
            "Icon path exceeds maximum length of {$maxLength} characters: {$length} characters. " .
            'Please use a shorter path.',
        );
    }

    /**
     * Path has too many nested levels
     */
    public static function pathTooDeep(string $path, int $maxDepth = 10): static
    {
        return new static(
            "Icon path has too many nested levels (max {$maxDepth}): '{$path}'. " .
            'Please reduce path nesting depth.',
        );
    }

    /**
     * Invalid vendor or package identifier
     */
    public static function invalidIdentifier(string $path): static
    {
        return new static(
            "Icon path contains invalid vendor or package identifier: '{$path}'. " .
            'Only alphanumeric characters, dashes, and underscores are allowed.',
        );
    }

    /**
     * Invalid icon name
     */
    public static function invalidIconName(string $name): static
    {
        return new static(
            "Invalid icon name: '{$name}'. " .
            'Only alphanumeric characters, dashes, underscores, and dots are allowed. ' .
            'If extension is present, it must be .svg',
        );
    }

    /**
     * Invalid path segment
     */
    public static function invalidPathSegment(string $segment): static
    {
        return new static(
            "Invalid path segment: '{$segment}'. " .
            'Only alphanumeric characters, dashes, and underscores are allowed.',
        );
    }

    /**
     * Path not found
     */
    public static function pathNotFound(string $path): static
    {
        return new static("Required path not found: '{$path}'");
    }

    /**
     * Directory not found
     */
    public static function directoryNotFound(string $path): static
    {
        return new static("Directory not found: '{$path}'");
    }

    /**
     * Filesystem operation failed
     */
    public static function filesystemFailure(string $operation, string $path): static
    {
        return new static("Filesystem {$operation} failed for: '{$path}'");
    }

    /**
     * File operation failed with reason
     */
    public static function fileOperationFailed(string $operation, string $path, string $reason): static
    {
        return new static("File {$operation} failed for '{$path}': {$reason}");
    }

    /**
     * Invalid SVG content
     */
    public static function invalidSvg(string $reason): static
    {
        return new static("Invalid SVG content: {$reason}");
    }

    /**
     * Icon rendering failed
     */
    public static function renderFailed(string $iconName, string $reason): static
    {
        return new static("Failed to render icon '{$iconName}': {$reason}");
    }

    /**
     * Package not registered
     */
    public static function packageNotRegistered(string $packageKey): static
    {
        return new static(
            "Package '{$packageKey}' is not registered. " .
            'Register it first using IconRegistry::register().',
        );
    }

    /**
     * Package registration missing metadata
     */
    public static function missingPackageMetadata(string $packageName, array $missingFields): static
    {
        $fieldList = implode("\n  - ", $missingFields);

        return new static(
            "Package registration failed for '{$packageName}'.\n\n" .
            "Missing required metadata:\n  - {$fieldList}\n\n" .
            "These fields are mandatory. Fix them in your service provider's registerWithIconRegistry() method.",
        );
    }

    /**
     * Package icon set class not found
     */
    public static function packageClassNotFound(string $packageName, string $className): static
    {
        return new static(
            "Package registration failed for '{$packageName}'.\n\n" .
            "Icon set class does not exist: {$className}\n\n" .
            'Ensure the class is properly autoloaded and the namespace is correct.',
        );
    }

    /**
     * Package icon path not found
     */
    public static function packagePathNotFound(string $packageName, string $path): static
    {
        return new static(
            "Package registration failed for '{$packageName}'.\n\n" .
            "Icon base path does not exist: {$path}\n\n" .
            'Ensure the path is correct and the directory exists.',
        );
    }

    /**
     * Icon set name conflict
     */
    public static function iconSetNameConflict(
        string $iconSetName,
        string $existingPackage,
        string $newPackage,
        string $providerClass,
    ): static {
        return new static(
            "Icon set name conflict: '{$iconSetName}' is already registered by '{$existingPackage}'.\n\n" .
            "Package '{$newPackage}' cannot reuse that name.\n\n" .
            "To resolve: change the icon set name in {$providerClass}::getIconSetName(), or remove one of the conflicting packages.",
        );
    }

    /**
     * Prefix conflict warning
     */
    public static function prefixConflict(string $prefix, array $packages): static
    {
        $packageList = implode(', ', $packages);

        return new static(
            "Icon prefix conflict: '{$prefix}' is used by multiple packages: {$packageList}.\n\n" .
            'Consider using unique prefixes for each package.',
        );
    }

    /**
     * Blade component conflict warning
     */
    public static function bladeComponentConflict(string $componentName, array $packages): static
    {
        $packageList = implode(', ', $packages);

        return new static(
            "Blade component conflict: '{$componentName}' is used by multiple packages: {$packageList}.\n\n" .
            'Only one component will be registered. Consider using unique component names.',
        );
    }

    /**
     * Package prefix conflict
     */
    public static function packagePrefixConflict(string $prefix, array $packages): static
    {
        $packageList = implode("\n  - ", $packages);

        return new static(
            "Package prefix conflict: '{$prefix}' is already used by: {$packageList}.\n\n" .
            'Each package must have a unique prefix.',
        );
    }

    /**
     * Package blade component conflict
     */
    public static function packageBladeComponentConflict(string $componentName, array $packages): static
    {
        $packageList = implode("\n  - ", $packages);

        return new static(
            "Blade component conflict: '{$componentName}' is already registered by: {$packageList}.\n\n" .
            'Each package must have a unique blade component name.',
        );
    }

    /**
     * Configuration missing method
     */
    public static function configurationMissingMethod(string $providerClass, string $methodName): static
    {
        return new static(
            "Configuration method missing in '{$providerClass}'.\n\n" .
            "Required method '{$methodName}()' is not defined.\n\n" .
            'Ensure your provider implements all required methods.',
        );
    }

    /**
     * Cache operation failed
     */
    public static function cacheFailure(string $operation, string $reason): static
    {
        return new static("Cache {$operation} failed: {$reason}");
    }

    /**
     * Seeding operation failed
     */
    public static function seedingFailed(string $package, string $reason): static
    {
        return new static("Icon seeding failed for package '{$package}': {$reason}");
    }

    /**
     * Seeding not allowed outside console
     */
    public static function seedingRequiresConsole(): static
    {
        return new static('Icon seeding can only be run from the console.');
    }

    /**
     * Required dependency not injected
     */
    public static function dependencyNotInjected(string $dependency, string $class): static
    {
        return new static(
            "Dependency not injected in '{$class}'.\n\n" .
            "Missing: {$dependency}\n\n" .
            "Ensure {$dependency} is registered as a singleton and all constructor dependencies are wired through the service container.",
        );
    }

    /**
     * Service unavailable or failed
     */
    public static function serviceFailure(string $service, string $operation, string $reason): static
    {
        return new static("{$service} service {$operation} failed: {$reason}");
    }

    /**
     * Discovery operation failed
     */
    public static function discoveryFailed(string $operation, string $reason): static
    {
        return new static("Icon discovery {$operation} failed: {$reason}");
    }

    /**
     * Browser operation failed
     */
    public static function browserOperationFailed(string $operation, string $reason): static
    {
        return new static("Icon browser {$operation} failed: {$reason}");
    }

    /**
     * HTTP request failed
     */
    public static function httpRequestFailed(string $url, string $reason): static
    {
        return new static("HTTP request to '{$url}' failed: {$reason}");
    }

    /**
     * Invalid data received
     */
    public static function invalidData(string $dataType, string $reason): static
    {
        return new static("Invalid {$dataType} data: {$reason}");
    }

    /**
     * Generic operation failed (use specific methods when possible)
     */
    public static function operationFailed(string $operation, string $reason): static
    {
        return new static("{$operation} failed: {$reason}");
    }

    /**
     * Class not found
     */
    public static function classNotFound(string $className): static
    {
        return new static(
            "Class not found: '{$className}'.\n\n" .
            'Ensure the class exists and is properly autoloaded.',
        );
    }

    /**
     * Path not readable
     */
    public static function pathNotReadable(string $path, string $context = ''): static
    {
        $message = "Path not readable: '{$path}'.\n\nThe path exists but cannot be read. Check file permissions.";

        if ($context) {
            $message .= "\nContext: {$context}\n";
        }

        return new static($message);
    }

    /**
     * Security violation, symlink or directory escape detected
     */
    public static function securityViolation(string $reason): static
    {
        return new static("Security violation: {$reason}");
    }

    /**
     * Invalid path type
     */
    public static function invalidPathType(string $path, string $expectedType, string $actualType): static
    {
        return new static(
            "Invalid path type for '{$path}': expected {$expectedType}, got {$actualType}.\n\n" .
            'Ensure the path points to the correct resource type.',
        );
    }
}
