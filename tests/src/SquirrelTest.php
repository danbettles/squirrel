<?php declare(strict_types=1);

namespace DanBettles\Squirrel\Tests;

use DanBettles\Squirrel\Squirrel;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SplFileInfo;

use function passthru;
use function preg_replace;
use function sleep;
use function time;

use const false;
use const true;

/**
 * @phpstan-import-type ConstructorArgsArray from Squirrel
 * @phpstan-import-type FactoryArgsArray from Squirrel
 *
 * @phpstan-type TestItemObject object{expiresAt: int}
 */
class SquirrelTest extends TestCase
{
    /** For convenience */
    private static SplFileInfo $nonexistentDirInfo;

    private static function createFixturesDir(string $subdir = ''): string
    {
        /** @var string */
        $classFilePathname = (new ReflectionClass(static::class))->getFileName();
        $fileInfo = new SplFileInfo($classFilePathname);
        $fileExtension = $fileInfo->getExtension();
        $baseFixturesDir = preg_replace("~\.{$fileExtension}$~", '', $fileInfo->getPathname());

        return $baseFixturesDir . ('' === $subdir ? $subdir : "/{$subdir}");
    }

    private static function getNonexistentDirInfo(): SplFileInfo
    {
        if (!isset(self::$nonexistentDirInfo)) {
            self::$nonexistentDirInfo = new SplFileInfo(self::createFixturesDir('doesNotExist'));
        }

        return self::$nonexistentDirInfo;
    }

    protected function setUp(): void
    {
        $fixturesDir = self::createFixturesDir();
        $command = "rm -f --verbose {$fixturesDir}/*/*.*";
        passthru($command);
    }

    /** @return array<mixed[]> */
    public static function providesValidConstructorArgs(): array
    {
        $fixturesDirInfo = new SplFileInfo(self::createFixturesDir('testIsInstantiable'));

        return [
            [
                [$fixturesDirInfo, 0],
            ],
            [
                [$fixturesDirInfo, 60],
            ],
        ];
    }

    /**
     * @phpstan-param ConstructorArgsArray $validConstructorArgs
     */
    #[DataProvider('providesValidConstructorArgs')]
    public function testIsInstantiable(array $validConstructorArgs): void
    {
        $this->expectNotToPerformAssertions();

        new Squirrel(...$validConstructorArgs);
    }

    /** @return array<mixed[]> */
    public static function providesNonexistentCacheDirObjects(): array
    {
        return [
            [
                self::getNonexistentDirInfo(),
            ],
        ];
    }

    #[DataProvider('providesNonexistentCacheDirObjects')]
    public function testThrowsAnExceptionIfTheCacheDirDoesNotExist(SplFileInfo $cacheDirInfo): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The (cache) directory, `{$cacheDirInfo}`, does not exist");

