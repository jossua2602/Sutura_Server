<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\ReceiptFiles;
use App\Services\ReceiptLedger;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * "Statements": every payment record and its receipt screenshot, filterable by period, with single-file
 * download (any plan) and bulk CSV / ZIP export (Premium: "Sales Reports & Income Exports"). Owner and branch
 * manager only; a manager only ever sees their own branch.
 */
class ReceiptExportController extends Controller
{
    private const MAX_EXPORT = 2000;

    private function branchScope(Request $request): ?int
    {
        $user = $request->user();
        if (! $user->hasRole('store_owner') && $user->staffProfile?->store_branch_id) {
            return (int) $user->staffProfile->store_branch_id;
        }

        return $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
    }

    private function filters(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'kinds' => ['nullable', 'array'],
            'kinds.*' => ['in:job,appointment,catalog'],
            'keys' => ['nullable', 'array', 'max:'.self::MAX_EXPORT],
            'keys.*' => ['string', 'max:40', 'regex:/^(job|appointment|catalog):\d+$/'],
        ]);

        return [
            $request->input('from'),
            $request->input('to'),
            $this->branchScope($request),
            $request->input('kinds', array_keys(ReceiptLedger::KINDS)),
            $request->has('keys') ? $request->input('keys') : null,
        ];
    }

    private function present(array $row): array
    {
        $located = $row['receipt_path'] ? ReceiptFiles::locate($row['receipt_path']) : null;

        return [
            'key' => $row['key'], 'kind' => $row['kind'], 'kind_label' => ReceiptLedger::KINDS[$row['kind']],
            'date' => $row['date']?->toIso8601String(),
            'doc_no' => $row['doc_no'], 'customer' => $row['customer'], 'method' => $row['method'],
            'payment_type' => $row['payment_type'], 'source' => $row['source'], 'amount' => $row['amount'],
            'status' => $row['status'], 'reference' => $row['reference'], 'branch' => $row['branch'],
            'has_receipt' => (bool) $row['receipt_path'],
            'receipt_available' => (bool) $located,
        ];
    }

    /** List + totals for the Statements screen. */
    public function index(Request $request, Store $store): JsonResponse
    {
        [$from, $to, $branchId, $kinds] = $this->filters($request);
        $rows = ReceiptLedger::entries($store, $from, $to, $branchId, $kinds);
        $counted = $rows->where('status', '!=', 'rejected');

        return response()->json(['success' => true, 'data' => [
            'first_record_date' => ReceiptLedger::firstRecordDate($store, $branchId),
            'totals' => [
                'records' => $rows->count(),
                'with_receipt' => $rows->filter(fn ($r) => $r['receipt_path'])->count(),
                'amount' => round((float) $counted->sum(fn ($r) => (float) ($r['amount'] ?? 0)), 2),
                'verified_amount' => round((float) $rows->where('status', 'verified')->sum(fn ($r) => (float) ($r['amount'] ?? 0)), 2),
            ],
            'plan_allows_export' => PlanGate::allows($store, 'premium'),
            'entries' => $rows->take(500)->map(fn ($r) => $this->present($r))->values(),
            'truncated' => $rows->count() > 500,
        ]]);
    }

    /** One receipt image, as a download. Any plan. */
    public function file(Request $request, Store $store): Response
    {
        $request->validate(['key' => ['required', 'string', 'regex:/^(job|appointment|catalog):\d+$/']]);
        $row = ReceiptLedger::entries($store, null, null, $this->branchScope($request), array_keys(ReceiptLedger::KINDS), [$request->input('key')])->first();
        $located = $row && $row['receipt_path'] ? ReceiptFiles::locate($row['receipt_path']) : null;
        abort_unless($located, 404, 'No receipt image is stored for this record.');

        $name = $this->fileName($row, $located['ext']);
        if (isset($located['absolute'])) {
            return response()->download($located['absolute'], $name);
        }

        return response($located['contents'], 200, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    /**
     * The shop's own logo bytes, for the branded Excel exports. Uploaded files sit outside the API's CORS
     * paths, so the browser can't read them directly to embed them; it reads them through here instead.
     * 404 when the shop has no logo (the export then uses the SUTURA logo).
     */
    public function logo(Store $store): Response
    {
        $located = ReceiptFiles::locate($store->logo_path);
        abort_unless($located && in_array($located['ext'], ['png', 'jpg', 'jpeg', 'webp', 'gif'], true), 404, 'This shop has no logo.');
        $type = $located['ext'] === 'jpg' ? 'image/jpeg' : 'image/'.$located['ext'];

        return isset($located['absolute'])
            ? response()->file($located['absolute'], ['Content-Type' => $type, 'Cache-Control' => 'private, max-age=3600'])
            : response($located['contents'], 200, ['Content-Type' => $type, 'Cache-Control' => 'private, max-age=3600']);
    }

    /** Bulk download. csv = the statement; zip = statement + every receipt image. Premium. */
    public function export(Request $request, Store $store): Response
    {
        if ($denied = PlanGate::require($store, 'premium', 'Exporting receipts and statements')) {
            return $denied;
        }
        $request->validate(['format' => ['required', 'in:csv,zip']]);
        [$from, $to, $branchId, $kinds, $keys] = $this->filters($request);
        $rows = ReceiptLedger::entries($store, $from, $to, $branchId, $kinds, $keys);
        if ($rows->count() > self::MAX_EXPORT) {
            return response()->json(['success' => false, 'message' => 'That period has more than '.self::MAX_EXPORT.' records. Pick a shorter period.'], 422);
        }
        if ($rows->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'There are no records in that period.'], 422);
        }

        $base = 'SUTURA-'.Str::slug($store->name).'-statement-'.($from ?: 'start').'_to_'.($to ?: now()->toDateString());

        return $request->input('format') === 'csv'
            ? $this->csvResponse($store, $rows, $from, $to, $base.'.csv')
            : $this->zipResponse($store, $rows, $from, $to, $base.'.zip');
    }

    private function fileName(array $row, string $ext): string
    {
        $parts = [$row['date']?->format('Y-m-d') ?? 'undated', $row['kind'].'-'.$row['id'], Str::slug((string) $row['doc_no']), $row['amount'] !== null ? 'PHP'.number_format($row['amount'], 2, '.', '') : null];

        return implode('_', array_filter($parts)).'.'.$ext;
    }

    private function csvBody(Store $store, Collection $rows, ?string $from, ?string $to, Collection $fileNames): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // so Excel opens the UTF-8 file correctly
        $counted = $rows->where('status', '!=', 'rejected');
        fputcsv($out, ['Statement', $store->name]);
        fputcsv($out, ['Period', ($from ?: 'Beginning of records').' to '.($to ?: now()->toDateString())]);
        fputcsv($out, ['Generated', now()->format('Y-m-d H:i')]);
        fputcsv($out, ['Records', $rows->count(), 'Total (excluding rejected)', number_format((float) $counted->sum(fn ($r) => (float) ($r['amount'] ?? 0)), 2, '.', '')]);
        fputcsv($out, []);
        fputcsv($out, ['Date', 'Time', 'Type', 'Order / Appointment no.', 'Customer', 'Method', 'Payment type', 'Source', 'Amount (PHP)', 'Status', 'Reference no.', 'Recorded by', 'Branch', 'Receipt file']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['date']?->format('Y-m-d'), $r['date']?->format('H:i'), ReceiptLedger::KINDS[$r['kind']], $r['doc_no'], $r['customer'],
                $r['method'], $r['payment_type'], $r['source'], $r['amount'] !== null ? number_format($r['amount'], 2, '.', '') : '',
                $r['status'], $r['reference'], $r['recorded_by'], $r['branch'], $fileNames->get($r['key'], ''),
            ]);
        }
        rewind($out);

        return stream_get_contents($out);
    }

    private function csvResponse(Store $store, Collection $rows, ?string $from, ?string $to, string $name): StreamedResponse
    {
        $body = $this->csvBody($store, $rows, $from, $to, collect());

        return response()->streamDownload(fn () => print($body), $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function zipResponse(Store $store, Collection $rows, ?string $from, ?string $to, string $name): BinaryFileResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sutura-zip-');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $fileNames = collect();
        $used = [];
        foreach ($rows as $r) {
            $located = $r['receipt_path'] ? ReceiptFiles::locate($r['receipt_path']) : null;
            if (! $located) {
                continue;
            }
            $file = $this->fileName($r, $located['ext']);
            $file = isset($used[$file]) ? Str::beforeLast($file, '.').'-'.$r['key'].'.'.$located['ext'] : $file;
            $used[$file] = true;
            isset($located['absolute']) ? $zip->addFile($located['absolute'], 'receipts/'.$file) : $zip->addFromString('receipts/'.$file, $located['contents']);
            $fileNames->put($r['key'], 'receipts/'.$file);
        }
        $zip->addFromString('statement.csv', $this->csvBody($store, $rows, $from, $to, $fileNames));
        $zip->addFromString('README.txt', "SUTURA statement for {$store->name}\nRecords: {$rows->count()}   Receipt images: {$fileNames->count()}\nstatement.csv lists every record; the 'Receipt file' column names the image inside receipts/.\nRecords without an image are cash payments or proofs that are no longer on the server.\n");
        $zip->close();

        return response()->download($tmp, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }
}
