<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\BusinessException;
use App\Exceptions\DocumentStateException;
use App\Exceptions\StockInsufficientException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class BusinessExceptionTest extends TestCase
{
    #[Test]
    public function business_exception_defaults_to_422(): void
    {
        $e = new BusinessException('Test error');

        $this->assertEquals('Test error', $e->getMessage());
        $this->assertEquals(422, $e->getStatusCode());
        $this->assertEquals([], $e->getContext());
    }
    #[Test]
    public function business_exception_accepts_custom_status_and_context(): void
    {
        $e = new BusinessException('Conflict', 409, ['field' => 'value']);

        $this->assertEquals(409, $e->getStatusCode());
        $this->assertEquals(['field' => 'value'], $e->getContext());
    }
    #[Test]
    public function stock_insufficient_builds_descriptive_message(): void
    {
        $e = StockInsufficientException::forProduct(1, 'Indomie Goreng', 10.0, 3.0);

        $this->assertStringContainsString('Indomie Goreng', $e->getMessage());
        $this->assertStringContainsString('10', $e->getMessage());
        $this->assertStringContainsString('3', $e->getMessage());
        $this->assertEquals(422, $e->getStatusCode());

        $context = $e->getContext();
        $this->assertEquals(1, $context['product_id']);
        $this->assertEquals('Indomie Goreng', $context['product_name']);
        $this->assertEquals(10.0, $context['required_qty']);
        $this->assertEquals(3.0, $context['available_qty']);
    }
    #[Test]
    public function document_state_cannot_edit_message(): void
    {
        $e = DocumentStateException::cannotEdit('Promo', 'approved');

        $this->assertStringContainsString('Promo', $e->getMessage());
        $this->assertStringContainsString('approved', $e->getMessage());
        $this->assertEquals(['current_status' => 'approved'], $e->getContext());
    }
    #[Test]
    public function document_state_cannot_transition_message(): void
    {
        $e = DocumentStateException::cannotTransition('Sales', 'voided', 'completed');

        $this->assertStringContainsString('voided', $e->getMessage());
        $this->assertStringContainsString('completed', $e->getMessage());
        $this->assertEquals(['from' => 'voided', 'to' => 'completed'], $e->getContext());
    }
    #[Test]
    public function semua_turunan_adalah_instance_business_exception(): void
    {
        $this->assertInstanceOf(BusinessException::class, StockInsufficientException::forProduct(1, 'X', 1.0, 0.0));
        $this->assertInstanceOf(BusinessException::class, DocumentStateException::cannotEdit('Promo', 'approved'));
        $this->assertInstanceOf(\Exception::class, new BusinessException('x'));
    }
    #[Test]
    public function business_exception_kode_internal_selalu_nol_terlepas_status_http(): void
    {
        $e = new BusinessException('Conflict', 409, ['a' => 1]);

        $this->assertSame(409, $e->getStatusCode());
        $this->assertSame(0, $e->getCode());
    }
    #[Test]
    public function business_exception_mempertahankan_previous_throwable(): void
    {
        $prev = new \LogicException('akar masalah');
        $e = new BusinessException('Pesan', 422, [], $prev);

        $this->assertSame($prev, $e->getPrevious());
    }
    #[Test]
    public function stock_insufficient_pesan_eksak_dan_status_default(): void
    {
        $e = StockInsufficientException::forProduct(7, 'Indomie Goreng', 10.0, 3.0);

        $this->assertSame(
            "Stok produk 'Indomie Goreng' tidak mencukupi. Butuh: 10, tersedia: 3.",
            $e->getMessage()
        );
        $this->assertSame(422, $e->getStatusCode());
        $this->assertSame([
            'product_id' => 7,
            'product_name' => 'Indomie Goreng',
            'required_qty' => 10.0,
            'available_qty' => 3.0,
        ], $e->getContext());
    }
    #[Test]
    public function stock_insufficient_pesan_pecahan_dipertahankan(): void
    {
        $e = StockInsufficientException::forProduct(9, 'Beras 5kg', 2.5, 0.75);

        $this->assertSame(
            "Stok produk 'Beras 5kg' tidak mencukupi. Butuh: 2.5, tersedia: 0.75.",
            $e->getMessage()
        );
        $this->assertSame(2.5, $e->getContext()['required_qty']);
        $this->assertSame(0.75, $e->getContext()['available_qty']);
    }
    #[Test]
    public function document_state_cannot_edit_pesan_dan_context_eksak(): void
    {
        $e = DocumentStateException::cannotEdit('Promo', 'approved');

        $this->assertSame(
            "Promo dengan status 'approved' tidak dapat diedit. Batalkan approval terlebih dahulu.",
            $e->getMessage()
        );
        $this->assertSame(422, $e->getStatusCode());
        $this->assertSame(['current_status' => 'approved'], $e->getContext());
    }
    #[Test]
    public function document_state_cannot_transition_pesan_dan_context_eksak(): void
    {
        $e = DocumentStateException::cannotTransition('Sales', 'voided', 'completed');

        $this->assertSame(
            "Transisi Sales dari 'voided' ke 'completed' tidak diizinkan.",
            $e->getMessage()
        );
        $this->assertSame(422, $e->getStatusCode());
        $this->assertSame(['from' => 'voided', 'to' => 'completed'], $e->getContext());
    }
}

class BusinessExceptionRenderTest extends \Tests\TestCase
{
    #[Test]
    public function business_exception_dirender_sebagai_422_dengan_message_dan_errors(): void
    {
        \Illuminate\Support\Facades\Route::middleware('api')->get('/__test/biz', function () {
            throw new BusinessException('Aturan bisnis dilanggar', 422, ['field' => 'qty']);
        });

        $this->getJson('/__test/biz')
            ->assertStatus(422)
            ->assertExactJson([
                'success' => false,
                'message' => 'Aturan bisnis dilanggar',
                'errors' => ['field' => 'qty'],
            ]);
    }
    #[Test]
    public function business_exception_tanpa_context_tidak_menyertakan_key_errors(): void
    {
        \Illuminate\Support\Facades\Route::middleware('api')->get('/__test/biz-nocontext', function () {
            throw new BusinessException('Tanpa konteks');
        });

        $this->getJson('/__test/biz-nocontext')
            ->assertStatus(422)
            ->assertExactJson([
                'success' => false,
                'message' => 'Tanpa konteks',
            ]);
    }
    #[Test]
    public function stock_insufficient_dirender_422_dengan_payload_eksak(): void
    {
        \Illuminate\Support\Facades\Route::middleware('api')->get('/__test/stock', function () {
            throw StockInsufficientException::forProduct(3, 'Susu UHT', 5.0, 1.0);
        });

        $this->getJson('/__test/stock')
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => "Stok produk 'Susu UHT' tidak mencukupi. Butuh: 5, tersedia: 1.",
                'errors' => [
                    'product_id' => 3,
                    'product_name' => 'Susu UHT',
                    'required_qty' => 5,
                    'available_qty' => 1,
                ],
            ]);
    }
}
