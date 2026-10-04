<?php

namespace Tests\Feature;

use App\Actions\StartDocumentExtraction;
use App\Actions\StoreInquiryDocuments;
use App\Jobs\ExtractDocument;
use App\Models\DocumentRun;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\User;
use App\Support\AiSources;
use App\Support\LocalExtractor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('inquiry_documents');
        Storage::fake('extraction');
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'agent']));
    }

    private function source(string $name, ?Inquiry $inquiry = null): InquiryDocument
    {
        $inquiry ??= Inquiry::factory()->create();
        $path = base_path('tests/Fixtures/extraction/'.$name);
        app(StoreInquiryDocuments::class)->handle($inquiry, [new UploadedFile($path, $name, test: true)], 'packing_list');

        return $inquiry->documents()->firstOrFail();
    }

    private function extract(InquiryDocument $document, array $pages = [], array $ocr = []): DocumentRun
    {
        $run = app(StartDocumentExtraction::class)->handle($document->inquiry, $document, auth()->user(), ['pages' => $pages, 'ocr_pages' => $ocr]);
        $run->update(['state' => 'processing', 'started_at' => now(), 'attempts' => 1]);
        app(LocalExtractor::class)->handle($run);

        return $run->fresh();
    }

    public function test_real_digital_pdf_keeps_page_evidence_and_does_not_use_ocr(): void
    {
        $document = $this->source('digital-packing-list.pdf');
        $before = $document->inquiry->snapshotHash();
        $run = $this->extract($document);
        $this->assertContains($run->state, ['extracted', 'manual_review']);
        $this->assertSame(1, $run->total_pages);
        $this->assertSame('poppler-layout', $run->pages[0]['method']);
        $this->assertStringContainsString('250.5 kg', $run->blocks[0]['text']);
        $this->assertStringContainsString('UNTRUSTED SOURCE', $run->blocks[0]['text']);
        $this->assertSame('page-1', $run->blocks[0]['locator']);
        $this->assertSame($document->checksum, $run->checksum);
        $this->assertSame($before, $document->inquiry->fresh()->snapshotHash());
        $this->assertSame('draft', $document->inquiry->fresh()->status);
        Storage::disk('extraction')->assertExists($run->pages[0]['image']);
        $this->assertSame([], Storage::disk('extraction')->allFiles('inquiries/'.$document->inquiry_id.'/runs/'.$run->id.'/work'));
    }

    public function test_real_mixed_pdf_uses_ocr_only_for_scanned_page_and_keeps_word_locations(): void
    {
        $run = $this->extract($this->source('mixed-packing-list.pdf'));
        $this->assertContains($run->state, ['extracted', 'manual_review'], json_encode([$run->warnings, $run->error_message]));
        $this->assertSame(['poppler-layout', 'tesseract'], array_column($run->pages, 'method'));
        $ocr = collect($run->blocks)->firstWhere('method', 'tesseract');
        $this->assertSame(2, $ocr['metadata']['page']);
        $this->assertArrayHasKey('engine_indicator', $ocr['metadata']['words'][0]);
        $this->assertNotEmpty($ocr['warnings']);
        $this->assertStringContainsString('250.5', implode(' ', array_column($run->blocks, 'text')));
    }

    public function test_real_scanned_pdf_and_jpeg_png_sources_extract_with_ocr(): void
    {
        foreach (['scanned-packing-list.pdf', 'packing-list.jpg', 'packing-list.png'] as $name) {
            $run = $this->extract($this->source($name));
            $this->assertContains($run->state, ['extracted', 'manual_review'], $name.': '.json_encode([$run->warnings, $run->error_message]));
            $this->assertSame('tesseract', $run->pages[0]['method']);
            $this->assertStringContainsString('250.5', implode(' ', array_column($run->blocks, 'text')));
        }
    }

    public function test_real_docx_xlsx_csv_keep_rows_cells_raw_formula_and_ambiguity(): void
    {
        $word = $this->extract($this->source('shipment.docx'));
        $this->assertSame('Cargo: General machine parts', collect($word->blocks)->firstWhere('locator', 'paragraph-2')['text']);
        $this->assertStringContainsString('250.5 kg', collect($word->blocks)->firstWhere('locator', 'table-1-row-2')['text']);
        $sheet = $this->extract($this->source('shipment.xlsx'));
        $this->assertContains($sheet->state, ['extracted', 'manual_review'], $sheet->error_message ?? '');
        $formula = collect($sheet->blocks)->firstWhere('locator', 'sheet-Cargo-row-8');
        $this->assertStringContainsString('=2+3', $formula['text']);
        $this->assertTrue($formula['metadata']['cells'][1]['formula']);
        $this->assertNull($formula['metadata']['cells'][1]['cached']);
        $this->assertSame('0.0000', collect($sheet->blocks)->firstWhere('locator', 'sheet-Cargo-row-3')['metadata']['cells'][1]['number_format']);
        $csv = $this->extract($this->source('shipment.csv'));
        $this->assertStringContainsString('B7: =2+3', collect($csv->blocks)->firstWhere('locator', 'row-7')['text']);
        $this->assertSame(';', $csv->blocks[0]['metadata']['delimiter']);
        $this->assertStringContainsString('1,200', collect($csv->blocks)->firstWhere('locator', 'row-4')['text']);
    }

    public function test_reuse_is_case_scoped_config_sensitive_and_explicit_reprocess_preserves_prior_runs(): void
    {
        $document = $this->source('digital-packing-list.pdf');
        $run = $this->extract($document);
        $action = app(StartDocumentExtraction::class);
        $selection = ['pages' => [], 'ocr_pages' => []];
        $this->assertSame($run->id, $action->handle($document->inquiry, $document, auth()->user(), $selection)->id);
        $retry = $action->handle($document->inquiry, $document, auth()->user(), $selection, true, 'Inspect page again');
        $this->assertNotSame($run->id, $retry->id);
        $this->assertSame(2, $retry->generation);
        $this->assertSame($retry->id, $action->handle($document->inquiry, $document, auth()->user(), $selection, true, 'Duplicate request')->id);
        $another = $this->source('digital-packing-list.pdf');
        $this->assertNotSame($run->id, $action->handle($another->inquiry, $another, auth()->user(), $selection)->id);
        config(['extraction.version' => 'new-extractor-version']);
        $this->assertNotSame($retry->id, $action->handle($document->inquiry, $document, auth()->user(), $selection)->id);
    }

    public function test_missing_ocr_partial_selection_limits_and_corrupt_source_are_honest(): void
    {
        config(['extraction.tesseract' => 'missing-tesseract-binary']);
        $mixed = $this->extract($this->source('mixed-packing-list.pdf'));
        $this->assertSame('partial', $mixed->state);
        $this->assertSame('unavailable', $mixed->pages[1]['state']);
        $this->assertNotEmpty($mixed->blocks);
        $partial = $this->extract($this->source('mixed-packing-list.pdf'), [1]);
        $this->assertSame('partial', $partial->state);
        $this->assertSame(1, $partial->pages[0]['count']);
        $corrupt = $this->extract($this->source('malformed.pdf'));
        $this->assertSame('failed', $corrupt->state);
        $this->assertSame([], $corrupt->blocks);
        $this->assertNotNull($corrupt->error_message);
    }

    public function test_completed_source_evidence_cannot_be_modified(): void
    {
        $document = $this->source('digital-packing-list.pdf');
        $run = $this->extract($document);
        $this->expectException(QueryException::class);
        $run->update(['blocks' => []]);
    }

    public function test_derived_pages_text_and_source_ids_are_private_and_case_scoped(): void
    {
        $document = $this->source('digital-packing-list.pdf');
        $run = $this->extract($document);
        $url = route('inquiries.extraction.page', [$document->inquiry, $run, 1]);
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('inquiries.extraction.page', [Inquiry::factory()->create(), $run, 1]))->assertNotFound();
        $this->get('/inquiries/'.$document->inquiry_id.'/extraction?source=another-case-block')->assertNotFound();
        $sources = AiSources::selected($document->inquiry, [$run->blocks[0]['id']]);
        $this->assertSame($run->id, $sources[0]['lineage']['run_id']);
        $this->assertSame($document->checksum, $sources[0]['lineage']['checksum']);
        $this->assertSame('packing_list', $sources[0]['lineage']['classification']);
        $this->assertTrue(AiSources::current($document->inquiry, $sources));
        $document->update(['classification' => 'goods_invoice']);
        $this->assertFalse(AiSources::current($document->inquiry, $sources));
        auth()->logout();
        $this->get($url)->assertRedirect('/login');
        $this->get('/inquiries/'.$document->inquiry_id.'/extraction')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['is_active' => false]))->get($url)->assertRedirect('/login');
    }

    public function test_checksum_tampering_stops_extraction_and_office_xml_is_not_expanded(): void
    {
        $document = $this->source('shipment.docx');
        Storage::disk('inquiry_documents')->put($document->storage_path, 'Changed original');
        $run = $this->extract($document);
        $this->assertSame('source_changed', $run->error_code);
        $this->assertSame('failed', $run->state);
        $office = $this->source('shipment.docx');
        $path = Storage::disk('inquiry_documents')->path($office->storage_path);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('word/document.xml');
        $zip->addFromString('word/document.xml', '<!DOCTYPE document [<!ENTITY forbidden SYSTEM "https://example.test/not-fetched">]>'.$xml);
        $zip->close();
        $office->update(['checksum' => hash_file('sha256', $path)]);
        $unsafe = $this->extract($office);
        $this->assertSame('unsafe_xml', $unsafe->error_code);
        $this->assertSame([], $unsafe->blocks);
    }

    public function test_resource_limits_report_unprocessed_content_without_silently_completing(): void
    {
        config(['extraction.cells' => 4]);
        $word = $this->extract($this->source('shipment.docx'));
        $this->assertSame('partial', $word->state);
        $this->assertContains('unprocessed-table-cells', array_column($word->pages, 'locator'));
        $csv = $this->extract($this->source('shipment.csv'));
        $this->assertSame('partial', $csv->state);
        $this->assertContains('unprocessed-rows', array_column($csv->pages, 'locator'));
        config(['extraction.pixels' => 100]);
        $image = $this->extract($this->source('packing-list.png'));
        $this->assertSame('pixel_limit', $image->error_code);
        $this->assertSame('failed', $image->state);
        config(['extraction.languages' => 'uninstalled-language']);
        $ocr = $this->extract($this->source('scanned-packing-list.pdf'));
        $this->assertSame('partial', $ocr->state);
        $this->assertSame('unavailable', $ocr->pages[0]['state']);
    }

    public function test_worker_failure_retains_checkpoints_and_recovery_respects_activity(): void
    {
        $document = $this->source('shipment.csv');
        $run = app(StartDocumentExtraction::class)->handle($document->inquiry, $document, auth()->user(), ['pages' => [], 'ocr_pages' => []]);
        $checkpoint = ['id' => 'checkpoint', 'locator' => 'row-1', 'text' => 'Preserved successful row', 'method' => 'csv', 'metadata' => [], 'warnings' => []];
        $run->update(['state' => 'processing', 'blocks' => [$checkpoint]]);
        $base = 'inquiries/'.$run->inquiry_id.'/runs/'.$run->id;
        Storage::disk('extraction')->put($base.'/work/abandoned/source.csv', 'Synthetic temporary copy');
        Storage::disk('extraction')->put($base.'/page-1.png', 'Retained derived evidence');
        Storage::disk('extraction')->put('inquiries/other/runs/other/work/source.csv', 'Another run');
        DB::table('document_runs')->where('id', $run->id)->update(['updated_at' => now()->subMinutes(15)]);
        $this->artisan('lrs:recover-processing', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('processing', $run->fresh()->state);
        $this->artisan('lrs:recover-processing')->assertSuccessful();
        $this->assertSame('partial', $run->fresh()->state);
        $this->assertEquals([$checkpoint], $run->fresh()->blocks);
        $this->assertSame('worker_stopped', $run->fresh()->error_code);
        Storage::disk('extraction')->assertMissing($base.'/work/abandoned/source.csv');
        Storage::disk('extraction')->assertExists($base.'/page-1.png');
        Storage::disk('extraction')->assertExists('inquiries/other/runs/other/work/source.csv');
        (new ExtractDocument($run->id))->failed(null);
        $this->assertEquals([$checkpoint], $run->fresh()->blocks);
    }

    public function test_spreadsheet_numeric_lexemes_and_cached_formulas_are_preserved_without_calculation(): void
    {
        $document = $this->source('shipment.xlsx');
        $path = Storage::disk('inquiry_documents')->path($document->storage_path);
        $zip = new \ZipArchive;
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sheet = preg_replace('/(<c r="B3"[^>]*><v>)[^<]+/', '${1}250.500000000000000001', $sheet);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $document->update(['checksum' => hash_file('sha256', $path)]);
        $run = $this->extract($document);
        $cell = collect($run->blocks)->firstWhere('locator', 'sheet-Cargo-row-3')['metadata']['cells'][1];
        $this->assertSame('250.500000000000000001', $cell['raw']);
        $formula = collect($run->blocks)->firstWhere('locator', 'sheet-Cargo-row-8')['metadata']['cells'][1];
        $this->assertSame('=2+3', $formula['raw']);
        $this->assertNull($formula['cached']);
    }
}
