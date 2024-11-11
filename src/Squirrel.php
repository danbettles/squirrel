<?php declare(strict_types=1);

namespace DanBettles\Squirrel;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use SplFileInfo;

use function array_key_exists;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function md5;
use function serialize;
use function time;
use function uniqid;
use function unlink;
use function unserialize;

use const false;
use const null;
use const true;

/**
 * "Squirrel" (noun): squirrels climb trees and feed on nuts and seeds.  They also love to do caching.
 *
 * @phpstan-type ConstructorArgsArray array{
 *   0: SplFileInfo,
 *   1: int,
 * }
 *
 * @phpstan-type FactoryArgsArray array{
 *   0: SplFileInfo|string,
 *   1: int,
 * }
 *
 * @final
 */
class Squirrel
{
    final public const int TTL_SESSION_LIFETIME = -1;

    /**
     * @var int[]
     */
    private const array SPECIAL_TTLS = [
        self::TTL_SESSION_LIFETIME,
    ];

    private string $byteStreamOfFalse;

    private string|null $sessionId;

    /**
     * Cache-files created by the instance
     *
     * @var array<string,SplFileInfo>
     */
    private array $cacheFiles;

    public static function create(
        SplFileInfo|string $cacheDir,
        int $ttl = 0,
    ): self {
        if (!($cacheDir instanceof SplFileInfo)) {
            $cacheDir = new SplFileInfo($cacheDir);
        }

        return new self($cacheDir, $ttl);
    }

    /**
     * @throws InvalidArgumentException If the directory does not exist
     */
    public function __construct(
        private SplFileInfo $cacheDirInfo,
        private int $ttl,
    ) {
        if (!$this->cacheDirInfo->isDir()) {
            throw new InvalidArgumentException("The (cache) directory, `{$this->cacheDirInfo}`, does not exist");
        }

        $this->assertTtlIsValid($this->ttl);

        $this->byteStreamOfFalse = serialize(false);
        $this->sessionId = self::TTL_SESSION_LIFETIME === $this->ttl ? uniqid('', true) : null;
        $this->cacheFiles = [];
    }

    public function __destruct()
    {
        if ($this->hasSession()) {
            foreach ($this->cacheFiles as $key => $cacheFileInfo) {
                @unlink($cacheFileInfo->getPathname());
                unset($this->cacheFiles[$key]);
            }
        }
    }

    private function hasSession(): bool
    {
        return null !== $this->sessionId;
    }

    /**
     * @throws InvalidArgumentException If the TTL is invalid
     */
    private function assertTtlIsValid(int $ttl): void
    {
        $ttlIsValid = $ttl >= 0 || in_array($ttl, self::SPECIAL_TTLS, true);

        if (!$ttlIsValid) {
            throw new InvalidArgumentException("The TTL, `{$ttl}`, is invalid");
        }
    }

    private function getItemFileInfo(string $key): SplFileInfo
    {
        if ($this->hasSession()) {
            // Make the key unique to the session: our files will not be for sharing and we don't want to nuke files
            // belonging to other sessions, either
            $key .= $this->sessionId;
        }

        if (!array_key_exists($key, $this->cacheFiles)) {
            $this->cacheFiles[$key] = new SplFileInfo("{$this->cacheDirInfo}/" . md5($key) . '.bs');
        }

        return $this->cacheFiles[$key];
    }

    /**
     * @internal
     */
    protected function hasItem(string $key): bool
    {
        $itemFileInfo = $this->getItemFileInfo($key);

        $itemFileExists = $itemFileInfo->isFile();

        if ($this->hasSession()) {
            // (No need to check the m-time: if the item-file exists, we can use it for as long as the 'session' is alive)
            return $itemFileExists;
        }

        return $itemFileExists
            && ($itemFileInfo->getMTime() + $this->ttl >= time())
        ;
    }

    /**
     * @internal
     */
    protected function save(
        string $key,
        mixed $item,
    ): bool {
        return (bool) file_put_contents(
            $this->getItemFileInfo($key)->getPathname(),
            serialize($item),
        );
    }

    /**
     * @internal
     * @throws RuntimeException If it failed to get the item
     * @throws RuntimeException If it failed to unserialize the item
     */
    protected function getItem(string $key): mixed
    {
        $itemFilePathname = $this->getItemFileInfo($key)->getPathname();
        $byteStream = file_get_contents($itemFilePathname);

        if (false === $byteStream) {
            throw new RuntimeException("Failed to get item `{$key}`");
        }

        $unserialized = unserialize($byteStream);

        if (false === $unserialized && $this->byteStreamOfFalse !== $byteStream) {
            throw new RuntimeException("Failed to unserialize item `{$key}`");
        }

        return $unserialized;
    }

    /**
     * "Squirrel" (verb): to store up for future use
     *
     * Depending on the time of year (TTL), this type of squirrel will either return the cached item or save it
     *
     * @throws RuntimeException If it failed to save the item
     */
    public function squirrel(
        string $key,
        Closure $factory,
    ): mixed {
        // If caching is disabled we should waste as little time as possible
        if (0 === $this->ttl) {
            return $factory();
        }

        if (!$this->hasItem($key)) {
            if (!$this->save($key, $factory())) {
                throw new RuntimeException("Failed to save item `{$key}`");
            }
        }

        return $this->getItem($key);
    }
}
