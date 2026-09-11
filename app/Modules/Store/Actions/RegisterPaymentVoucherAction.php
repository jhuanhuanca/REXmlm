<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Store;
use App\Services\Catalog\CatalogClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RegisterPaymentVoucherAction
{
    public function __construct(private readonly CatalogClient $catalog) {}

    public function handle(Store $store, Order $order, UploadedFile $file): Order
    {
        $extension = strtolower((string) ($file->guessExtension() ?: 'jpg'));
        $directory = 'vouchers/'.$store->id;
        $filename = $order->id.'.'.$extension;

        foreach (Storage::disk('local')->files($directory) as $existing) {
            if (str_starts_with(basename($existing), $order->id.'.')) {
                Storage::disk('local')->delete($existing);
            }
        }

        $path = $file->storeAs($directory, $filename, 'local');

        $documentId = $this->registerInCatalog($store, $order, $file->getClientOriginalName());

        $order->forceFill([
            'payment_voucher_url' => $path,
            'payment_voucher_document_id' => $documentId,
            'payment_reference' => $documentId ? (string) $documentId : $path,
        ])->save();

        return $order->fresh(['items', 'partner:id,name,email']) ?? $order;
    }

    private function registerInCatalog(Store $store, Order $order, ?string $originalName): ?int
    {
        $store->loadMissing('user');
        $companyId = (int) ($store->user?->workingCatalogCompanyId() ?: $store->user?->catalog_company_id ?: 0);

        if ($companyId <= 0) {
            return null;
        }

        try {
            $document = $this->catalog->createCompanyDocument($companyId, [
                'title' => 'Comprobante orden #'.$order->id,
                'description' => trim($store->name.' · '.($order->payment_method ?: 'pago').' · '.$order->customer_name),
                'file_type' => 'voucher',
                'original_name' => $originalName,
            ]);
        } catch (Throwable $exception) {
            Log::warning('No se pudo registrar el comprobante en el catálogo.', [
                'order_id' => $order->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $id = (int) ($document['id'] ?? 0);

        return $id > 0 ? $id : null;
    }
}
