<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;

require_once __DIR__ . '/LocalSpeechToTextJournalPrivilegeException.php';

/**
 * Resolves owner/guest journal access for the unified module log.
 *
 * The journal tree has a single canonical owner (the service account that owns
 * the journal directory). Owner processes mutate permissions freely; a root
 * guest never performs path-based mutations inside the foreign-owned directory.
 * File creation happens exclusively through a setegid+seteuid window that acts
 * as the directory owner, and root-side opens never use creating modes, so a
 * root-owned file cannot appear even after a lost race.
 */
final class LocalSpeechToTextJournalAccess
{
    private const int ROOT_UID = 0;
    private const int ROOT_GID = 0;
    private const int CREATE_MODE = 027;

    private readonly string $directory;
    private readonly Closure $effectiveUidProvider;
    private readonly Closure $ownerFileCreator;

    /** @var array{realDirectory: string, mode: 'owner'|'guest', canonicalUid: int|null, canonicalGid: int|null}|null */
    private ?array $access = null;

    public function __construct(
        string $directory,
        ?callable $effectiveUidProvider = null,
        ?callable $ownerFileCreator = null
    ) {
        $this->directory = $directory;
        $this->effectiveUidProvider = Closure::fromCallable(
            $effectiveUidProvider ?? static fn (): ?int => function_exists('posix_geteuid') ? posix_geteuid() : null
        );
        $this->ownerFileCreator = Closure::fromCallable(
            $ownerFileCreator ?? $this->createFileAsDirectoryOwner(...)
        );
    }

    /** @return array{realDirectory: string, mode: 'owner'|'guest', canonicalUid: int|null, canonicalGid: int|null}|null */
    public function resolveDirectoryAccess(): ?array
    {
        $directory = rtrim($this->directory, '/');
        if ($directory === '' || is_link($directory)) {
            return null;
        }
        $effectiveUid = ($this->effectiveUidProvider)();

        if (!is_dir($directory) && !$this->createJournalDirectory($effectiveUid) && !is_dir($directory)) {
            return null;
        }
        if (is_link($directory)) {
            return null;
        }

        $realDirectory = realpath($directory);
        if (!is_string($realDirectory) || !is_dir($realDirectory)) {
            return null;
        }

        clearstatcache(true, $realDirectory);
        $stat = @lstat($realDirectory);
        if (!is_array($stat)
            || (($stat['mode'] & 0170000) !== 0040000)
            || (($stat['mode'] & 0022) !== 0)
        ) {
            return null;
        }

        $mode = 'owner';
        $canonicalUid = null;
        if (is_int($effectiveUid)) {
            if ((int)$stat['uid'] === $effectiveUid) {
                $canonicalUid = (int)$stat['uid'];
            } elseif (
                $effectiveUid === self::ROOT_UID
                && is_int($ownerUid = $this->canonicalParentOwnerUid($realDirectory))
                && $ownerUid !== self::ROOT_UID
                && (int)$stat['uid'] === $ownerUid
                && $this->actorMayActAsGuest($ownerUid)
            ) {
                $mode = 'guest';
                $canonicalUid = $ownerUid;
            } else {
                return null;
            }
        }

        if ($mode === 'owner' && !chmod($realDirectory, 0750)) {
            return null;
        }

        return $this->access = [
            'realDirectory' => rtrim($realDirectory, '/'),
            'mode' => $mode,
            'canonicalUid' => $canonicalUid,
            'canonicalGid' => (int)$stat['gid'],
        ];
    }

    public function isGuestMode(): bool
    {
        return $this->access !== null && $this->access['mode'] === 'guest';
    }

    public function canonicalUid(): ?int
    {
        return $this->access['canonicalUid'] ?? null;
    }

    public function canonicalGid(): ?int
    {
        return $this->access['canonicalGid'] ?? null;
    }

    public function clear(): void
    {
        $this->access = null;
    }

    /**
     * Creates a missing journal file exclusively through the owner identity.
     * The requested mode is intentionally ignored: creation is always exclusive
     * so a lost race can never append through a substituted entry.
     *
     * @return resource|false
     */
    public function createFileAsOwner(string $path, string $mode): mixed
    {
        $canonicalUid = $this->access['canonicalUid'] ?? null;
        $canonicalGid = $this->access['canonicalGid'] ?? null;
        if (!is_int($canonicalUid) || !is_int($canonicalGid)) {
            return false;
        }

        return ($this->ownerFileCreator)($path, $canonicalUid, $canonicalGid, 'x+b');
    }

    public function applyOwnerFileMode(string $path): bool
    {
        if ($this->isGuestMode()) {
            return true;
        }

        return chmod($path, 0640);
    }

    /**
     * Runs a path mutation (unlink, rename, ...) through the owner identity.
     * Guest callers must use this for every mutation inside the journal tree:
     * a substituted path then makes the directory owner — never root — act.
     * Returns null when the owner window is unavailable.
     */
    public function mutateAsOwner(callable $operation): mixed
    {
        $canonicalUid = $this->access['canonicalUid'] ?? null;
        $canonicalGid = $this->access['canonicalGid'] ?? null;
        if (!is_int($canonicalUid) || !is_int($canonicalGid)) {
            return null;
        }

        return $this->withDirectoryOwner($canonicalUid, $canonicalGid, $operation);
    }

    public function guestModeIsSafe(int $mode): bool
    {
        $permissions = $mode & 07777;

        return ($permissions & 0022) === 0 && ($permissions & 0600) === 0600;
    }

