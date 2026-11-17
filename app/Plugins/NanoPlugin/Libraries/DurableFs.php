<?php

declare(strict_types=1);

namespace App\Plugins\NanoPlugin\Libraries;

/**
 * Small helpers that make files and directories the Nano plugin creates
 * durable: a write is only reported once its bytes AND the directory entries
 * that name it have reached the disk, so a power loss cannot drop a claim or a
 * suspended quote that was already reported as saved.
 */
final class DurableFs
{
    /**
     * Create $dir (and missing parents) and fsync each parent whose entry was
     * added, so the new directories survive a crash.
     */
    public static function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        $missing = [];
        for ($d = $dir; !is_dir($d) && dirname($d) !== $d; $d = dirname($d)) {
            $missing[] = $d;
        }

        if (!mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new NanoException('The Nano data directory could not be created.');
        }

        foreach ($missing as $created) {
            self::syncDirectory(dirname($created));
        }
    }

    /**
     * fsync a directory so entries created or renamed in it are on disk.
     * Platforms that cannot open a directory as a stream (Windows) skip this;
     * where it can be opened, a failed fsync is an error.
     */
    public static function syncDirectory(string $dir): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        $handle = @fopen($dir, 'rb');
        if ($handle === false) {
            throw new NanoException('The Nano data directory could not be opened to sync it.');
        }

        try {
            if (!fsync($handle)) {
                throw new NanoException('The Nano data directory could not be synced to disk.');
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Write $contents to $path atomically and durably: a temp file in the same
     * directory is written, fsynced, renamed over $path, and the directory is
     * fsynced. Throws on any failure and leaves no partial file behind.
     */
    public static function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        self::ensureDirectory($dir);

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        $handle = fopen($tmp, 'xb');
        if ($handle === false) {
            throw new NanoException('The Nano data file could not be created.');
        }

        try {
            $length = strlen($contents);
            $written = 0;
            while ($written < $length) {
                $bytes = fwrite($handle, substr($contents, $written));
                if ($bytes === false || $bytes === 0) {
                    throw new NanoException('The Nano data file could not be written.');
                }
                $written += $bytes;
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new NanoException('The Nano data file could not be saved to disk.');
            }
        } catch (NanoException $e) {
            fclose($handle);
            @unlink($tmp);
            throw $e;
        }

        fclose($handle);

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new NanoException('The Nano data file could not be moved into place.');
        }

        self::syncDirectory($dir);
    }
}
