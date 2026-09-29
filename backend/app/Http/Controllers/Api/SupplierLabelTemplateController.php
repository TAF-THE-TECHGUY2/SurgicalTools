<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockItemResource;
use App\Models\StockItem;
use App\Models\SupplierLabelTemplate;
use App\Services\ScanExtractionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Supplier label templates (spec §3.2): per-supplier rules for reading a
 * label, managed by an admin so a new supplier needs no code release.
 * Anyone who scans may list them — the scanner offers them as a choice.
 */
class SupplierLabelTemplateController extends Controller
{
    public function __construct(protected ScanExtractionService $extraction) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->can('stock_count.scan'), 403);

        return response()->json([
            'data' => SupplierLabelTemplate::query()
                ->when(! $user->isAdmin() || ! $request->boolean('include_inactive'), fn ($q) => $q->active())
                ->orderBy('supplier')->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $template = SupplierLabelTemplate::create($this->validated($request));

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, SupplierLabelTemplate $labelTemplate)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $labelTemplate->update($this->validated($request));

        return response()->json(['data' => $labelTemplate->fresh()]);
    }

    public function destroy(Request $request, SupplierLabelTemplate $labelTemplate)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $labelTemplate->delete();

        return response()->noContent();
    }

    /**
     * Try a template on a barcode before relying on it: a saved template by
     * id, or the unsaved one being edited. Nothing is stored.
     */
    public function test(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $request->validate([
            'barcode'     => ['required', 'string', 'max:512'],
            'template_id' => ['nullable', 'integer', 'exists:supplier_label_templates,id'],
            'template'    => ['nullable', 'array'],
        ]);

        $template = match (true) {
            $request->filled('template_id') => SupplierLabelTemplate::find($request->integer('template_id')),
            $request->filled('template')    => new SupplierLabelTemplate($this->validated(
                Request::create('', 'POST', (array) $request->input('template')),
            )),
            default => null,
        };

        try {
            $extracted = $this->extraction->readBarcode($request->string('barcode')->toString(), $template);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['barcode' => $e->getMessage()]);
        }

        $item = StockItem::resolveFromScan($extracted);

        return response()->json([
            'extracted'        => $extracted,
            'matched_template' => $template?->exists ? $template->only(['id', 'supplier', 'name']) : null,
            'pattern_matches'  => $template ? $template->matches($request->string('barcode')->toString()) : null,
            'stock_item'       => $item ? new StockItemResource($item) : null,
        ]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'supplier'       => ['required', 'string', 'max:100'],
            'name'           => ['required', 'string', 'max:150'],
            'barcode_type'   => ['nullable', Rule::in(['gs1', 'code128', 'datamatrix', 'none'])],
            'match_pattern'  => ['nullable', 'string', 'max:500'],
            'field_mappings' => ['nullable', 'array'],
            'field_mappings.*.source'      => ['nullable', Rule::in(['ai', 'raw'])],
            'field_mappings.*.ai'          => ['nullable', 'regex:/^\d{2,4}$/'],
            'field_mappings.*.pattern'     => ['nullable', 'string', 'max:500'],
            'field_mappings.*.date_format' => ['nullable', Rule::in(SupplierLabelTemplate::DATE_FORMATS)],
            'ocr_hints'      => ['nullable', 'string', 'max:2000'],
            'is_active'      => ['boolean'],
        ]);

        $errors = [];

        if (filled($data['match_pattern'] ?? null) && $e = SupplierLabelTemplate::patternError($data['match_pattern'])) {
            $errors['match_pattern'] = "Not a valid pattern: {$e}";
        }

        foreach ($data['field_mappings'] ?? [] as $field => $mapping) {
            if (! in_array($field, SupplierLabelTemplate::FIELDS, true)) {
                $errors["field_mappings.{$field}"] = "Unknown field '{$field}'. Use one of: ".implode(', ', SupplierLabelTemplate::FIELDS).'.';

                continue;
            }
            if (($mapping['source'] ?? 'ai') !== 'raw' && blank($mapping['ai'] ?? null)) {
                $errors["field_mappings.{$field}.ai"] = "Say which GS1 AI {$field} comes from, or read it from the raw text.";
            }
            if (filled($mapping['pattern'] ?? null) && $e = SupplierLabelTemplate::patternError($mapping['pattern'])) {
                $errors["field_mappings.{$field}.pattern"] = "Not a valid pattern: {$e}";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }
}
