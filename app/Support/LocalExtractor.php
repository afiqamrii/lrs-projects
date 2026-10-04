<?php

namespace App\Support;

use App\Models\DocumentRun;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use ZipArchive;

class LocalExtractor
{
    private DocumentRun $run;

    private array $limits;

    private string $work;

    private int $characters = 0;

    private float $deadline;

    public function __construct(private ExtractionTools $tools) {}

    public function handle(DocumentRun $run): void
    {
        $this->run = $run;
        $this->limits = $run->configuration['limits'];
        $this->characters = 0;
        $this->deadline = microtime(true) + $this->limits['run_timeout'];
        $disk = Storage::disk('extraction');
        $relative = $this->base().'/work/'.Str::uuid();
        $disk->makeDirectory($relative);
        $this->work = $disk->path($relative);
        try {
            $document = $run->document;
            $path = Storage::disk('inquiry_documents')->path($document->storage_path);
            if (! is_file($path) || ! hash_equals($run->checksum, hash_file('sha256', $path))) {
                throw new ExtractionProblem('source_changed', 'The original is missing or its checksum changed. Restore the preserved source before retrying.');
            }
            $extension = strtolower(pathinfo($document->original_name, PATHINFO_EXTENSION));
            $controlled = $this->work.'/source.'.$extension;
            if (! copy($path, $controlled)) {
                throw new ExtractionProblem('storage', 'The private source could not be read. Ask Admin to check storage.');
            }
            match ($extension) {
                'pdf' => $this->pdf($controlled),
                'jpg', 'jpeg', 'png' => $this->image($controlled),
                'docx' => $this->docx($controlled),
                'xlsx' => $this->xlsx($controlled),
                'csv' => $this->csv($controlled),
                default => throw new ExtractionProblem('unsupported', 'This source format has no supported extraction path. Continue with manual entry.'),
            };
            $this->run->refresh();
            $skipped = collect($this->run->pages)->contains(fn (array $page): bool => in_array($page['state'], ['skipped', 'failed', 'unavailable', 'truncated'], true));
            $state = $skipped ? 'partial' : ($this->run->warnings ? 'manual_review' : 'extracted');
            if (! $this->run->blocks) {
                $state = $skipped ? 'partial' : 'manual_review';
                $this->warning('No readable text was found. Use the private original and manual entry.');
            }
            $this->run->update(['state' => $state, 'completed_at' => now()]);
        } catch (ExtractionProblem $exception) {
            $this->run->refresh()->update(['state' => $this->run->blocks ? 'partial' : ($exception->unavailable ? 'unavailable' : 'failed'), 'error_code' => $exception->reason, 'error_message' => $exception->getMessage(), 'completed_at' => now()]);
        } catch (\Throwable $exception) {
            $this->run->refresh()->update(['state' => $this->run->blocks ? 'partial' : 'failed', 'error_code' => 'unreadable', 'error_message' => 'The source could not be read safely. Successful passages are retained. Retry a smaller selection or enter details manually.', 'completed_at' => now()]);
        } finally {
            $resolved = realpath($this->work);
            $root = realpath($disk->path($this->base().'/work'));
            if ($resolved && $root && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                $disk->deleteDirectory($relative);
            }
        }
    }

    private function base(): string
    {
        return 'inquiries/'.$this->run->inquiry_id.'/runs/'.$this->run->id;
    }

    private function warning(string $message): void
    {
        $warnings = $this->run->warnings ?? [];
        $warnings[] = $message;
        $this->run->update(['warnings' => array_values(array_unique($warnings))]);
    }

    private function block(string $locator, string $text, string $method, array $metadata = [], array $warnings = []): void
    {
        if (microtime(true) > $this->deadline) {
            throw new ExtractionProblem('time_limit', 'The run reached its time limit. Completed passages are retained; select fewer pages for another local run.');
        }
        $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', mb_scrub($text, 'UTF-8')));
        if ($text === '') {
            return;
        }
        $available = $this->limits['text_chars'] - $this->characters;
        if (mb_strlen($text) > $available) {
            $this->warning('The text limit was reached. This source is incomplete; omitted text is not available to AI.');
            $this->page($locator, ['state' => 'truncated', 'method' => $method]);
            throw new ExtractionProblem('text_limit', 'The text limit was reached. Select fewer pages/rows or use manual entry.');
        }
        $this->characters += mb_strlen($text);
        $blocks = $this->run->blocks ?? [];
        $blocks[] = ['id' => 'd'.$this->run->document_id.'-r'.$this->run->id.'-b'.(count($blocks) + 1), 'locator' => $locator, 'text' => $text, 'method' => $method, 'metadata' => $metadata, 'warnings' => $warnings];
        $this->run->update(['blocks' => $blocks]);
    }

