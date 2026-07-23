<?php

namespace Modules\Identity\Support\Sso\Credentials\LocalSealed;

use Modules\Identity\Support\Sso\Credentials\SecretReference;

/**
 * Maps opaque logical references to physical seal paths under an approved store root.
 */
final class LocalSealedPathResolver
{
    public function __construct(
        private readonly string $storeRoot,
        private readonly string $publicRoot,
    ) {}

    public function assertStoreConfigured(): string
    {
        if (trim($this->storeRoot) === '') {
            throw LocalSealedException::failClosed('missing_or_invalid_store_path');
        }

        if (! is_dir($this->storeRoot)) {
            throw LocalSealedException::failClosed('missing_or_invalid_store_path');
        }

        $root = realpath($this->storeRoot);
        $public = realpath($this->publicRoot);

        if ($root === false) {
            throw LocalSealedException::failClosed('missing_or_invalid_store_path');
        }
        if ($public === false) {
            throw LocalSealedException::failClosed('invalid_public_root');
        }

        if ($root === $public || str_starts_with($root, rtrim($public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw LocalSealedException::failClosed('store_inside_web_root');
        }

        return $root;
    }

    /**
     * Physical path for a logical reference. Callers never supply filesystem paths.
     */
    public function resolveSealPath(SecretReference $reference): string
    {
        $root = $this->assertStoreConfigured();
        $this->assertLogicalReference($reference->reference);

        $hash = hash('sha256', $reference->provider.'|'.$reference->reference);
        $relative = 'seals'.DIRECTORY_SEPARATOR.substr($hash, 0, 2).DIRECTORY_SEPARATOR.$hash.'.seal';
        $candidate = $root.DIRECTORY_SEPARATOR.$relative;

        $parent = dirname($candidate);
        if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
            throw LocalSealedException::failClosed('store_mkdir_failed');
        }

        $this->assertNoSymlinkEscape($candidate, $root);

        return $candidate;
    }

    public function lockPath(string $sealPath): string
    {
        return $sealPath.'.lock';
    }

    public function assertNoSymlinkEscape(string $candidate, ?string $root = null): void
    {
        $root ??= $this->assertStoreConfigured();

        if (is_link($candidate)) {
            throw LocalSealedException::failClosed('symlink_escape');
        }

        $parent = dirname($candidate);
        if (is_link($parent)) {
            throw LocalSealedException::failClosed('symlink_escape');
        }

        $parentReal = realpath($parent);
        if ($parentReal === false || ! $this->isUnder($parentReal, $root)) {
            throw LocalSealedException::failClosed('symlink_escape');
        }

        if (file_exists($candidate) && ! is_link($candidate)) {
            $real = realpath($candidate);
            if ($real === false || ! $this->isUnder($real, $root)) {
                throw LocalSealedException::failClosed('symlink_escape');
            }
        }
    }

    private function assertLogicalReference(string $reference): void
    {
        if ($reference === '' || str_contains($reference, "\0") || str_contains($reference, '..')) {
            throw LocalSealedException::failClosed('malformed_reference');
        }

        if (str_starts_with($reference, '/') || str_starts_with($reference, '\\')) {
            throw LocalSealedException::failClosed('absolute_reference');
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $reference) === 1) {
            throw LocalSealedException::failClosed('absolute_reference');
        }

        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $reference) !== 1) {
            throw LocalSealedException::failClosed('malformed_reference');
        }
    }

    private function isUnder(string $path, string $root): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }
}
