<?php

declare(strict_types=1);

namespace App\Plugins\NanoPlugin\Libraries;

/**
 * The durable claim ledger for Nano payments: one send block credits one sale,
 * once, and the record survives a restart.
 *
 * A confirmed Nano send stays valid forever and its hash is public, so replay
 * protection cannot live in memory: a process restart that forgot its claims
 * would let every old block be presented again. Each claim is appended and
 * fsynced to a file under a directory the operator configures, and every claim
 * re-reads that file, so the "block hash -> sale" record outlives the PHP
 * worker. The read, the check and the append happen under ONE exclusive lock,
 * so two workers presenting the same block at the same moment cannot both win.
 * Pure: no dependency and no network, so it unit-tests on its own.
 *
 * File format: one `HASH\tORDER\n` line per claim; the hash is upper-case so a
 * re-read in another case is the same block.
 */
final class ClaimsFile
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Record that $blockHash settles $orderId.
     *
     * @return bool true when this is the first time the block is credited;
     *              false when the same block was already recorded for the same
     *              order (idempotent - a retried add is not a new claim)
     *
     * @throws NanoException when the block was already spent on another order,
     *                       or the order was already paid by another block -
     *                       neither is ever credited
     */
    public function claim(string $blockHash, string $orderId): bool
    {
        $hash = strtoupper(trim($blockHash));
        if (preg_match('/^[0-9A-F]{64}$/', $hash) !== 1) {
            throw new NanoException('A Nano block hash is 64 hex characters.');
        }
        if ($orderId === '' || preg_match('/[\x00-\x1F\x7F]/', $orderId) === 1) {
            // A tab or a newline in an order id could forge a second claim line.
            throw new NanoException('A Nano order id must be non-empty and free of control characters.');
        }

        $handle = $this->open();

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new NanoException('The Nano claims file is locked.');
            }

            [$byHash, $byOrder, $complete, $tailIsPartial] = $this->read($handle);

            if (isset($byHash[$hash])) {
                if ($byHash[$hash] === $orderId) {
                    return false;
                }

                throw new NanoException('This Nano block was already used to pay another sale.');
            }

            if (isset($byOrder[$orderId]) && $byOrder[$orderId] !== $hash) {
                throw new NanoException('This sale was already paid by another Nano block.');
            }

            // A tail with no newline is an append that was cut short (a crash
            // or a failed write). It never returned success, so no sale was
            // credited by it; cut it off so the new line cannot be glued onto
            // it and form a different record.
            $stat = fstat($handle);
            if ($stat === false) {
                throw new NanoException('The Nano claims file could not be read.');
            }
            if ($stat['size'] > $complete && !$tailIsPartial) {
                // The read stopped before the end of the file without finding
                // an unterminated last line: never cut claims we did not read.
                throw new NanoException('The Nano claims file could not be read completely.');
            }
            if ($stat['size'] > $complete && !ftruncate($handle, $complete)) {
                throw new NanoException('The Nano claims file has an incomplete last line that could not be repaired.');
            }

            $this->append($handle, $complete, $hash . "\t" . $orderId . "\n");

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function isClaimed(string $blockHash): bool
    {
        if (!is_file($this->path)) {
            return false;
        }

        $handle = $this->open();

        try {
            flock($handle, LOCK_SH);
            [$byHash] = $this->read($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return isset($byHash[strtoupper(trim($blockHash))]);
    }

    /**
     * Append one claim line at $offset and make it durable: every byte written,
     * flushed, and fsynced before the claim is reported, so a sale is never
     * released on a record that is not on disk. Any failure throws, and the
     * partial bytes are cut off again where possible.
     *
     * @param resource $handle
     */
    private function append($handle, int $offset, string $line): void
    {
        if (fseek($handle, $offset) !== 0) {
            throw new NanoException('The Nano claims file could not be written.');
        }

        $length = strlen($line);
        $written = 0;
        while ($written < $length) {
            $bytes = fwrite($handle, substr($line, $written));
            if ($bytes === false || $bytes === 0) {
                ftruncate($handle, $offset);
                throw new NanoException('The Nano claim could not be written to the claims file.');
            }
            $written += $bytes;
        }

        if (!fflush($handle) || !fsync($handle)) {
            ftruncate($handle, $offset);
            throw new NanoException('The Nano claim could not be saved to disk.');
        }
    }

    /**
     * @return resource
     */
    private function open()
    {
        $dir = dirname($this->path);
        DurableFs::ensureDirectory($dir);

        $isNew = !is_file($this->path);

        // c+: create when missing, never truncate, read and write.
        $handle = fopen($this->path, 'c+b');
        if ($handle === false) {
            throw new NanoException('The Nano claims file could not be opened.');
        }

        if ($isNew) {
            // The new directory entry for the ledger must reach the disk too,
            // or a crash could lose the file with its first claim in it.
            DurableFs::syncDirectory($dir);
        }

        return $handle;
    }

    /**
     * @param resource $handle
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: int, 3: bool}
     *         claims by hash, claims by order, and the byte length of the
     *         newline-terminated part of the file (an unterminated tail is an
     *         interrupted append and is never read as a claim), and whether
     *         such an unterminated tail ends the file
     */
    private function read($handle): array
    {
        $byHash = [];
        $byOrder = [];
        $complete = 0;

        $tailIsPartial = false;

        rewind($handle);
        while (($line = fgets($handle)) !== false) {
            if (!str_ends_with($line, "\n")) {
                // Only an unterminated line that ends the file is an interrupted
                // append; anything else is a read error.
                $tailIsPartial = feof($handle);
                if (!$tailIsPartial) {
                    throw new NanoException('The Nano claims file could not be read completely.');
                }
                break;
            }
            $complete += strlen($line);
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$hash, $order] = $parts;
            $byHash[$hash] = $order;
            $byOrder[$order] = $hash;
        }

        if (!$tailIsPartial && !feof($handle)) {
            // fgets() returned false before the end of the file: a read error,
            // not the end of the ledger. Refuse rather than trust a short read.
            throw new NanoException('The Nano claims file could not be read completely.');
        }

        return [$byHash, $byOrder, $complete, $tailIsPartial];
    }
}
