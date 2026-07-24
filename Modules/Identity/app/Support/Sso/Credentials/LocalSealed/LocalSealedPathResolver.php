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
        private readonly FilesystemGuard $fs = new FilesystemGuard,
    ) {}

    public function assertStoreConfigured(): string
    {
        if (trim($this->storeRoot) === '') {
            throw LocalSealedException::failClosed('missing_or_invalid_store_path');
        }

        if (! $this->fs->isDir($this->storeRoot)) {
            throw LocalSealedException::failClosed('missing_or_invalid_store_path');
        }

        if ($this->fs->isLink($this->storeRoot)) {
            throw LocalSealedException::failClosed('symlink_escape');
        }

        $root = $this->fs->realpath($this->storeRoot);
        $public = $this->fs->realpath($this->publicRoot);

        if ($root === false) {
            throw LocalSealedException::failClosed('missing_or_invalid_store_path');
        }
        if ($public === false) {
            throw LocalSealedException::failClosed('invalid_public_root');
        }

        if ($root === $public || str_starts_with($root, rtrim($public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw LocalSealedException::failClosed('store_inside_web_root');
        }

        if ($this->fs->hasOverlyBroadPermissions($root)) {
            throw LocalSealedException::failClosed('store_permissions_too_open');
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
        if (! $this->fs->isDir($parent) && ! mkdir($parent, 0700, true) && ! $this->fs->isDir($parent)) {
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

        // Walk every existing component from candidate up to (and including) store root.
        $this->assertComponentChainNotLinked($candidate, $root);

        $parent = dirname($candidate);
        if ($this->fs->isLink($parent)) {
            throw LocalSealedException::failClosed('symlink_escape');
        }

        $parentReal = $this->fs->realpath($parent);
        if ($parentReal === false || ! $this->isUnder($parentReal, $root)) {
            throw LocalSealedException::failClosed('symlink_escape');
        }

        if ($this->fs->isLink($candidate)) {
            throw LocalSealedException::failClosed('symlink_escape');
        }

        if ($this->fs->isFile($candidate) || $this->fs->isDir($candidate)) {
            $real = $this->fs->realpath($candidate);
            if ($real === false || ! $this->isUnder($real, $root)) {
                throw LocalSealedException::failClosed('symlink_escape');
            }
        }
    }

    /**
     * Temp write destinations must also stay under the sealed store root.
     */
    public function assertTempPathAllowed(string $tmpPath, string $root): void
    {
        if ($this->fs->isLink($tmpPath)) {
            throw LocalSealedException::failClosed('symlink_escape');
        }

        $this->assertComponentChainNotLinked($tmpPath, $root);

        $parent = dirname($tmpPath);
        $parentReal = $this->fs->realpath($parent);
        if ($parentReal === false || ! $this->isUnder($parentReal, $root)) {
            throw LocalSealedException::failClosed('symlink_escape');
        }
    }

    private function assertComponentChainNotLinked(string $path, string $root): void
    {
        $normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR);
        $current = $path;

        for ($i = 0; $i < 128; $i++) {
            if ($this->fs->isLink($current)) {
                throw LocalSealedException::failClosed('symlink_escape');
            }

            $normalized = rtrim($current, DIRECTORY_SEPARATOR);
            if ($normalized === $normalizedRoot) {
                break;
            }

            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
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
