<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\StoreBranch;
use App\Notifications\StoreActivityNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Verify branch map locations": a branch a shop adds (or moves) stays off the public map, search and booking form
 * until the System Admin has looked at its pin and address and approved it.
 */
class BranchVerificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'pending');
        $base = StoreBranch::query()->whereHas('store', fn ($q) => $q->where('status', 'approved'));

        $branches = (clone $base)->with(['store:id,name,slug,owner_id', 'store.owner:id,name,email,contact_email'])
            ->when($status !== 'all', fn ($q) => $q->where('verification_status', $status))
            ->orderBy('created_at')->limit(200)->get()
            ->map(fn (StoreBranch $b) => [
                'id' => $b->id, 'name' => $b->name, 'is_main' => (bool) $b->is_main, 'status' => $b->status,
                'verification_status' => $b->verification_status, 'verification_note' => $b->verification_note,
                'address' => $b->address, 'barangay' => $b->barangay, 'district' => $b->district, 'city' => $b->city, 'landmark' => $b->landmark,
                'latitude' => $b->latitude, 'longitude' => $b->longitude, 'guide_image_url' => $b->guide_image_url, 'landmark_image_url' => $b->landmark_image_url,
                'map_url' => $b->latitude && $b->longitude ? "https://www.google.com/maps?q={$b->latitude},{$b->longitude}" : null,
                'created_at' => $b->created_at, 'verified_at' => $b->verified_at,
                'store' => ['id' => $b->store->id, 'name' => $b->store->name, 'slug' => $b->store->slug],
                'owner' => $b->store->owner ? ['name' => $b->store->owner->name, 'email' => $b->store->owner->contact_email ?? $b->store->owner->email] : null,
            ]);

        return response()->json(['success' => true, 'data' => [
            'counts' => [
                'pending' => (clone $base)->where('verification_status', 'pending')->count(),
                'verified' => (clone $base)->where('verification_status', 'verified')->count(),
                'rejected' => (clone $base)->where('verification_status', 'rejected')->count(),
            ],
            'branches' => $branches,
        ]]);
    }

    public function verify(Request $request, StoreBranch $branch): JsonResponse
    {
        abort_if(! $branch->latitude || ! $branch->longitude, 422, 'This branch has no map pin, so its location cannot be verified.');
        $branch->update(['verification_status' => 'verified', 'verified_at' => now(), 'verified_by' => $request->user()->id, 'verification_note' => null]);
        $this->tell($request, $branch, 'branch_verified', 'Branch location verified', "“{$branch->name}” is now visible on the map and open for booking.");

        return response()->json(['success' => true, 'data' => ['id' => $branch->id, 'verification_status' => 'verified']]);
    }

    public function reject(Request $request, StoreBranch $branch): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $branch->update(['verification_status' => 'rejected', 'verified_at' => null, 'verified_by' => $request->user()->id, 'verification_note' => $data['reason']]);
        $this->tell($request, $branch, 'branch_rejected', 'Branch location needs changes', "“{$branch->name}” is not visible yet: {$data['reason']} Fix the location and save it to send it for checking again.");

        return response()->json(['success' => true, 'data' => ['id' => $branch->id, 'verification_status' => 'rejected']]);
    }

    private function tell(Request $request, StoreBranch $branch, string $action, string $title, string $message): void
    {
        $store = $branch->store;
        $store?->owner?->notify(new StoreActivityNotification($action, $title, $message, '/dashboard/branches', ['branch_id' => $branch->id]));
        $store?->auditLogs()->create([
            'user_id' => $request->user()->id, 'action' => $action, 'model_type' => StoreBranch::class, 'model_id' => $branch->id,
            'payload' => ['name' => $branch->name, 'reason' => $branch->verification_note, 'description' => 'System Admin: '.str_replace('_', ' ', $action).' — '.$branch->name],
            'ip_address' => $request->ip(),
        ]);
    }
}
