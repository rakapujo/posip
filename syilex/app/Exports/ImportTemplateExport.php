<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ImportTemplateExport implements FromArray, WithHeadings, WithStyles
{
    private array $headings;
    private array $samples;

    public function __construct(array $headings, array $samples = [])
    {
        $this->headings = $headings;
        $this->samples = $samples;
    }

    public function array(): array
    {
        return $this->samples;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function styles(Worksheet $sheet)
    {
        $colCount = count($this->headings);
        $lastCol = $this->getColLetter($colCount);

        // Bold header with background color
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFE2E8F0'],
            ],
        ]);

        // Sample rows in italic + light gray
        $sampleCount = count($this->samples);
        if ($sampleCount > 0) {
            $lastRow = $sampleCount + 1;
            $sheet->getStyle("A2:{$lastCol}{$lastRow}")->applyFromArray([
                'font' => [
                    'italic' => true,
                    'color' => ['argb' => 'FF94A3B8'],
                ],
            ]);
        }

        // Auto-size columns
        for ($i = 1; $i <= $colCount; $i++) {
            $sheet->getColumnDimension($this->getColLetter($i))->setAutoSize(true);
        }

        return [];
    }

    private function getColLetter(int $num): string
    {
        $letter = '';
        while ($num > 0) {
            $num--;
            $letter = chr(65 + ($num % 26)) . $letter;
            $num = intdiv($num, 26);
        }
        return $letter;
    }
}
