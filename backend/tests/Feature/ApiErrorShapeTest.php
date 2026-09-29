<?php

namespace Tests\Feature;

use App\Models\Transfer;
use App\Support\ReferenceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An unauthenticated API request must say "not logged in", whatever headers
 * the caller sent. Laravel's default is to redirect an unauthenticated request
 * to a `login` route, which an API-only app does not have — so the redirect
 * throws and the caller gets a 500.
 */
class ApiErrorShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_request_returns_401_without_an_accept_header(): void
    {
        $this->get('/api/meta/options')->assertStatus(401);
    }

    public function test_unauthenticated_api_request_returns_401_with_json_accept(): void
    {
        $this->getJson('/api/meta/options')->assertStatus(401);
    }

    /** A bad token is still "not logged in", not a server error. */
    public function test_a_rejected_token_returns_401(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->get('/api/meta/options')
            ->assertStatus(401);
    }

    /** Public routes are unaffected by the guest-redirect change. */
    public function test_public_routes_still_work(): void
    {
        $this->postJson('/api/auth/login', [])->assertStatus(422);
    }

    /* ------------------------------------------------------------------ */
    /*  Sequence generation locking                                        */
    /* ------------------------------------------------------------------ */

    /**
     * The advisory lock is Postgres-only, so the concurrency it prevents
     * cannot be exercised on SQLite. What is testable is the key derivation:
     * stable for a sequence, distinct between sequences, and inside the int4
     * range pg_advisory_xact_lock accepts.
     */
    public function test_lock_key_is_stable_for_a_sequence(): void
    {
        $this->assertSame(
            ReferenceGenerator::lockKeyFor('transfers.voucher_number'),
            ReferenceGenerator::lockKeyFor('transfers.voucher_number'),
        );
    }

    public function test_lock_keys_differ_between_sequences(): void
    {
        $this->assertNotSame(
            ReferenceGenerator::lockKeyFor('transfers.voucher_number'),
            ReferenceGenerator::lockKeyFor('transfers.reference'),
            'separate sequences must not queue behind one another',
        );
    }

    public function test_lock_key_fits_in_a_postgres_int4(): void
    {
        foreach (['transfers.voucher_number', 'transfers.reference', 'stock_counts.reference'] as $scope) {
            $key = ReferenceGenerator::lockKeyFor($scope);
            $this->assertGreaterThanOrEqual(0, $key);
            $this->assertLessThanOrEqual(2147483647, $key, "{$scope} overflows int4");
        }
    }

    /** Generation still works with the lock in place. */
    public function test_sequences_still_increment(): void
    {
        $this->assertSame('130119', ReferenceGenerator::nextSerial(Transfer::class, 'voucher_number', 130119));
        $this->assertStringStartsWith('TR-', ReferenceGenerator::next(Transfer::class, 'reference', 'TR'));
    }
}
