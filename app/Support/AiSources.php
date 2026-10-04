<?php

namespace App\Support;

use App\Models\DocumentRun;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Storage;

class AiSources
{
    public static function catalogue(Inquiry $inquiry): array
    {
        $blocks = [];
        if ($inquiry->original_source_text) {
            $blocks[] = ['id' => 'original-'.$inquiry->id, 'locator' => 'Original manual source', 'text' => $inquiry->original_source_text, 'method' => 'preserved-source', 'metadata' => [], 'warnings' => [], 'lineage' => ['kind' => 'manual', 'checksum' => hash('sha256', $inquiry->original_source_text)], 'label' => 'Original request'];
        }
        if ($original = $inquiry->publicSubmission) {
            $snapshot = $original->snapshot;
            $data = ['shipment' => $snapshot['shipment'] ?? [], 'notes' => $snapshot['additional_notes'] ?? null];
            $text = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $blocks[] = ['id' => 'website-'.$original->id, 'locator' => 'Original website shipment and notes', 'text' => $text, 'method' => 'preserved-website', 'metadata' => [], 'warnings' => ['Website values are submitted evidence, not verified identity.'], 'lineage' => ['kind' => 'website', 'submission_id' => $original->id, 'checksum' => hash('sha256', $text)], 'label' => 'Original website submission'];
        }
        $runs = DocumentRun::where('inquiry_id', $inquiry->id)->whereIn('state', Processing::DOCUMENT_SUCCESS)->with('document')->latest('id')->get();
        foreach ($runs as $run) {
            if ($run->document->is_archived) {
                continue;
            }
            foreach ($run->blocks as $block) {
                $blocks[] = [...$block, 'label' => $run->document->original_name.' · '.$block['locator'], 'lineage' => ['kind' => 'document', 'run_id' => $run->id, 'document_id' => $run->document_id, 'checksum' => $run->checksum, 'classification' => $run->document->classification, 'configuration_hash' => Processing::hash($run->configuration)], 'warnings' => array_values(array_unique([...$block['warnings'], ...$run->warnings]))];
            }
        }

        return $blocks;
    }

    public static function selected(Inquiry $inquiry, array $ids): array
    {
        $catalogue = collect(self::catalogue($inquiry))->keyBy('id');
        $sources = [];
        foreach (array_unique($ids) as $id) {
            if (! $catalogue->has($id)) {
                Processing::fail('A selected passage is no longer available. Reload the source selection.');
            }
            $source = $catalogue[$id];
            unset($source['metadata']['words']);
            $sources[] = $source;
        }
        if ($sources === [] || count($sources) > 200) {
            Processing::fail('Select between 1 and 200 source passages.');
        }
        usort($sources, fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        if (! self::current($inquiry, $sources)) {
            Processing::fail('The selected evidence changed. Restore/reprocess the source and compare it before continuing.');
        }
        if (mb_strlen(self::input($sources)) > config('ai.input_chars')) {
            Processing::fail('The selected input exceeds the AI text limit. Deliberately select fewer pages or passages. Nothing has been sent.');
        }

        return $sources;
    }

    public static function input(array $sources): string
    {
        return json_encode(array_map(fn (array $source): array => ['id' => $source['id'], 'locator' => $source['locator'], 'classification' => $source['lineage']['classification'] ?? $source['lineage']['kind'], 'method' => $source['method'], 'text' => $source['text'], 'warnings' => $source['warnings']], $sources), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function current(Inquiry $inquiry, array $sources): bool
    {
        $checked = [];
        foreach ($sources as $source) {
            $lineage = $source['lineage'];
            if ($lineage['kind'] === 'manual') {
                if (! hash_equals($lineage['checksum'], hash('sha256', $inquiry->original_source_text ?? ''))) {
                    return false;
                }
            } elseif ($lineage['kind'] === 'website') {
                $original = $inquiry->publicSubmission;
                $text = $original ? json_encode(['shipment' => $original->snapshot['shipment'] ?? [], 'notes' => $original->snapshot['additional_notes'] ?? null], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '';
                if (! $original || $original->id !== $lineage['submission_id'] || ! hash_equals($lineage['checksum'], hash('sha256', $text))) {
                    return false;
                }
            } elseif (! isset($checked[$lineage['document_id']])) {
                $document = $inquiry->documents()->whereKey($lineage['document_id'])->first();
                if (! $document || $document->is_archived || $document->classification !== $lineage['classification'] || ! hash_equals($lineage['checksum'], $document->checksum)) {
                    return false;
                }
                $path = Storage::disk('inquiry_documents')->path($document->storage_path);
                if (! is_file($path) || ! hash_equals($lineage['checksum'], hash_file('sha256', $path))) {
                    return false;
                }
                $checked[$document->id] = true;
            }
        }

        return true;
    }
}
