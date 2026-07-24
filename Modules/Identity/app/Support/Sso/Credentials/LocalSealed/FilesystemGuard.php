<?php

namespace Modules\Identity\Support\Sso\Credentials\LocalSealed;

/**
 * Thin FS wrapper so symlink / permission checks are testable without OS symlink support.
 */
class FilesystemGuard
{
    public function isLink(string $path): bool
    {
        return is_link($path);
    }

    public function isFile(string $path): bool
    {
        return is_file($path);
    }

    public function isDir(string $path): bool
    {
        return is_dir($path);
    }

    public function isReadable(string $path): bool
    {
        return is_readable($path);
    }

    public function realpath(string $path): string|false
    {
        return realpath($path);
    }

    /**
     * True when group/other bits are set (Unix). Always false on Windows ACL platforms.
     */
    public function hasOverlyBroadPermissions(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        if (! file_exists($path)) {
            return false;
        }

        $perms = fileperms($path);
        if ($perms === false) {
            return true;
        }

        return ($perms & 0077) !== 0;
    }
}
