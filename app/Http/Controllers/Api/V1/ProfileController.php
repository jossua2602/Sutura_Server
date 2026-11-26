<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    /**
     * Minimal public profile for viewing another user (e.g. tapping a
     * reviewer's name/avatar on a catalog item's Ratings & Reviews page).
     * Deliberately just id/name/profile_picture — no email/phone/bio/etc,
     * and no account-hub data (Job Orders, Appointments, ...): that's
     * private and only ever shown on the viewer's own /account.
     */
    public function publicShow(int $id): JsonResponse
    {
        // Only people who already show up publicly — someone who wrote a review, or a shop
        // owner — can be opened. Otherwise this would list the name and photo of every
        // account (customers, staff, admins) just by counting ids upward.
        $isPublic = \App\Models\StoreReview::where('user_id', $id)->exists()
            || \App\Models\ServiceReview::where('user_id', $id)->exists()
            || \App\Models\CatalogItemReview::where('user_id', $id)->exists()
            || \App\Models\ServicePackageReview::where('user_id', $id)->exists()
            || \App\Models\Store::where('owner_id', $id)->where('status', 'approved')->exists();
        abort_unless($isPublic, 404);

        $user = User::select('id', 'name', 'profile_picture')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $user,
        ]);
    }

    // iPhones/Macs save camera photos as HEIC by default, which browsers
    // can't display and PHP's GD (what XAMPP ships) can't decode -- without
    // this message a Mac/iPhone user just sees a generic "invalid file"
    // error with no clue why a photo that opens fine in Preview won't upload.
    private const INVALID_IMAGE_MESSAGE = 'Only JPG, PNG, or WEBP images are supported. '
        .'If this photo was taken on an iPhone/Mac it may be saved as HEIC -- go to '
        .'iPhone Settings -> Camera -> Formats -> "Most Compatible" (or export/share it '
        .'as JPEG from Photos) before uploading.';

    /**
     * The mobile number SMS goes to, and the switch to stop texts. A real Philippine mobile number is required —
     * a made-up or landline number would just make the shop's drafts show up as "needs fixing".
     */
    public function updateTextMessages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:30'],
            'sms_opt_out' => ['sometimes', 'boolean'],
        ]);
        if (! empty($data['phone'])) {
            $normalized = \App\Support\PhoneNumber::normalize($data['phone']);
            abort_if($normalized === null, 422, 'Enter a Philippine mobile number, like 0917 123 4567.');
            $data['phone'] = $normalized;
        }
        $request->user()->update($data);

        return response()->json(['success' => true, 'data' => $request->user()->only(['phone', 'sms_opt_out'])]);
    }

    /**
     * Update the user's personal details.
     */
    public function updatePersonal(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'bio' => 'nullable|string',
            'experience' => 'nullable|array',
            'education' => 'nullable|array',
            'skills' => 'nullable|array',
            'social_links' => 'nullable|array',
            'creations_gallery' => 'nullable|array',
        ]);

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            // Must include roles — the frontend replaces its entire auth-store user
            // object with this response, and a missing `roles` array flips
            // isStoreOwner to false, hiding owner-only nav until the next full reload.
            'data' => $user->fresh()->load('roles:id,name'),
        ]);
    }

    /**
     * Update the user's password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|current_password',
            // `different`: an admin-issued temporary password can't just be
            // "changed" to itself to clear must_change_password.
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ])->save();

        // A changed password signs out every other device/tab (a stolen token dies with the
        // old password); the session making the change stays signed in.
        $request->user()->tokens()->where('id', '!=', $request->user()->currentAccessToken()?->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }

    /**
     * Toggle Staff Availability.
     */
    public function toggleAvailability(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('staff') || ! $user->staffProfile) {
            return response()->json(['message' => "Only staff accounts can do this."], 403);
        }

        $validated = $request->validate([
            'is_available' => 'required|boolean',
        ]);

        $user->staffProfile()->update([
            'is_available' => $validated['is_available'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Availability updated.',
            'data' => $user->staffProfile->fresh(),
        ]);
    }

    // Same fixed pattern as FileUploadController::UPLOAD_DISK — a bare
    // Storage::url($path) resolves against the app's DEFAULT disk ('local'
    // here, see config/filesystems.php), not whichever disk the file was
    // actually store()'d to ('public'). This coincidentally produced a
    // working URL in local dev (config('app.url') + the local disk's
    // relative /storage/... fallback happen to line up), but drifts the
    // moment FILESYSTEM_DISK or the storage disk config changes — exactly
    // the bug class already fixed once in FileUploadController.
    // Resolved at run time from config/filesystems.php (UPLOAD_DISK), not a constant, so production can use R2.
    private static function uploadDisk(): string
    {
        return config('filesystems.upload_disk', 'public');
    }

    /**
     * Upload Profile or Cover Picture.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:avatar,cover,creation',
            'file' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ], [
            'file.image' => self::INVALID_IMAGE_MESSAGE,
            'file.mimes' => self::INVALID_IMAGE_MESSAGE,
        ]);

        $user = $request->user();
        $file = $request->file('file');

        $path = $file->store('users/'.$user->id, self::uploadDisk());
        $url = Storage::disk(self::uploadDisk())->url($path);

        if ($request->type === 'avatar') {
            $user->update(['profile_picture' => $url]);
        } elseif ($request->type === 'cover') {
            $user->update(['cover_photo' => $url]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully.',
            'url' => $url,
            'data' => $user->fresh()->load('roles:id,name'),
        ]);
    }
}
