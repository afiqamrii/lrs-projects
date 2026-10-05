<?php

namespace App\Support;

use App\Models\AiRun;
use Illuminate\Support\Facades\Http;

class OpenAiResponses
{
    public function request(AiRun $run): array
    {
        if (config('operations.restore_lockdown')) {
            throw new \RuntimeException('External AI is disabled during restore verification.');
        }
        $wording = $run->purpose === 'rfq_wording';
        $quotation = $run->purpose === 'vendor_quotation';
        $response = Http::withToken(config('ai.key'))->acceptJson()->connectTimeout(10)->timeout(config('ai.timeout'))->withOptions(['allow_redirects' => false])->post('https://api.openai.com/v1/responses', [
            'model' => $run->model, 'store' => false, 'max_output_tokens' => (int) $run->settings['max_output_tokens'],
            'input' => [['role' => 'system', 'content' => ($quotation ? OfferProposal::prompt() : ($wording ? RfqWording::prompt() : ProposalSchema::prompt()))], ['role' => 'user', 'content' => ($quotation ? OfferProposal::input($run->sources) : ($wording ? RfqWording::input($run->sources) : AiSources::input($run->sources)))]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => $quotation ? 'vendor_quotation_v1' : ($wording ? 'rfq_wording_v1' : 'shipment_proposals_v1'), 'strict' => true, 'schema' => ($quotation ? OfferProposal::schema() : ($wording ? RfqWording::schema() : ProposalSchema::schema()))]],
            'tools' => [],
        ]);
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $metadata = [
            'provider_request_id' => mb_substr((string) $response->header('x-request-id'), 0, 255) ?: null,
            'provider_response_id' => is_string($payload['id'] ?? null) ? mb_substr($payload['id'], 0, 255) : null,
            'provider_model' => is_string($payload['model'] ?? null) ? mb_substr($payload['model'], 0, 255) : null,
            'provider_status' => is_string($payload['status'] ?? null) ? mb_substr($payload['status'], 0, 255) : 'http_'.$response->status(),
        ];
        if (! $response->successful()) {
            return ['metadata' => $metadata, 'usage' => $payload['usage'] ?? null, 'error' => $response->status() === 429 ? 'rate_limited' : 'provider_failed', 'result' => null];
        }
        if (($payload['status'] ?? null) !== 'completed') {
            return ['metadata' => $metadata, 'usage' => $payload['usage'] ?? null, 'error' => 'incomplete', 'result' => null];
        }
        $texts = [];
        foreach ($payload['output'] ?? [] as $message) {
            if (($message['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ($message['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    return ['metadata' => $metadata, 'usage' => $payload['usage'] ?? null, 'error' => 'refused', 'result' => null];
                }
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    $texts[] = $content['text'];
                }
            }
        }
        try {
            if (count($texts) !== 1 || strlen($texts[0]) > 262144) {
                throw new \UnexpectedValueException;
            }
            $result = json_decode($texts[0], true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($result)) {
                throw new \UnexpectedValueException;
            }
            if ($quotation) {
                OfferProposal::validate($result);
            } elseif ($wording) {
                RfqWording::validate($result);
            } else {
                ProposalSchema::validate($result);
            }
        } catch (\Throwable $exception) {
            return ['metadata' => $metadata, 'usage' => $payload['usage'] ?? null, 'error' => 'invalid_output', 'result' => null];
        }

        return ['metadata' => $metadata, 'usage' => $payload['usage'] ?? null, 'error' => null, 'result' => $result];
    }

    public function checkModel(string $model): bool
    {
        if (config('operations.restore_lockdown')) {
            return false;
        }

        return Http::withToken(config('ai.key'))->acceptJson()->connectTimeout(10)->timeout(15)->withOptions(['allow_redirects' => false])->get('https://api.openai.com/v1/models/'.rawurlencode($model))->successful();
    }
}
