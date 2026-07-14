<?php

namespace App\Services;

use App\Models\DocAdjustment;
use App\Models\DocHppCorrection;
use App\Models\DocRepack;
use App\Models\DocStockOpname;
use App\Models\DocTransfer;

class InventoryMasterRules
{
    /**
     * @param  list<array<string, mixed>>  $details
     * @return array<string, list<string>>|null
     */
    public static function warehouseWithDetailsErrors(int $warehouseId, array $details): ?array
    {
        return self::mergeErrors(
            PurchaseMasterRules::warehouseErrors($warehouseId),
            PurchaseMasterRules::inventoryProductLinesErrors($details, 'details'),
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, list<string>>|null
     */
    public static function transferPayloadErrors(array $validated): ?array
    {
        return self::mergeErrors(
            PurchaseMasterRules::transferWarehouseErrors(
                $validated['warehouse_from_id'],
                $validated['warehouse_to_id'],
            ),
            PurchaseMasterRules::inventoryProductLinesErrors($validated['details'], 'details'),
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, list<string>>|null
     */
    public static function repackPayloadErrors(array $validated): ?array
    {
        return self::mergeErrors(
            PurchaseMasterRules::warehouseErrors($validated['warehouse_id']),
            PurchaseMasterRules::inventoryProductLinesErrors($validated['inputs'], 'inputs'),
            PurchaseMasterRules::inventoryProductLinesErrors($validated['outputs'], 'outputs'),
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, list<string>>|null
     */
    public static function hppCorrectionPayloadErrors(array $validated): ?array
    {
        return PurchaseMasterRules::inventoryProductLinesErrors($validated['details'], 'details');
    }

    public static function transferDocumentErrors(DocTransfer $transfer): ?array
    {
        $transfer->loadMissing('details');

        $details = $transfer->details->map(fn ($detail) => ['product_id' => $detail->product_id])->all();

        return self::mergeErrors(
            PurchaseMasterRules::transferWarehouseErrors($transfer->warehouse_from_id, $transfer->warehouse_to_id),
            PurchaseMasterRules::inventoryProductLinesErrors($details, 'details'),
        );
    }

    public static function adjustmentDocumentErrors(DocAdjustment $adjustment): ?array
    {
        $adjustment->loadMissing('details');

        $details = $adjustment->details->map(fn ($detail) => ['product_id' => $detail->product_id])->all();

        return self::warehouseWithDetailsErrors($adjustment->warehouse_id, $details);
    }

    public static function opnameDocumentErrors(DocStockOpname $opname): ?array
    {
        $opname->loadMissing('details');

        $details = $opname->details->map(fn ($detail) => ['product_id' => $detail->product_id])->all();

        return self::warehouseWithDetailsErrors($opname->warehouse_id, $details);
    }

    public static function repackDocumentErrors(DocRepack $repack): ?array
    {
        $repack->loadMissing(['inputs', 'outputs']);

        $inputs = $repack->inputs->map(fn ($row) => ['product_id' => $row->product_id])->all();
        $outputs = $repack->outputs->map(fn ($row) => ['product_id' => $row->product_id])->all();

        return self::mergeErrors(
            PurchaseMasterRules::warehouseErrors($repack->warehouse_id),
            PurchaseMasterRules::inventoryProductLinesErrors($inputs, 'inputs'),
            PurchaseMasterRules::inventoryProductLinesErrors($outputs, 'outputs'),
        );
    }

    public static function hppCorrectionDocumentErrors(DocHppCorrection $correction): ?array
    {
        $correction->loadMissing('details');

        $details = $correction->details->map(fn ($detail) => ['product_id' => $detail->product_id])->all();

        return PurchaseMasterRules::inventoryProductLinesErrors($details, 'details');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, list<string>>|null
     */
    public static function salesReturnPayloadErrors(array $validated): ?array
    {
        return self::mergeErrors(
            PurchaseMasterRules::warehouseErrors($validated['warehouse_id']),
            PurchaseMasterRules::inventoryProductLinesErrors($validated['items'], 'items'),
        );
    }

    /**
     * @return array<string, list<string>>|null
     */
    private static function mergeErrors(?array ...$parts): ?array
    {
        $errors = [];

        foreach ($parts as $part) {
            if ($part !== null) {
                $errors = array_merge($errors, $part);
            }
        }

        return $errors !== [] ? $errors : null;
    }
}
