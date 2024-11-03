<?php declare(strict_types=1);

namespace DanBettles\Squirrel;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use SplFileInfo;

use function file_get_contents;
use function file_put_contents;
use function md5;
use function serialize;
use function time;
use function unserialize;

use const false;

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
    private string $byteStreamOfFalse;

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
     * @throws InvalidArgumentException If the TTL is invalid
     */
    public function __construct(
        private SplFileInfo $cacheDirInfo,
        private int $ttl,
    ) {
        if (!$cacheDirInfo->isDir()) {
            throw new InvalidArgumentException("The (cache) directory, `{$cacheDirInfo}`, does not exist");
        }

        if ($ttl < 0) {
            throw new InvalidArgumentException("The TTL, `{$ttl}`, is invalid");
        }

        $this->byteStreamOfFalse = serialize(false);
    }

    private function createItemFileInfo(string $key): SplFileInfo
    {
        $itemFilePathname = "{$this->cacheDirInfo->getPathname()}/" . md5($key) . '.bs';

        return new SplFileInfo($itemFilePathname);
    }

    /**
     * @internal
     */
    protected function hasItem(string $key): bool
    {
        $itemFileInfo = $this->createItemFileInfo($key);

        return $itemFileInfo->isFile()
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
            $this->createItemFileInfo($key)->getPathname(),
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
        $itemFilePathname = $this->createItemFileInfo($key)->getPathname();
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
     * Depending on the time of year (TTL), this squirrel will either return the cached item or save it
     *
     * @throws RuntimeException If it failed to save the item
     */
    public function squirrel(
        string $key,
        Closure $factory,
    ): mixed {
        if (!$this->hasItem($key)) {
            if (!$this->save($key, $factory())) {
                throw new RuntimeException("Failed to save item `{$key}`");
            }
        }

        return $this->getItem($key);
    }
}
