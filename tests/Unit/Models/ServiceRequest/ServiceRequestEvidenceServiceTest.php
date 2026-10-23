<?php

namespace Tests\Unit\Models\ServiceRequest;

use App\Core\DomainException;
use App\Models\ServiceRequest\ServiceRequestEvidenceService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;

/**
 * Tests unitarios de ServiceRequestEvidenceService — PDO mockeado, nunca
 * MySQL real. Único test de este Service en todo el proyecto (antes solo se
 * ejercitaba superficialmente vía ServiceRequestControllerTest) — cubre a
 * fondo la validación de negocio propia (tipo/URL/extensión/tamaño de
 * evidencia) que no vive en ningún Validator separado.
 */
class ServiceRequestEvidenceServiceTest extends TestCase
{
    use MocksDatabase;

    private ServiceRequestEvidenceService $service;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->service = new ServiceRequestEvidenceService();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
    }

    private function validEvidenceData(array $overrides = []): array
    {
        return array_merge([
            'evidence_type' => 'before',
            'image_url' => 'https://cdn.parce.test/evidencia.jpg',
            'original_filename' => 'foto.jpg',
            'description' => 'Antes de la reparación',
            'file_size' => 1024,
        ], $overrides);
    }

    // =========================================================================
    // addEvidence() — control de acceso y estado
    // =========================================================================

    public function testAddEvidenceFailsWithNotFoundForANonexistentServiceRequest(): void
    {
        $this->expectQueries([$this->stepFetchOne(false)]);

        $this->expectException(DomainException::class);
        try {
            $this->service->addEvidence(999999, 42, $this->validEvidenceData());
        } catch (DomainException $e) {
            $this->assertSame(404, $e->getStatusCode());
            throw $e;
        }
    }

    public function testAddEvidenceFailsWhenTheMechanicIsNotAssignedToTheRequest(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'mechanic_id' => 999, 'status' => 'in_progress']),
        ]);

        $this->expectException(DomainException::class);
        try {
            $this->service->addEvidence(5, 42, $this->validEvidenceData());
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getStatusCode());
            throw $e;
        }
    }

    public function testAddEvidenceFailsWhenTheRequestIsNotInAnEligibleStatus(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'mechanic_id' => 42, 'status' => 'pending']),
        ]);

        $this->expectException(DomainException::class);
        try {
            $this->service->addEvidence(5, 42, $this->validEvidenceData());
        } catch (DomainException $e) {
            $this->assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }

    // =========================================================================
    // addEvidence() — validación de datos
    // =========================================================================

    public function testAddEvidenceRejectsAnInvalidEvidenceType(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'mechanic_id' => 42, 'status' => 'in_progress']),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->addEvidence(5, 42, $this->validEvidenceData(['evidence_type' => 'middle']));
    }

    public function testAddEvidenceRejectsAMissingImageUrl(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'mechanic_id' => 42, 'status' => 'in_progress']),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->addEvidence(5, 42, $this->validEvidenceData(['image_url' => '']));
    }

    public function testAddEvidenceRejectsANonHttpUrl(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'mechanic_id' => 42, 'status' => 'in_progress']),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->addEvidence(5, 42, $this->validEvidenceData(['image_url' => 'ftp://cdn.parce.test/foto.jpg']));
    }

    public function testAddEvidenceRejectsAnUnsupportedFileExtension(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'mechanic_id' => 42, 'status' => 'in_progress']),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->addEvidence(5, 42, $this->validEvidenceData(['image_url' => 'https://cdn.parce.test/documento.pdf']));
    }

    public function testAddEvidenceRejectsAFileSizeOverTheLimit(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'mechanic_id' => 42, 'status' => 'in_progress']),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->addEvidence(5, 42, $this->validEvidenceData(['file_size' => 5242880 + 1]));
    }

    // =========================================================================
    // addEvidence() — éxito y re-validación bajo lock
    // =========================================================================

    public function testAddEvidenceSucceedsAndReturnsTheStoredRecord(): void
    {
        $this->allowTransactions();
        $this->expectLastInsertId('9');
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'mechanic_id' => 42, 'status' => 'in_progress']),
            $this->stepFetchOne(['status' => 'in_progress']), // revalidación con lock dentro de la transacción
            function (\PDOStatement $stmt): void {}, // INSERT service_request_evidences
            $this->stepFetchOne([
                'id' => 9, 'service_request_id' => 5, 'uploaded_by' => 42,
                'evidence_type' => 'before', 'image_url' => 'https://cdn.parce.test/evidencia.jpg',
            ]),
        ]);

        $evidence = $this->service->addEvidence(5, 42, $this->validEvidenceData());

        $this->assertSame(9, $evidence['id']);
    }

    public function testAddEvidenceRejectsWhenTheStatusChangedConcurrentlyBetweenTheInitialCheckAndTheLock(): void
    {
        // El chequeo inicial (fuera de la transacción) ve 'in_progress', pero la
        // solicitud se canceló justo antes de que la transacción tomara el lock.
        $this->allowTransactions();
        $this->expectQueries([
            $this->stepFetchOne(['id' => 5, 'mechanic_id' => 42, 'status' => 'in_progress']),
            $this->stepFetchOne(['status' => 'cancelled']),
        ]);

        $this->expectException(DomainException::class);
        try {
            $this->service->addEvidence(5, 42, $this->validEvidenceData());
        } catch (DomainException $e) {
            $this->assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }

    // =========================================================================
    // getEvidences()
    // =========================================================================

    public function testGetEvidencesFailsWithNotFoundForANonexistentServiceRequest(): void
    {
        $this->expectQueries([$this->stepFetchOne(false)]);

        $this->expectException(DomainException::class);
        try {
            $this->service->getEvidences(999999, 1, 'customer');
        } catch (DomainException $e) {
            $this->assertSame(404, $e->getStatusCode());
            throw $e;
        }
    }

    public function testGetEvidencesFailsForAUserWithNoAccess(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['customer_id' => 1, 'mechanic_id' => 42]),
        ]);

        $this->expectException(DomainException::class);
        try {
            $this->service->getEvidences(5, 999, 'customer');
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getStatusCode());
            throw $e;
        }
    }

    public function testGetEvidencesSucceedsForTheOwningCustomer(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['customer_id' => 1, 'mechanic_id' => 42]),
            $this->stepFetchAll([['id' => 9, 'evidence_type' => 'before']]),
        ]);

        $evidences = $this->service->getEvidences(5, 1, 'customer');

        $this->assertCount(1, $evidences);
    }

    public function testGetEvidencesSucceedsForTheAssignedMechanic(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['customer_id' => 1, 'mechanic_id' => 42]),
            $this->stepFetchAll([['id' => 9, 'evidence_type' => 'before']]),
        ]);

        $evidences = $this->service->getEvidences(5, 42, 'mechanic');

        $this->assertCount(1, $evidences);
    }
}