        new Squirrel($cacheDirInfo, 0);
    }

    /** @return array<mixed[]> */
    public static function providesInvalidTtls(): array
    {
        return [
            [
                -1,
            ],
        ];
    }

    #[DataProvider('providesInvalidTtls')]
    public function testThrowsAnExceptionIfTheTtlIsInvalid(int $invalidTtl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The TTL, `{$invalidTtl}`, is invalid");

        $existentCacheDir = new SplFileInfo(self::createFixturesDir(__FUNCTION__));

        new Squirrel($existentCacheDir, $invalidTtl);
    }

    /** @return array<mixed[]> */
    public static function providesValidFactoryArgs(): array
    {
        $fixturesDirInfo = new SplFileInfo(self::createFixturesDir('testCreateReturnsANewInstance'));

        return [
            [
                [$fixturesDirInfo->getPathname()],
            ],
            [
                [$fixturesDirInfo],
            ],
            [
                [$fixturesDirInfo->getPathname(), 0],
            ],
            [
                [$fixturesDirInfo, 0],
            ],
            [
                [$fixturesDirInfo->getPathname(), 60],
            ],
            [
                [$fixturesDirInfo, 60],
            ],
        ];
    }

    /**
     * @phpstan-param FactoryArgsArray $factoryArgs
     */
    #[DataProvider('providesValidFactoryArgs')]
    public function testCreateReturnsANewInstance(array $factoryArgs): void
    {
        $this->assertInstanceOf(
            Squirrel::class,
            Squirrel::create(...$factoryArgs),
        );
    }

    /** @return array<mixed[]> */
    public static function providesNonexistentCacheDirs(): array
    {
        $nonexistentDirInfo = self::getNonexistentDirInfo();

        return [
            [
                $nonexistentDirInfo->getPathname(),
            ],
            [
                $nonexistentDirInfo,
            ],
        ];
    }

    #[DataProvider('providesNonexistentCacheDirs')]
    public function testCreateThrowsAnExceptionIfTheCacheDirDoesNotExist(SplFileInfo|string $cacheDir): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The (cache) directory, `{$cacheDir}`, does not exist");

        Squirrel::create($cacheDir);
    }

    #[DataProvider('providesInvalidTtls')]
    public function testCreateThrowsAnExceptionIfTheTtlIsInvalid(int $invalidTtl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The TTL, `{$invalidTtl}`, is invalid");

        $existentCacheDir = new SplFileInfo(self::createFixturesDir(__FUNCTION__));

        Squirrel::create($existentCacheDir, $invalidTtl);
    }

    public function testSquirrelCallsSaveIfTheItemDoesNotExistInThePool(): void
    {
        $mockSquirrel = $this
            ->getMockBuilder(Squirrel::class)
            ->onlyMethods([
                'hasItem',
                'save',
                'getItem',
            ])
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $mockSquirrel
            ->expects($this->once())
            ->method('hasItem')
            ->with('foo')
            ->willReturn(false)
        ;

        $mockSquirrel
            ->expects($this->once())
            ->method('save')
            ->with('foo', 'bar')
            ->willReturn(true)
        ;

        $mockSquirrel
            ->expects($this->once())
            ->method('getItem')
            ->with('foo')
            ->willReturn('bar')
        ;

        /** @var Squirrel $mockSquirrel */

        $foo = $mockSquirrel->squirrel('foo', function (): string {
            return 'bar';
        });

        $this->assertSame('bar', $foo);
    }

    public function testSquirrelNeverCallsSaveIfTheItemExistsInThePool(): void
    {
        $mockSquirrel = $this
            ->getMockBuilder(Squirrel::class)
            ->onlyMethods([
                'hasItem',
                'save',
                'getItem',
            ])
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $mockSquirrel
            ->expects($this->once())
            ->method('hasItem')
            ->with('foo')
            ->willReturn(true)
        ;

        $mockSquirrel
            ->expects($this->never())
            ->method('save')
        ;

        $mockSquirrel
            ->expects($this->once())
            ->method('getItem')
            ->with('foo')
            ->willReturn('bar')
        ;

        /** @var Squirrel $mockSquirrel */

        $foo = $mockSquirrel->squirrel('foo', function (): void {
            // Ignored
        });

        $this->assertSame('bar', $foo);
    }

    public function testSquirrelDoesWhatItShould(): void
    {
        // (Deliberately weird(-ish) key)
        $key = 'foo.bar/baz?qux#quux';

        $factory = function (): object {
            return (object) [
                'expiresAt' => time() + 2000,
            ];
        };

        $squirrel = Squirrel::create(
            self::createFixturesDir('testSquirrelDoesWhatItShould'),
            ttl: 2,
        );

        /** @phpstan-var TestItemObject */
        $originalItem = $squirrel->squirrel($key, $factory);

        $this->assertIsObject($originalItem);
        $this->assertObjectHasProperty('expiresAt', $originalItem);

        $originalExpiresAt = $originalItem->expiresAt;

        $this->assertIsInt($originalExpiresAt);

        sleep(1);

        /** @phpstan-var TestItemObject */
        $itemAfterMoreThanASecond = $squirrel->squirrel($key, $factory);

        $this->assertIsObject($itemAfterMoreThanASecond);
        $this->assertObjectHasProperty('expiresAt', $itemAfterMoreThanASecond);
        $this->assertIsInt($itemAfterMoreThanASecond->expiresAt);
        $this->assertSame($originalExpiresAt, $itemAfterMoreThanASecond->expiresAt);

        sleep(2);

        /** @phpstan-var TestItemObject */
        $itemAfterMoreThanTwoSeconds = $squirrel->squirrel($key, $factory);

        $this->assertIsObject($itemAfterMoreThanTwoSeconds);
        $this->assertObjectHasProperty('expiresAt', $itemAfterMoreThanTwoSeconds);
        $this->assertIsInt($itemAfterMoreThanTwoSeconds->expiresAt);
        $this->assertNotSame($originalExpiresAt, $itemAfterMoreThanTwoSeconds->expiresAt);
        // To be clear:
        $this->assertGreaterThan($originalExpiresAt, $itemAfterMoreThanTwoSeconds->expiresAt);
    }
}