    private function page(string $locator, array $details): void
    {
        $pages = $this->run->pages ?? [];
        $pages = array_values(array_filter($pages, fn (array $page): bool => $page['locator'] !== $locator));
        $pages[] = ['locator' => $locator, ...$details];
        $this->run->update(['pages' => $pages]);
    }

    private function usable(string $text): bool
    {
        return preg_match_all('/\pL/u', $text) >= 30 && str_word_count($text) >= 5 && substr_count($text, '�') / max(1, mb_strlen($text)) < 0.03;
    }

    private function pdf(string $path): void
    {
        foreach (['pdfinfo', 'pdftotext'] as $name) {
            $this->tools->requireTool($name, $this->run->configuration);
        }
        $info = $this->tools->execute([$this->limits['pdfinfo'], $path]);
        if (preg_match('/^Encrypted:\s+yes/mi', $info)) {
            throw new ExtractionProblem('encrypted', 'Password-protected PDFs require an unlocked copy or manual entry.');
        }
        if (! preg_match('/^Pages:\s+(\d+)/mi', $info, $match)) {
            throw new ExtractionProblem('corrupt_pdf', 'The PDF page count could not be read. Use a valid copy or manual entry.');
        }
        $total = (int) $match[1];
        $this->run->update(['total_pages' => $total]);
        $requested = $this->run->selection['pages'] ?: range(1, min($total, $this->limits['pages']));
        if (count($requested) > $this->limits['pages'] || max($requested) > $total) {
            throw new ExtractionProblem('page_range', 'The selected PDF pages exceed the document or processing limit.');
        }
        $omitted = $total - count($requested);
        if ($omitted > 0) {
            $this->warning($omitted.' PDF pages were not selected. This run represents only the selected pages.');
            $this->page('unselected-pages', ['state' => 'skipped', 'count' => $omitted]);
        }
        foreach ($requested as $number) {
            $locator = 'page-'.$number;
            try {
                $textFile = $this->work.'/page-'.$number.'.txt';
                $this->tools->execute([$this->limits['pdftotext'], '-f', (string) $number, '-l', (string) $number, '-layout', '-enc', 'UTF-8', $path, $textFile]);
                if (filesize($textFile) > $this->limits['text_chars'] * 4) {
                    throw new ExtractionProblem('text_limit', 'This page exceeds the text limit; select another source or enter it manually.');
                }
                $text = file_get_contents($textFile);
                $ocr = ! $this->usable($text) || in_array($number, $this->run->selection['ocr_pages'], true);
                $preview = null;
                if ($ocr || $this->run->configuration['tools']['pdftoppm'] !== 'unavailable') {
                    $this->tools->requireTool('pdftoppm', $this->run->configuration);
                    $prefix = $this->work.'/render-'.$number;
                    $scale = min(2600, (int) floor(sqrt($this->limits['pixels'])));
                    try {
                        $this->tools->execute([$this->limits['pdftoppm'], '-f', (string) $number, '-l', (string) $number, '-singlefile', '-scale-to', (string) $scale, '-png', $path, $prefix]);
                        $image = $prefix.'.png';
                        $preview = $this->keepImage($image, $number);
                    } catch (ExtractionProblem $exception) {
                        if ($ocr) {
                            throw $exception;
                        }
                        $this->warning('Page '.$number.': digital text retained; rendered preview unavailable.');
                    }
                }
                if ($ocr) {
                    $this->ocr($image, $locator, $number);
                    $preview = $this->keepImage($image, $number);
                    $method = 'tesseract';
                } else {
                    $warnings = preg_match('/\S\s{3,}\S/', $text) ? ['Column/table layout detected. Check row relationships against the page; text order is not proof of table meaning.'] : [];
                    if ($warnings) {
                        $this->warning('Page '.$number.': '.$warnings[0]);
                    }
                    $this->block($locator, $text, 'poppler-layout', ['page' => $number], $warnings);
                    $method = 'poppler-layout';
                }
                $this->page($locator, ['state' => 'extracted', 'page' => $number, 'method' => $method, 'image' => $preview]);
            } catch (ExtractionProblem $exception) {
                $this->page($locator, ['state' => $exception->unavailable ? 'unavailable' : 'failed', 'page' => $number, 'error' => $exception->getMessage()]);
                $this->warning('Page '.$number.': '.$exception->getMessage());
            }
        }
    }

