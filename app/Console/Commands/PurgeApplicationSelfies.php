<?php

namespace App\Console\Commands;

use App\Models\StoreApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Retention rule from the application's privacy consent: the selfie holding
 * the government ID is only needed for the review, so it's deleted
 * SELFIE_RETENTION_DAYS after the application is approved or rejected
 * (reviewed_at). The row keeps selfie_deleted_at as a record that it existed.
 */
class PurgeApplicationSelfies extends Command
{
    protected $signature = 'app:purge-application-selfies';

    protected $description = 'Deletes shop-application selfies 30 days after the application was approved or rejected.';

    public function handle(): int
    {
        $disk = Storage::disk(config('filesystems.private_disk', 'local'));
        $purged = 0;

        StoreApplication::whereNotNull('reviewed_at')
            ->where('reviewed_at', '<', now()->subDays(StoreApplication::SELFIE_RETENTION_DAYS))
            ->whereNull('selfie_deleted_at')
            ->where(fn ($q) => $q->whereNotNull('selfie_with_id_path')->orWhere('previous_documents', 'like', '%"selfie"%'))
            ->each(function (StoreApplication $application) use ($disk, &$purged) {
                $previous = $application->previous_documents ?? [];
                $paths = array_filter([$application->selfie_with_id_path, $previous['selfie']['path'] ?? null]);
                $disk->delete(array_values($paths));
                unset($previous['selfie']);

                $application->update([
                    'selfie_with_id_path' => null,
                    'previous_documents' => $previous ?: null,
                    'selfie_deleted_at' => now(),
                ]);
                $purged++;
            });

        $this->info("Deleted the selfie from {$purged} decided application(s).");

        return self::SUCCESS;
    }
}