    /** @param array<string|int, mixed> $handleStat */
    public function statMatchesPath(array $handleStat, string $path): bool
    {
        clearstatcache(true, $path);
        $pathStat = @lstat($path);

        return is_array($pathStat)
            && (($pathStat['mode'] & 0170000) === 0100000)
            && (($handleStat['mode'] & 0170000) === 0100000)
            && (int)$handleStat['dev'] === (int)$pathStat['dev']
            && (int)$handleStat['ino'] === (int)$pathStat['ino'];
    }

    public static function modeCreates(string $mode): bool
    {
        return $mode !== '' && strpbrk($mode, 'awcx') !== false;
    }

    /**
     * Guest mode must not be unlocked by the (injectable) effective-UID
     * provider alone: the real process identity has to be root or the
     * canonical owner for the guest contract to apply.
     */
    private function actorMayActAsGuest(int $ownerUid): bool
    {
        if (!function_exists('posix_geteuid')) {
            return true;
        }
        $realUid = posix_geteuid();

        return $realUid === self::ROOT_UID || $realUid === $ownerUid;
    }

    private function createJournalDirectory(?int $effectiveUid): bool
    {
        $owner = null;
        if (is_int($effectiveUid) && $effectiveUid === self::ROOT_UID) {
            $owner = $this->canonicalParentOwner(rtrim($this->directory, '/'));
        }
        if (is_array($owner)) {
            $created = $this->withDirectoryOwner(
                $owner['uid'],
                $owner['gid'],
                fn (): bool => @mkdir($this->directory, 0750)
            );

            return $created === true || is_dir($this->directory);
        }

        return @mkdir($this->directory, 0750, true);
    }

    /** @return array{uid: int, gid: int}|null */
    private function canonicalParentOwner(string $directory): ?array
    {
        $parent = dirname(rtrim($directory, '/'));
        if ($parent === rtrim($directory, '/')) {
            return null;
        }
        clearstatcache(true, $parent);
        $stat = @lstat($parent);
        if (!is_array($stat)
            || (($stat['mode'] & 0170000) !== 0040000)
            || (int)$stat['uid'] === self::ROOT_UID
        ) {
            return null;
        }

        return ['uid' => (int)$stat['uid'], 'gid' => (int)$stat['gid']];
    }

    private function canonicalParentOwnerUid(string $realDirectory): ?int
    {
        $owner = $this->canonicalParentOwner($realDirectory);

        return $owner === null ? null : $owner['uid'];
    }

    private function withDirectoryOwner(int $ownerUid, int $ownerGid, callable $operation): mixed
    {
        if ($ownerUid === self::ROOT_UID
            || $ownerGid === self::ROOT_GID
            || !function_exists('posix_seteuid')
            || !function_exists('posix_setegid')
        ) {
            return null;
        }
        $originalEuid = posix_geteuid();
        $originalEgid = posix_getegid();
        if (!is_int($originalEuid) || !is_int($originalEgid)) {
            return null;
        }
        $previousSignalMask = self::blockSignalsForOwnerWindow();
        $previousAsyncSignals = function_exists('pcntl_async_signals') ? pcntl_async_signals(false) : null;
        try {
            if (!posix_setegid($ownerGid) || posix_getegid() !== $ownerGid) {
                return null;
            }
            try {
                if (!posix_seteuid($ownerUid) || posix_geteuid() !== $ownerUid) {
                    return null;
                }
                try {
                    return $operation();
                } finally {
                    if (posix_geteuid() !== $originalEuid
                        && (!posix_seteuid($originalEuid) || posix_geteuid() !== $originalEuid)
                    ) {
                        throw new LocalSpeechToTextJournalPrivilegeException(
                            'Unable to restore the original effective UID after journal owner window'
                        );
                    }
                }
            } finally {
                if (posix_getegid() !== $originalEgid
                    && (!posix_setegid($originalEgid) || posix_getegid() !== $originalEgid)
                ) {
                    throw new LocalSpeechToTextJournalPrivilegeException(
                        'Unable to restore the original effective GID after journal owner window'
                    );
                }
            }
        } finally {
            self::restoreSignalMaskAfterOwnerWindow($previousSignalMask);
            if ($previousAsyncSignals === true && function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
            }
        }
    }

    /** @return array<int, int>|null */
    private static function blockSignalsForOwnerWindow(): ?array
    {
        if (!function_exists('pcntl_sigprocmask')) {
            return null;
        }
        $blocked = pcntl_sigprocmask(
            SIG_BLOCK,
            [SIGTERM, SIGINT, SIGHUP, SIGQUIT, SIGUSR1, SIGUSR2, SIGCHLD, SIGALRM, SIGWINCH, SIGPIPE]
        );

        return is_array($blocked) ? $blocked : null;
    }

    /** @param array<int, int>|null $previousMask */
    private static function restoreSignalMaskAfterOwnerWindow(?array $previousMask): void
    {
        if ($previousMask === null || !function_exists('pcntl_sigprocmask')) {
            return;
        }
        pcntl_sigprocmask(SIG_SETMASK, $previousMask);
    }

    private function createFileAsDirectoryOwner(string $path, int $ownerUid, int $ownerGid, string $mode): mixed
    {
        return $this->withDirectoryOwner(
            $ownerUid,
            $ownerGid,
            static function () use ($path, $mode): mixed {
                $previousUmask = umask(self::CREATE_MODE);
                try {
                    $handle = fopen($path, $mode);
                    if (is_resource($handle)) {
                        chmod($path, 0640);
                    }

                    return $handle;
                } finally {
                    umask($previousUmask);
                }
            }
        );
    }
}