    private function keepImage(string $path, int $page): string
    {
        $dimensions = @getimagesize($path);
        if (! $dimensions || $this->limits['pixels'] < $dimensions[0] * $dimensions[1]) {
            throw new ExtractionProblem('pixel_limit', 'The image exceeds the configured pixel limit. Provide a smaller image or enter the details manually.');
        }
        $relative = $this->base().'/page-'.$page.'.png';
        Storage::disk('extraction')->put($relative, file_get_contents($path));

        return $relative;
    }

    private function image(string $path): void
    {
        $dimensions = @getimagesize($path);
        if (! $dimensions || $this->limits['pixels'] < $dimensions[0] * $dimensions[1]) {
            throw new ExtractionProblem('pixel_limit', 'This image is invalid or exceeds the pixel limit. Use a smaller copy or manual entry.');
        }
        $image = imagecreatefromstring(file_get_contents($path));
        if (! $image) {
            throw new ExtractionProblem('image_decode', 'The image cannot be read safely.');
        }
        if (function_exists('exif_read_data') && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true)) {
            $orientation = @exif_read_data($path)['Orientation'] ?? 1;
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            }
            $rotation = match ($orientation) {
                3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0
            };
            if ($rotation) {
                $image = imagerotate($image, $rotation, 0xFFFFFF);
            }
        }
        $normalized = $this->work.'/normalized.png';
        imagepng($image, $normalized);
        $this->run->update(['total_pages' => 1]);
        $this->ocr($normalized, 'page-1', 1);
        $this->page('page-1', ['state' => 'extracted', 'page' => 1, 'method' => 'tesseract', 'image' => $this->keepImage($normalized, 1)]);
    }

    private function ocr(string $image, string $locator, int $page): void
    {
        $this->tools->requireTool('tesseract', $this->run->configuration);
        $extra = $this->tools->ocrArguments($this->run->configuration);
        $orientation = ['method' => 'OSD unavailable; retained orientation', 'rotation' => 0];
        try {
            $osd = $this->tools->execute([$this->limits['tesseract'], $image, 'stdout', ...$extra, '-l', 'osd', '--psm', '0']);
            if (preg_match('/Rotate:\s+(90|180|270)/', $osd, $match) && preg_match('/Orientation confidence:\s+([\d.]+)/', $osd, $score) && (float) $score[1] >= 5) {
                $resource = imagecreatefrompng($image);
                $rotated = imagerotate($resource, -(int) $match[1], 0xFFFFFF);
                imagepng($rotated, $image);
                $orientation = ['method' => 'Tesseract OSD', 'rotation' => (int) $match[1], 'engine_indicator' => $score[1]];
            } else {
                $orientation = ['method' => 'Tesseract OSD; retained orientation', 'rotation' => 0];
            }
        } catch (ExtractionProblem $exception) {
            $this->warning('Page '.$page.': orientation detection could not resolve direction; inspect the preview manually.');
        }
        $prefix = $this->work.'/ocr-'.$page;
        $this->tools->execute([$this->limits['tesseract'], $image, $prefix, ...$extra, '-l', $this->limits['languages'], '--psm', '3', 'txt', 'tsv']);
        if (! is_file($prefix.'.txt') || filesize($prefix.'.txt') > $this->limits['text_chars'] * 4 || filesize($prefix.'.tsv') > 8000000) {
            throw new ExtractionProblem('ocr_limit', 'OCR output is absent or exceeds the configured limit.');
        }
        $handle = fopen($prefix.'.tsv', 'rb');
        $head = fgetcsv($handle, 0, "\t", '"', '');
        $lines = [];
        $words = 0;
        while (($values = fgetcsv($handle, 0, "\t", '"', '')) !== false) {
            if (count($values) !== count($head)) {
                continue;
            }
            $word = array_combine($head, $values);
            if ($word['level'] !== '5' || trim($word['text']) === '') {
                continue;
            }
            if (++$words > 20000) {
                fclose($handle);
                throw new ExtractionProblem('ocr_word_limit', 'OCR word limits were reached; select fewer pages.');
            }
            $key = $word['block_num'].'-'.$word['par_num'].'-'.$word['line_num'];
            $lines[$key][] = ['text' => $word['text'], 'left' => (int) $word['left'], 'top' => (int) $word['top'], 'width' => (int) $word['width'], 'height' => (int) $word['height'], 'engine_indicator' => $word['conf']];
        }
        fclose($handle);
        $warning = 'OCR text requires visual verification. Engine scores are not probabilities of business correctness.';
        $this->warning('Page '.$page.': '.$warning);
        foreach ($lines as $line => $items) {
            $this->block($locator.'-ocr-'.$line, implode(' ', array_column($items, 'text')), 'tesseract', ['page' => $page, 'words' => $items, 'orientation' => $orientation], [$warning]);
        }
    }

    private function archive(string $path): ZipArchive
    {
        if (! class_exists(ZipArchive::class)) {
            throw new ExtractionProblem('missing_zip', 'The PHP ZIP extension is unavailable. Ask Admin to enable it or enter details manually.', true);
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true || $zip->numFiles > $this->limits['archive_entries']) {
            throw new ExtractionProblem('archive_limit', 'The Office file is damaged or exceeds archive limits.');
        }
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $total += $stat['size'];
            if ($stat['size'] > $this->limits['entry_bytes'] || $total > $this->limits['archive_bytes'] || $this->limits['archive_ratio'] < $stat['size'] / max(1, $stat['comp_size']) || preg_match('#(?:vbaProject|activeX|embeddings)#i', $stat['name'])) {
                $zip->close();
                throw new ExtractionProblem('archive_limit', 'The Office archive exceeds expansion limits or contains unsupported active/embedded content.');
            }
        }

        return $zip;
    }

    private function xml(string $text): DOMDocument
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $text)) {
            throw new ExtractionProblem('unsafe_xml', 'External/entity XML declarations are not supported. Use a clean Office file or manual entry.');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($text, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new ExtractionProblem('invalid_xml', 'This Office source has invalid XML. Use a clean copy or manual entry.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function docx(string $path): void
    {
        $zip = $this->archive($path);
        try {
            $xml = $zip->getFromName('word/document.xml');
            if ($xml === false) {
                throw new ExtractionProblem('invalid_docx', 'The Word document body is missing.');
            }
            $document = $this->xml($xml);
            $query = new DOMXPath($document);
            $query->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $paragraph = 0;
            $table = 0;
            $blocks = 0;
            $totalRows = 0;
            $totalCells = 0;
            foreach ($query->query('/w:document/w:body/*') as $node) {
                if (++$blocks > $this->limits['rows']) {
                    $this->page('remaining-blocks', ['state' => 'skipped']);
                    $this->warning('Word block limit reached; remaining paragraphs/tables were not processed.');
                    break;
                }
                if ($node->localName === 'p') {
                    $texts = [];
                    foreach ($query->query('.//w:t', $node) as $text) {
                        $texts[] = $text->textContent;
                    }
                    $this->block('paragraph-'.(++$paragraph), implode('', $texts), 'docx-xml');
                } elseif ($node->localName === 'tbl') {
                    $table++;
                    $row = 0;
                    foreach ($query->query('./w:tr', $node) as $rowNode) {
                        if (++$row > $this->limits['rows'] || ++$totalRows > $this->limits['rows']) {
                            $this->page('table-'.$table.'-remaining-rows', ['state' => 'skipped']);
                            $this->warning('Word table row limit reached.');
                            break;
                        }
                        $cellNodes = $query->query('./w:tc', $rowNode);
                        if ($totalCells + $cellNodes->length > $this->limits['cells']) {
                            $this->page('unprocessed-table-cells', ['state' => 'skipped']);
                            $this->warning('Word total table cell limit reached; remaining content was not processed.');
                            break 2;
                        }
                        $totalCells += $cellNodes->length;
                        $cells = [];
                        foreach ($cellNodes as $cell) {
                            $pieces = [];
                            foreach ($query->query('.//w:t', $cell) as $text) {
                                $pieces[] = $text->textContent;
                            }
                            $cells[] = implode(' ', $pieces);
                        }
                        $this->block('table-'.$table.'-row-'.$row, implode(' | ', $cells), 'docx-table', ['table' => $table, 'row' => $row, 'cells' => $cells], ['Check column headings and merged cells in the original.']);
                    }
                }
            }
            if ($query->query('//w:drawing|//w:pict|//w:object|//w:altChunk|//w:tbl//w:tbl|//w:vMerge|//w:gridSpan')->length) {
                $this->warning('Drawings, embedded/alternate content, merged or nested tables are not interpreted. Inspect the original manually.');
            }
            $this->warning('Word headers/footers, comments, footnotes, text boxes and layout are not interpreted; only body paragraphs and direct table rows are extracted.');
        } finally {
            $zip->close();
        }
    }

    private function xlsx(string $path): void
    {
        $zip = $this->archive($path);
        $reader = new Xlsx;
        $info = $reader->listWorksheetInfo($path);
        $selected = array_slice(array_column($info, 'worksheetName'), 0, $this->limits['sheets']);
        try {
            $rawCells = $this->xlsxRawCells($zip, $selected);
        } finally {
            $zip->close();
        }
        $limits = $this->limits;
        $filter = new class($limits) implements IReadFilter
        {
            private int $count = 0;

            public function __construct(private array $limits) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->limits['rows'] && Coordinate::columnIndexFromString($columnAddress) <= 100 && ++$this->count <= $this->limits['cells'];
            }
        };
        $reader->setReadFilter($filter)->setLoadSheetsOnly($selected)->setReadDataOnly(false)->setIncludeCharts(false);
        $book = $reader->load($path);
        try {
            $count = 0;
            foreach ($book->getWorksheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                    if (++$count > $this->limits['cells']) {
                        break 2;
                    }
                    $cell = $sheet->getCell($coordinate);
                    $value = $cell->getValue();
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $formula = $cell->getDataType() === 'f';
                    $cached = $formula ? $cell->getOldCalculatedValue() : null;
                    if ($cached === '') {
                        $cached = null;
                    }
                    $lexeme = $rawCells[$sheet->getTitle()][$coordinate] ?? null;
                    $raw = ! $formula && $lexeme !== null ? $lexeme : (string) $value;
                    if ($formula && array_key_exists($coordinate, $rawCells[$sheet->getTitle()] ?? [])) {
                        $cached = $lexeme;
                    }
                    $rows[$cell->getRow()][] = ['cell' => $coordinate, 'raw' => $raw, 'formula' => $formula, 'cached' => $cached === null ? null : (string) $cached, 'type' => $cell->getDataType(), 'number_format' => $cell->getStyle()->getNumberFormat()->getFormatCode()];
                }
                foreach ($rows as $row => $cells) {
                    $warnings = array_filter($cells, fn (array $cell): bool => $cell['formula']) ? ['Formula text and cached values are preserved without calculation. Cached values may be stale; verify manually.'] : [];
                    $this->block('sheet-'.$sheet->getTitle().'-row-'.$row, implode(' | ', array_map(fn (array $cell): string => $cell['cell'].': '.$cell['raw'].($cell['formula'] ? ' [cached: '.($cell['cached'] ?? 'absent').']' : ''), $cells)), 'xlsx-cells', ['sheet' => $sheet->getTitle(), 'row' => $row, 'cells' => $cells], $warnings);
                }
            }
            $totalCells = array_sum(array_map(fn (array $sheet): int => $sheet['totalRows'] * $sheet['totalColumns'], $info));
            if (count($info) > $this->limits['sheets'] || collect($info)->contains(fn (array $sheet): bool => $sheet['totalRows'] > $this->limits['rows'] || $sheet['totalColumns'] > 100) || $totalCells > $this->limits['cells']) {
                $this->page('unprocessed-cells', ['state' => 'skipped']);
                $this->warning('Spreadsheet selection is bounded to '.$this->limits['sheets'].' sheets, '.$this->limits['rows'].' rows per sheet, 100 columns and '.$this->limits['cells'].' cells. Additional cells are not processed.');
            }
            $this->warning('Spreadsheet formulas are never evaluated; drawings, merged-layout meaning, hidden content and external connections are not interpreted or refreshed. Check units/number formats against the original.');
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function xlsxRawCells(ZipArchive $zip, array $selected): array
    {
        $workbook = $this->xml($zip->getFromName('xl/workbook.xml') ?: '');
        $relations = $this->xml($zip->getFromName('xl/_rels/workbook.xml.rels') ?: '');
        $targets = [];
        foreach ($relations->getElementsByTagName('Relationship') as $relationship) {
            if ($relationship->getAttribute('TargetMode') !== 'External') {
                $targets[$relationship->getAttribute('Id')] = $relationship->getAttribute('Target');
            }
        }
        $values = [];
        $count = 0;
        foreach ($workbook->getElementsByTagName('sheet') as $sheet) {
            $name = $sheet->getAttribute('name');
            if (! in_array($name, $selected, true)) {
                continue;
            }
            $target = $targets[$sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')] ?? '';
            $entry = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            if (! preg_match('#^xl/worksheets/[^/]+\\.xml$#', $entry)) {
                continue;
            }
            $xml = $zip->getFromName($entry);
            if ($xml === false) {
                continue;
            }
            $document = $this->xml($xml);
            $query = new DOMXPath($document);
            $query->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($query->query('//m:sheetData/m:row/m:c') as $cell) {
                $coordinate = $cell->getAttribute('r');
                if (! preg_match('/^([A-Z]{1,3})([1-9]\\d*)$/', $coordinate, $match) || (int) $match[2] > $this->limits['rows'] || Coordinate::columnIndexFromString($match[1]) > 100) {
                    continue;
                }
                if (++$count > $this->limits['cells']) {
                    return $values;
                }
                if (in_array($cell->getAttribute('t'), ['', 'n'], true) || $query->query('./m:f', $cell)->length) {
                    $value = $query->query('./m:v', $cell)->item(0)?->textContent;
                    $values[$name][$coordinate] = $value === '' ? null : $value;
                }
            }
        }

        return $values;
    }

    private function csv(string $path): void
    {
        $contents = file_get_contents($path);
        $encoding = mb_detect_encoding($contents, ['UTF-8', 'UTF-16LE', 'UTF-16BE', 'Windows-1252'], true) ?: 'Windows-1252';
        if (str_starts_with($contents, "\xFF\xFE")) {
            $encoding = 'UTF-16LE';
        } elseif (str_starts_with($contents, "\xFE\xFF")) {
            $encoding = 'UTF-16BE';
        }
        $contents = mb_convert_encoding($contents, 'UTF-8', $encoding);
        $contents = preg_replace('/^\x{FEFF}/u', '', $contents);
        $sample = strtok($contents, "\n") ?: '';
        $delimiter = ',';
        $max = 0;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $fields = count(str_getcsv($sample, $candidate, '"', ''));
            if ($fields > $max) {
                $max = $fields;
                $delimiter = $candidate;
            }
        }
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);
        $row = 0;
        $count = 0;
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if (++$row > $this->limits['rows'] || $count + count($cells) > $this->limits['cells']) {
                $this->page('unprocessed-rows', ['state' => 'skipped']);
                $this->warning('CSV row/cell limit reached. Remaining rows were not processed.');
                break;
            }
            $count += count($cells);
            $texts = [];
            $metadata = [];
            $warnings = [];
            foreach ($cells as $index => $value) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $value = (string) $value;
                $formula = preg_match('/^[=+@-]/', ltrim($value)) === 1;
                $texts[] = $column.$row.': '.$value;
                $metadata[] = ['cell' => $column.$row, 'raw' => $value, 'formula_like' => $formula];
                if ($formula) {
                    $warnings[] = 'Formula-like CSV values are text only; no expressions are executed.';
                }
            }
            $this->block('row-'.$row, implode(' | ', $texts), 'csv', ['row' => $row, 'cells' => $metadata, 'encoding' => $encoding, 'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter], array_values(array_unique($warnings)));
        }
        fclose($stream);
        $this->warning('CSV has no reliable type/unit metadata. Confirm dates, decimal separators, units and repeated headers manually.');
    }
}
