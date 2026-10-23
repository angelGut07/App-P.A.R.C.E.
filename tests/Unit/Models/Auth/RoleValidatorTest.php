<?php

namespace Tests\Unit\Models\Auth;

use App\Models\Auth\RoleValidator;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;

/**
 * Tests unitarios de RoleValidator — PDO mockeado, nunca MySQL real.
 *
 * getUserRoles() ya se ejercita indirectamente en AuthMiddlewareTest/
 * RBACMiddlewareTest/MechanicApplicationControllerTest, pero solo como efecto
 * colateral de esos flujos. Este archivo prueba la clase de forma aislada,
 * en particular su caché por instancia (no estática, no compartida entre
 * peticiones) y el manejo silencioso de errores de base de datos.
 */
class RoleValidatorTest extends TestCase
{
    use MocksDatabase;

    private RoleValidator $validator;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->validator = new RoleValidator();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
    }

    // =========================================================================
    // getUserRoles()
    // =========================================================================

    public function testGetUserRolesReturnsTheSlugsFromTheQuery(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([['slug' => 'customer'], ['slug' => 'mechanic']]),
        ]);

        $this->assertSame(['customer', 'mechanic'], $this->validator->getUserRoles(7));
    }

    public function testGetUserRolesReturnsAnEmptyArrayWhenTheUserHasNoActiveRoles(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([]),
        ]);

        $this->assertSame([], $this->validator->getUserRoles(7));
    }

    public function testGetUserRolesCachesPerInstanceAndOnlyQueriesOnce(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([['slug' => 'customer']]),
        ]);

        $first = $this->validator->getUserRoles(7);
        $second = $this->validator->getUserRoles(7);

        $this->assertSame($first, $second);
    }

    public function testGetUserRolesCachesSeparatelyPerUserId(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([['slug' => 'customer']]),
            $this->stepFetchAll([['slug' => 'mechanic']]),
        ]);

        $this->assertSame(['customer'], $this->validator->getUserRoles(7));
        $this->assertSame(['mechanic'], $this->validator->getUserRoles(42));
    }

    public function testGetUserRolesFailsSilentlyAndReturnsAnEmptyArrayOnADatabaseError(): void
    {
        $this->mockPdo->method('prepare')->willThrowException(new PDOException('Connection lost'));

        $this->assertSame([], $this->validator->getUserRoles(7));
    }

    // =========================================================================
    // hasRole() / hasAnyRole() / hasAllRoles() — comparten la caché de getUserRoles()
    // =========================================================================

    public function testHasRoleReturnsTrueWhenThePresentAndFalseOtherwiseUsingOnlyOneQuery(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([['slug' => 'mechanic']]),
        ]);

        $this->assertTrue($this->validator->hasRole(7, 'mechanic'));
        $this->assertFalse($this->validator->hasRole(7, 'administrator'));
    }

    public function testHasAnyRoleReturnsFalseImmediatelyForAnEmptyListWithoutTouchingTheDatabase(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $this->assertFalse($this->validator->hasAnyRole(7, []));
    }

    public function testHasAnyRoleReturnsTrueWhenAtLeastOneRoleMatches(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([['slug' => 'customer']]),
        ]);

        $this->assertTrue($this->validator->hasAnyRole(7, ['administrator', 'customer']));
    }

    public function testHasAnyRoleReturnsFalseWhenNoRoleMatches(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([['slug' => 'customer']]),
        ]);

        $this->assertFalse($this->validator->hasAnyRole(7, ['administrator', 'super_admin']));
    }

    public function testHasAllRolesReturnsFalseImmediatelyForAnEmptyListWithoutTouchingTheDatabase(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');

        $this->assertFalse($this->validator->hasAllRoles(7, []));
    }

    public function testHasAllRolesRequiresEveryRoleToBePresent(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([['slug' => 'customer'], ['slug' => 'mechanic']]),
        ]);

        $this->assertTrue($this->validator->hasAllRoles(7, ['customer', 'mechanic']));
    }

    public function testHasAllRolesFailsWhenAtLeastOneRoleIsMissing(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([['slug' => 'customer']]),
        ]);

        $this->assertFalse($this->validator->hasAllRoles(7, ['customer', 'mechanic']));
    }

    // =========================================================================
    // clearCache() / clearAllCaches()
    // =========================================================================

    public function testClearCacheForcesAFreshQueryOnTheNextCall(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([['slug' => 'customer']]),
            $this->stepFetchAll([['slug' => 'customer'], ['slug' => 'mechanic']]),
        ]);

        $this->assertSame(['customer'], $this->validator->getUserRoles(7));
        $this->validator->clearCache(7);
        $this->assertSame(['customer', 'mechanic'], $this->validator->getUserRoles(7));
    }

    public function testClearAllCachesForcesAFreshQueryForEveryUser(): void
    {
        $this->expectQueries([
            $this->stepFetchAll([['slug' => 'customer']]),
            $this->stepFetchAll([['slug' => 'mechanic']]),
        ]);

        $this->assertSame(['customer'], $this->validator->getUserRoles(7));
        $this->validator->clearAllCaches();
        $this->assertSame(['mechanic'], $this->validator->getUserRoles(7));
    }
}
