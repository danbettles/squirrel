<?php declare(strict_types=1);

namespace DanBettles\Squirrel\Tests;

use DanBettles\Squirrel\Squirrel;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SplFileInfo;

use function array_filter;
use function preg_replace;
use function scandir;
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
    /**
     * For convenience
     */
    private static SplFileInfo $nonexistentDirInfo;

    private static function createFixturesDirPathname(string $subdir = ''): string
    {
        /** @var string */
        $classFilePathname = (new ReflectionClass(static::class))->getFileName();
        $fileInfo = new SplFileInfo($classFilePathname);
        $fileExtension = $fileInfo->getExtension();
        $baseFixturesDir = preg_replace("~\.{$fileExtension}$~", '', $fileInfo->getPathname());

        return $baseFixturesDir . ('' === $subdir ? $subdir : "/{$subdir}");
    }

    /**
     * For convenience
     */
    private static function createFixturesDirInfo(string $subdir = ''): SplFileInfo
    {
        return new SplFileInfo(self::createFixturesDirPathname($subdir));
    }

    private static function getNonexistentDirInfo(): SplFileInfo
    {
        if (!isset(self::$nonexistentDirInfo)) {
            self::$nonexistentDirInfo = self::createFixturesDirInfo('doesNotExist');
        }

        return self::$nonexistentDirInfo;
    }

    /**
     * @return string[]
     */
    private static function listSignificantFiles(string $dirPathname): array
    {
        /** @var string[] Because I'm lazy */
        $basenames = scandir($dirPathname);

        return array_filter(
            $basenames,
            fn (string $basename): bool => '.' !== $basename[0],
        );
    }

    /** @return array<mixed[]> */
    public static function providesValidConstructorArgs(): array
    {
        $fixturesDirInfo = self::createFixturesDirInfo('testIsInstantiable');

        return [
            [
                [$fixturesDirInfo, 0],
            ],
            [
                [$fixturesDirInfo, 60],
            ],
            [
                [$fixturesDirInfo, Squirrel::TTL_SESSION_LIFETIME],
            ],
        ];
    }

    /** @phpstan-param ConstructorArgsArray $validConstructorArgs */
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
                -2,
            ],
            [
                -3,
            ],
        ];
    }

    #[DataProvider('providesInvalidTtls')]
    public function testThrowsAnExceptionIfTheTtlIsInvalid(int $invalidTtl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The TTL, `{$invalidTtl}`, is invalid");

        new Squirrel(
            self::createFixturesDirInfo(__FUNCTION__),
            $invalidTtl,
        );
    }

    /** @return array<mixed[]> */
    public static function providesValidArgsForCreate(): array
    {
        $fixturesDirInfo = self::createFixturesDirInfo('testCreateReturnsANewInstance');

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
            [
                [$fixturesDirInfo->getPathname(), Squirrel::TTL_SESSION_LIFETIME],
            ],
            [
                [$fixturesDirInfo, Squirrel::TTL_SESSION_LIFETIME],
            ],
        ];
    }

    /** @phpstan-param FactoryArgsArray $factoryArgs */
    #[DataProvider('providesValidArgsForCreate')]
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

        Squirrel::create(
            self::createFixturesDirPathname(__FUNCTION__),
            $invalidTtl,
        );
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
            ->setConstructorArgs([self::createFixturesDirInfo(__FUNCTION__), 42])
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

        $cachedItem = $mockSquirrel->squirrel('foo', function (): string {
            return 'bar';
        });

        $this->assertSame('bar', $cachedItem);
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
            ->setConstructorArgs([self::createFixturesDirInfo(__FUNCTION__), 42])
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

        $cachedItem = $mockSquirrel->squirrel('foo', function (): void {
            // Ignored
        });

        $this->assertSame('bar', $cachedItem);
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
            self::createFixturesDirPathname('testSquirrelDoesWhatItShould'),
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

        $this->assertEqualsWithDelta(
            $originalExpiresAt,
            $itemAfterMoreThanASecond->expiresAt,
            1,  /* Allows 1 ms difference, to account for slight variances in the execution-time of the tests code */
        );

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

    public function testSquirrelImmediatelyInvokesTheFactoryFunctionIfCachingIsDisabled(): void
    {
        $mockSquirrel = $this
            ->getMockBuilder(Squirrel::class)
            ->onlyMethods([
                'hasItem',
                'save',
                'getItem',
            ])
            ->setConstructorArgs([
                self::createFixturesDirInfo(__FUNCTION__),
                0,
            ])
            ->getMock()
        ;

        $mockSquirrel
            ->expects($this->never())
            ->method('hasItem')
        ;

        $mockSquirrel
            ->expects($this->never())
            ->method('save')
        ;

        $mockSquirrel
            ->expects($this->never())
            ->method('getItem')
        ;

        /** @var Squirrel $mockSquirrel */

        $factoryReturnValue = $mockSquirrel->squirrel('foo', function (): string {
            return 'bar';
        });

        $this->assertSame('bar', $factoryReturnValue);
    }

    public function testSquirrelCleansUpAfterItselfIfTheTtlIsSession(): void
    {
        $fixturesDir = self::createFixturesDirPathname(__FUNCTION__);
        $basenameOfFileBelongingToOtherSession = '7e50c2e875a2258b4039d738b5a8ee0c.bs';
        $significantFixtureFilesAtStart = self::listSignificantFiles($fixturesDir);

        $this->assertIsArray($significantFixtureFilesAtStart);
        $this->assertCount(1, $significantFixtureFilesAtStart);
        $this->assertContains($basenameOfFileBelongingToOtherSession, $significantFixtureFilesAtStart);

        $key = 'Time when factory-function was invoked';
        $factory = time(...);

        $squirrel = Squirrel::create(
            $fixturesDir,
            ttl: Squirrel::TTL_SESSION_LIFETIME,
        );

        $originalCacheItem = $squirrel->squirrel($key, $factory);

        $this->assertIsInt($originalCacheItem);

        sleep(1);
        $itemAfterASecond = $squirrel->squirrel($key, $factory);
        $significantFilesAfterASecond = self::listSignificantFiles($fixturesDir);

        $this->assertSame($originalCacheItem, $itemAfterASecond);
        $this->assertIsArray($significantFilesAfterASecond);
        $this->assertCount(2, $significantFilesAfterASecond);
        $this->assertContains($basenameOfFileBelongingToOtherSession, $significantFilesAfterASecond);

        sleep(2);
        $itemAfterTwoSeconds = $squirrel->squirrel($key, $factory);
        $significantFilesAfterTwoSeconds = self::listSignificantFiles($fixturesDir);

        $this->assertSame($originalCacheItem, $itemAfterTwoSeconds);
        $this->assertIsArray($significantFilesAfterTwoSeconds);
        $this->assertCount(2, $significantFilesAfterTwoSeconds);
        $this->assertContains($basenameOfFileBelongingToOtherSession, $significantFilesAfterTwoSeconds);

        $squirrel->__destruct();

        // (Only) the pre-existing file should remain after the end of the session
        $significantFilesAfterSessionEnded = self::listSignificantFiles($fixturesDir);
        $this->assertIsArray($significantFilesAfterSessionEnded);
        $this->assertCount(1, $significantFilesAfterSessionEnded);
        $this->assertContains($basenameOfFileBelongingToOtherSession, $significantFilesAfterSessionEnded);
    }
}
