<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;

/**
 * Reads the first sheet of an .xlsx file (or a .csv file) into rows of
 * strings, for imports. Needs no extension beyond zlib: the xlsx package is
 * unzipped here, so it also works where ext-zip is missing.
 *
 * Cells come back as their stored text; numbers stay as written (a date is
 * its Excel serial number, see excelDate()).
 */
class SpreadsheetReader
{
    /**
     * @return list<list<string|null>>
     */
    public function read(string $path, ?string $extension = null): array
    {
        $extension = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx' => $this->readXlsx($path),
            'csv', 'txt' => $this->readCsv($path),
            default => throw new RuntimeException('Yalnız .xlsx veya .csv dosyası okunabilir.'),
        };
    }

    /**
     * Excel serial day number (1900 date system) as Y-m-d.
     */
    public static function excelDate(float $serial): string
    {
        return gmdate('Y-m-d', (int) round(($serial - 25569) * 86400));
    }

    /**
     * @return list<list<string|null>>
     */
    private function readCsv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1254');
        }

        $firstLine = strtok($content, "\n");
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $rows = [];
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($value) => $value === '' ? null : $value, $row);
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return list<list<string|null>>
     */
    private function readXlsx(string $path): array
    {
        $files = $this->unzip($path);

        $workbook = $this->xml($files, 'xl/workbook.xml');
        $sheet = $workbook->sheets->sheet[0] ?? throw new RuntimeException('Dosyada sayfa yok.');
        $relationId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];

        $sheetPath = 'xl/worksheets/sheet1.xml';
        if (isset($files['xl/_rels/workbook.xml.rels'])) {
            foreach ($this->xml($files, 'xl/_rels/workbook.xml.rels')->Relationship as $relation) {
                if ((string) $relation['Id'] === $relationId) {
                    $target = ltrim((string) $relation['Target'], '/');
                    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                }
            }
        }

        $strings = [];
        if (isset($files['xl/sharedStrings.xml'])) {
            foreach ($this->xml($files, 'xl/sharedStrings.xml')->si as $item) {
                $strings[] = $this->text($item);
            }
        }

        $rows = [];
        foreach ($this->xml($files, $sheetPath)->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $value = match ((string) $cell['t']) {
                    's' => $strings[(int) $cell->v] ?? null,
                    'inlineStr' => $this->text($cell->is),
                    'b' => (string) $cell->v === '1' ? '1' : '0',
                    default => isset($cell->v) ? (string) $cell->v : null,
                };
                $index = $this->columnIndex((string) $cell['r']) ?? count($cells);
                $cells[$index] = $value === '' ? null : $value;
            }

            if ($cells !== []) {
                $line = array_fill(0, max(array_keys($cells)) + 1, null);
                foreach ($cells as $index => $value) {
                    $line[$index] = $value;
                }
                $rowNumber = (int) $row['r'] ?: count($rows) + 1;
                // Keep empty rows in between so row numbers match the sheet.
                while (count($rows) < $rowNumber - 1) {
                    $rows[] = [];
                }
                $rows[] = $line;
            }
        }

        return $rows;
    }

    private function text(SimpleXMLElement $item): string
    {
        if (isset($item->t)) {
            return (string) $item->t;
        }

        $text = '';
        foreach ($item->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    private function columnIndex(string $reference): ?int
    {
        if (! preg_match('/^([A-Z]+)/', $reference, $match)) {
            return null;
        }

        $index = 0;
        foreach (str_split($match[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function xml(array $files, string $name): SimpleXMLElement
    {
        $xml = isset($files[$name]) ? simplexml_load_string($files[$name], SimpleXMLElement::class, LIBXML_NONET) : false;

        return $xml ?: throw new RuntimeException('Dosya okunamadı: geçerli bir .xlsx değil.');
    }

    /**
     * Files of a zip archive (stored or deflated entries), by name.
     *
     * @return array<string, string>
     */
    private function unzip(string $path): array
    {
        $data = (string) file_get_contents($path);
        $end = strrpos($data, "PK\x05\x06");
        if ($end === false) {
            throw new RuntimeException('Dosya okunamadı: geçerli bir .xlsx değil.');
        }

        $directory = unpack('ventries/Vsize/Voffset', substr($data, $end + 10, 10));
        $position = $directory['offset'];
        $files = [];

        for ($i = 0; $i < $directory['entries']; $i++) {
            if (substr($data, $position, 4) !== "PK\x01\x02") {
                break;
            }
            $entry = unpack('vmethod/x8/Vcompressed/Vsize/vname/vextra/vcomment/x8/Voffset', substr($data, $position + 10, 36));
            $name = substr($data, $position + 46, $entry['name']);
            $position += 46 + $entry['name'] + $entry['extra'] + $entry['comment'];

            $local = unpack('vname/vextra', substr($data, $entry['offset'] + 26, 4));
            $raw = substr($data, $entry['offset'] + 30 + $local['name'] + $local['extra'], $entry['compressed']);

            $files[$name] = match ($entry['method']) {
                0 => $raw,
                8 => @gzinflate($raw) ?: '',
                default => '',
            };
        }

        return $files;
    }
}
