<?php

namespace Tests\Unit\Models\ServiceRequest;

use App\Core\DomainException;
use App\Models\ServiceRequest\MechanicRatingService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\MocksDatabase;

/**
 * Tests unitarios de MechanicRatingService — PDO mockeado, nunca MySQL real.
 * El mecánico califica al cliente vía la tabla `ratings`
 * (rating_type='mechanic_to_customer').
 */
class MechanicRatingServiceTest extends TestCase
{
    use MocksDatabase;

    private MechanicRatingService $service;

    protected function setUp(): void
    {
        $this->mockPdo();
        $this->service = new MechanicRatingService();
    }

    protected function tearDown(): void
    {
        $this->unmockPdo();
    }

    private function ratingData(array $overrides = []): array
    {
        return array_merge([
            'score' => 5,
            'comment' => 'Buen cliente, comunicación clara.',
            'punctuality_score' => 4,
            'quality_score' => null,
        ], $overrides);
    }

    public function testRateCustomerSucceedsForTheAssignedMechanicOnACompletedRequest(): void
    {
        $this->expectLastInsertId('7');
        $this->expectQueries([
            $this->stepFetchOne(['id' => 29, 'customer_id' => 1, 'mechanic_id' => 42, 'status' => 'completed']),
            $this->stepFetchOne(false), // sin calificación previa
            function (\PDOStatement $stmt): void {}, // INSERT ratings
            $this->stepFetchOne([
                'id' => 7, 'service_request_id' => 29, 'rater_id' => 42, 'ratee_id' => 1,
                'rating_type' => 'mechanic_to_customer', 'score' => 5,
            ]),
        ]);

        $result = $this->service->rateCustomer(29, 42, $this->ratingData());

        $this->assertSame(7, $result['id']);
        $this->assertSame('mechanic_to_customer', $result['rating_type']);
    }

    public function testRateCustomerFailsWithNotFoundForANonexistentRequest(): void
    {
        $this->expectQueries([$this->stepFetchOne(false)]);

        $this->expectException(DomainException::class);

        try {
            $this->service->rateCustomer(999999, 42, $this->ratingData());
        } catch (DomainException $e) {
            $this->assertSame(404, $e->getStatusCode());
            throw $e;
        }
    }

    public function testRateCustomerFailsForAMechanicNotAssignedToTheRequest(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 29, 'customer_id' => 1, 'mechanic_id' => 999, 'status' => 'completed']),
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->rateCustomer(29, 42, $this->ratingData());
        } catch (DomainException $e) {
            $this->assertSame(403, $e->getStatusCode());
            throw $e;
        }
    }

    public function testRateCustomerFailsWhenTheRequestIsNotCompleted(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 29, 'customer_id' => 1, 'mechanic_id' => 42, 'status' => 'in_progress']),
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->rateCustomer(29, 42, $this->ratingData());
        } catch (DomainException $e) {
            $this->assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }

    public function testRateCustomerFailsOnASecondRatingForTheSameRequest(): void
    {
        $this->expectQueries([
            $this->stepFetchOne(['id' => 29, 'customer_id' => 1, 'mechanic_id' => 42, 'status' => 'completed']),
            $this->stepFetchOne(['id' => 3]), // ya existe una fila mechanic_to_customer para esta solicitud
        ]);

        $this->expectException(DomainException::class);

        try {
            $this->service->rateCustomer(29, 42, $this->ratingData());
        } catch (DomainException $e) {
            $this->assertSame(409, $e->getStatusCode());
            throw $e;
        }
    }

    public function testRateCustomerFailsOnAConcurrentDuplicateCaughtByTheUniqueConstraint(): void
    {
        // El SELECT previo no ve nada (misma carrera que motiva el
        // UNIQUE(service_request_id, rating_type) como respaldo real), pero
        // el INSERT choca con la restricción — debe traducirse al mismo 409.
        $lookup = $this->createMock(\PDOStatement::class);
        $lookup->method('execute')->willReturn(true);
        $lookup->method('fetch')->willReturn(['id' => 29, 'customer_id' => 1, 'mechanic_id' => 42, 'status' => 'completed']);

        $noPriorRating = $this->createMock(\PDOStatement::class);
        $noPriorRating->method('execute')->willReturn(true);
        $noPriorRating->method('fetch')->willReturn(false);

        $insertStmt = $this->createMock(\PDOStatement::class);
        $insertStmt->method('execute')->willThrowException(
            new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '29-mechanic_to_customer' for key 'uq_ratings_request_type'")
        );

        $this->mockPdo->expects($this->exactly(3))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($lookup, $noPriorRating, $insertStmt);

        $this->expectException(DomainException::class);

        try {
            $this->service->rateCustomer(29, 42, $this->ratingData());
        } catch (DomainException $e) {
            $this->assertSame(409, $e->getStatusCode());
            throw $e;
        }
    }
}
