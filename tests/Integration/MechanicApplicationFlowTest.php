<?php

namespace Tests\Integration;

use App\Core\Database;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Auth\AuthService;
use App\Models\Auth\PasswordHasher;
use App\Models\Auth\SessionManager;
use App\Models\MechanicApplication\MechanicApplicationService;
use App\Middleware\RBACMiddleware;

/**
 * Verificación de integración contra una base de datos MySQL de
 * pruebas real y aislada ("parce_test", nunca "parce"). Ejercita las clases
 * reales (MechanicApplicationService, AuthService, RBACMiddleware) contra
 * transacciones/locks/INSERTs/UPDATEs reales — nada de esto está mockeado.
 *
 * Alcance deliberado: se prueba la capa de Service directamente (donde vive
 * toda la lógica de negocio: transacciones, locks, invariantes) y
 * RBACMiddleware de forma aislada para las comprobaciones de autorización.
 * No se levanta el stack HTTP/Router completo — el enrutamiento y la
 * asignación de middleware por ruta están en config/routes.php.
 */
class MechanicApplicationFlowTest extends IntegrationTestCase
{
    private MechanicApplicationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MechanicApplicationService();
    }

    // =========================================================================
    // 1. Registro
    // =========================================================================

    public function testRegisterAssignsOnlyCustomerRoleAndNeverMechanic(): void
    {
        $authService = new AuthService(new PasswordHasher(), new SessionManager());

        $result = $authService->register(
            'nuevo_' . bin2hex(random_bytes(4)) . '@test.local',
            (new PasswordHasher())->hash('Test12345!'),
            ['first_name' => 'Nuevo', 'last_name' => 'Usuario', 'driver_license_number' => 'LIC-1'],
            '127.0.0.1',
            'PHPUnit-Integration',
            null,
            ['certification_title' => 'Técnico automotriz']
        );

        $this->assertTrue($result->success);
        $this->assertTrue($this->hasRole($result->userId, 'customer'));
        // Pedir ser mecánico en el registro abre una solicitud, pero NO concede el rol
        $this->assertFalse($this->hasRole($result->userId, 'mechanic'));
        $pending = Database::fetchOne(
            "SELECT status FROM mechanic_applications WHERE user_id = ?",
            [$result->userId]
        );
        $this->assertSame('pending', $pending['status']);
    }

    // =========================================================================
    // 2. Creación de solicitud
    // =========================================================================

    public function testCreateSucceedsForActiveUserWithCompleteValidLicense(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);

        $applicationId = $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Tengo experiencia como mecanico automotriz certificado.']);

        $app = $this->service->getByIdForUser($applicationId, $userId);
        $this->assertSame('pending', $app['status']);
        $this->assertSame('Técnico automotriz', $app['certification_title']);
    }

    public function testCreateFailsWithIncompleteLicense(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId, complete: false);

        $this->assertDomainException(400, function () use ($userId) {
            $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);
        });
    }

    public function testCreateFailsWithExpiredLicense(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId, '-10 days');

        $this->assertDomainException(400, function () use ($userId) {
            $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);
        });
    }

    public function testCreateFailsForSuspendedUser(): void
    {
        $userId = $this->createUser(['account_status' => 'suspended']);
        $this->setLicense($userId);

        $this->assertDomainException(403, function () use ($userId) {
            $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);
        });
    }

    public function testCreateFailsWhenUserAlreadyHasMechanicRole(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);
        $this->assignRole($userId, 'mechanic');

        $this->assertDomainException(409, function () use ($userId) {
            $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);
        });
    }

    public function testCreateFailsForAdministrator(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);
        $this->assignRole($userId, 'administrator');

        $this->assertDomainException(403, function () use ($userId) {
            $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);
        });

        // No debe haberse creado ninguna fila ni tocado user_roles.
        $this->assertFalse($this->hasRole($userId, 'mechanic'));
        $count = Database::fetchOne('SELECT COUNT(*) AS c FROM mechanic_applications WHERE user_id = ?', [$userId]);
        $this->assertSame(0, (int)$count['c']);
    }

    public function testCreateFailsForSuperAdmin(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);
        $this->assignRole($userId, 'super_admin');

        $this->assertDomainException(403, function () use ($userId) {
            $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);
        });

        $this->assertFalse($this->hasRole($userId, 'mechanic'));
        $count = Database::fetchOne('SELECT COUNT(*) AS c FROM mechanic_applications WHERE user_id = ?', [$userId]);
        $this->assertSame(0, (int)$count['c']);
    }

    public function testCreateFailsWhenAPendingApplicationAlreadyExists(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);
        $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Primera justificacion suficientemente larga.']);

        $this->assertDomainException(409, function () use ($userId) {
            $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Segunda justificacion suficientemente larga.']);
        });
    }


    // =========================================================================
    // 3. Ownership / IDOR
    // =========================================================================

    public function testUserCannotViewAnotherUsersApplication(): void
    {
        $owner = $this->createUser();
        $this->setLicense($owner);
        $applicationId = $this->service->create($owner, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);
        $stranger = $this->createUser();

        $this->assertDomainException(404, function () use ($applicationId, $stranger) {
            $this->service->getByIdForUser($applicationId, $stranger);
        });
    }

    public function testUserCannotCancelAnotherUsersApplication(): void
    {
        $owner = $this->createUser();
        $this->setLicense($owner);
        $applicationId = $this->service->create($owner, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);
        $stranger = $this->createUser();

        $this->assertDomainException(404, function () use ($applicationId, $stranger) {
            $this->service->cancel($applicationId, $stranger);
        });
    }

    public function testOwnershipCheckDoesNotRevealWhetherAForeignIdExists(): void
    {
        $stranger = $this->createUser();
        $owner = $this->createUser();
        $this->setLicense($owner);
        $applicationId = $this->service->create($owner, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);

        $messageForNonexistentId = null;
        try {
            $this->service->getByIdForUser(999999, $stranger);
        } catch (DomainException $e) {
            $messageForNonexistentId = [$e->getMessage(), $e->getStatusCode()];
        }

        $messageForForeignId = null;
        try {
            $this->service->getByIdForUser($applicationId, $stranger);
        } catch (DomainException $e) {
            $messageForForeignId = [$e->getMessage(), $e->getStatusCode()];
        }

        $this->assertNotNull($messageForNonexistentId);
        $this->assertSame($messageForNonexistentId, $messageForForeignId);
    }

    // =========================================================================
    // 4. Cancelación
    // =========================================================================

    public function testCancelTransitionsPendingToCancelled(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);
        $applicationId = $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);

        $result = $this->service->cancel($applicationId, $userId);

        $this->assertSame('cancelled', $result['status']);
    }

    public function testCancelFailsWhenApplicationIsAlreadyApproved(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);
        $applicationId = $this->createPendingApplication($userId);
        $admin = $this->createUser();
        $this->assignRole($admin, 'administrator');
        $this->service->approve($applicationId, $admin);

        $this->assertDomainException(409, function () use ($applicationId, $userId) {
            $this->service->cancel($applicationId, $userId);
        });
    }

    public function testCancelFailsWhenApplicationIsAlreadyRejected(): void
    {
        $userId = $this->createUser();
        $applicationId = $this->createPendingApplication($userId);
        $admin = $this->createUser();
        $this->assignRole($admin, 'administrator');
        $this->service->reject($applicationId, $admin, 'Motivo de rechazo de prueba.');

        $this->assertDomainException(409, function () use ($applicationId, $userId) {
            $this->service->cancel($applicationId, $userId);
        });
    }

    public function testCancelFailsWhenApplicationIsAlreadyCancelled(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);
        $applicationId = $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);
        $this->service->cancel($applicationId, $userId);

        $this->assertDomainException(409, function () use ($applicationId, $userId) {
            $this->service->cancel($applicationId, $userId);
        });
    }

    // =========================================================================
    // 5. Aprobación
    // =========================================================================

    public function testAdministratorCanApprove(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);
        $applicationId = $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);
        $admin = $this->createUser();
        $this->assignRole($admin, 'administrator');

        $result = $this->service->approve($applicationId, $admin);

        $this->assertSame('approved', $result['status']);
        $this->assertTrue($this->hasRole($userId, 'mechanic'));
    }

    public function testSuperAdminCanApprove(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);
        $applicationId = $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);
        $superAdmin = $this->createUser();
        $this->assignRole($superAdmin, 'super_admin');

        $result = $this->service->approve($applicationId, $superAdmin);

        $this->assertSame('approved', $result['status']);
        $this->assertTrue($this->hasRole($userId, 'mechanic'));
    }

    public function testCustomerCannotAccessAdminApprovalRoute(): void
    {
        $customer = $this->createUser();
        $this->assignRole($customer, 'customer');

        $middleware = new RBACMiddleware(['administrator', 'super_admin']);
        $request = new Request();
        $request->setAttribute('user', ['id' => $customer]);

        $response = $middleware->handle($request, fn(Request $req) => new Response());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMechanicCannotAccessAdminApprovalRoute(): void
    {
        $mechanic = $this->createUser();
        $this->assignRole($mechanic, 'mechanic');

        $middleware = new RBACMiddleware(['administrator', 'super_admin']);
        $request = new Request();
        $request->setAttribute('user', ['id' => $mechanic]);

        $response = $middleware->handle($request, fn(Request $req) => new Response());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testApplicantCannotApproveTheirOwnApplication(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);
        $applicationId = $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);

        $this->assertDomainException(403, function () use ($applicationId, $userId) {
            $this->service->approve($applicationId, $userId);
        });

        $this->assertFalse($this->hasRole($userId, 'mechanic'));
    }

    public function testApprovalFailsWhenUserBecameInactiveAfterApplying(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);
        $applicationId = $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);

        // El usuario se suspende DESPUÉS de aplicar, antes de que el admin revise.
        Database::update('users', ['account_status' => 'suspended'], 'id = ?', [$userId]);

        $admin = $this->createUser();
        $this->assignRole($admin, 'administrator');

        $this->assertDomainException(400, function () use ($applicationId, $admin) {
            $this->service->approve($applicationId, $admin);
        });
    }

    public function testApprovalFailsWhenLicenseExpiresAfterApplying(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId, '+1 day'); // vigente al momento de aplicar
        $applicationId = $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);

        // La licencia vence DESPUÉS de aplicar, antes de que el admin revise.
        $this->setLicense($userId, '-1 day');

        $admin = $this->createUser();
        $this->assignRole($admin, 'administrator');

        $this->assertDomainException(400, function () use ($applicationId, $admin) {
            $this->service->approve($applicationId, $admin);
        });
    }

    public function testApprovalCreatesExactlyOneMechanicRoleAssignmentWithCorrectAuditFields(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);
        $applicationId = $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);
        $admin = $this->createUser();
        $this->assignRole($admin, 'administrator');

        $result = $this->service->approve($applicationId, $admin);

        $this->assertSame(1, $this->countRoleAssignments($userId, 'mechanic'));

        $roleRow = Database::fetchOne(
            'SELECT * FROM user_roles WHERE user_id = ? AND role_id = ?',
            [$userId, self::$mechanicRoleId]
        );
        $this->assertSame($admin, (int)$roleRow['assigned_by']);
        $this->assertSame(1, (int)$roleRow['is_active']);

        $this->assertSame($admin, (int)$result['reviewed_by']);
        $this->assertNotNull($result['reviewed_at']);
        $this->assertSame('approved', $result['status']);
    }

    // =========================================================================
    // 6. Rechazo
    // =========================================================================

    public function testAdministratorCanReject(): void
    {
        $userId = $this->createUser();
        $applicationId = $this->createPendingApplication($userId);
        $admin = $this->createUser();
        $this->assignRole($admin, 'administrator');

        $result = $this->service->reject($applicationId, $admin, 'La licencia esta vencida.');

        $this->assertSame('rejected', $result['status']);
    }

    public function testSuperAdminCanReject(): void
    {
        $userId = $this->createUser();
        $applicationId = $this->createPendingApplication($userId);
        $superAdmin = $this->createUser();
        $this->assignRole($superAdmin, 'super_admin');

        $result = $this->service->reject($applicationId, $superAdmin, 'Informacion insuficiente.');

        $this->assertSame('rejected', $result['status']);
    }

    public function testRejectPersistsTheGivenReason(): void
    {
        // La obligatoriedad del motivo ya se probó exhaustivamente a nivel
        // unitario en MechanicApplicationValidatorTest; aquí se
        // confirma que el Service persiste correctamente el motivo recibido.
        $userId = $this->createUser();
        $applicationId = $this->createPendingApplication($userId);
        $admin = $this->createUser();
        $this->assignRole($admin, 'administrator');

        $result = $this->service->reject($applicationId, $admin, 'Motivo real de prueba.');

        $this->assertSame('Motivo real de prueba.', $result['review_notes']);
    }

    public function testRejectPopulatesReviewedByAndDoesNotGrantTheRole(): void
    {
        $userId = $this->createUser();
        $applicationId = $this->createPendingApplication($userId);
        $admin = $this->createUser();
        $this->assignRole($admin, 'administrator');

        $result = $this->service->reject($applicationId, $admin, 'Motivo de rechazo de prueba.');

        $this->assertSame($admin, (int)$result['reviewed_by']);
        $this->assertNotNull($result['reviewed_at']);
        $this->assertFalse($this->hasRole($userId, 'mechanic'));
    }

    // =========================================================================
    // 8. Integridad final
    // =========================================================================

    public function testNoMechanicRoleExistsWhileApplicationIsPending(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);
        $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);

        $this->assertFalse($this->hasRole($userId, 'mechanic'));
    }

    public function testNoMechanicRoleExistsAfterRejection(): void
    {
        $userId = $this->createUser();
        $applicationId = $this->createPendingApplication($userId);
        $admin = $this->createUser();
        $this->assignRole($admin, 'administrator');
        $this->service->reject($applicationId, $admin, 'Motivo de prueba.');

        $this->assertFalse($this->hasRole($userId, 'mechanic'));
    }

    public function testNoMechanicRoleExistsAfterCancellation(): void
    {
        $userId = $this->createUser();
        $this->setLicense($userId);
        $applicationId = $this->service->create($userId, ['certification_title' => 'Técnico automotriz', 'justification' => 'Justificacion suficientemente larga para pasar validacion.']);
        $this->service->cancel($applicationId, $userId);

        $this->assertFalse($this->hasRole($userId, 'mechanic'));
    }
}
