<?php

namespace App\Exports\Concerns;

use App\Concerns\ExportSheetStyles;
use Maatwebsite\Excel\Concerns\WithStyles;

/**
 * Marker + trait for Excel exports that share the standard header row styling.
 */
trait UsesExportSheetStyles
{
    use ExportSheetStyles;
}
