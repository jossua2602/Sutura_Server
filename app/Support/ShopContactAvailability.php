<?php

namespace App\Support;

use App\Models\Store;

/**
 * "Open a shop": is this owner email / contact number already used by another
 * shop's registration? Rejected applications don't count (the owner can apply
 * again). A customer account with the same email is fine — the application
 * form says so — so only shops are checked, never customer users.
 *
 * Used by the form's live check and by the final submit, so both agree.
 */
class ShopContactAvailability
{
    public static function emailTaken(string $email): bool
    {
        $email = mb_strtolower(trim($email));

        return $email !== '' && Store::query()
            ->where('status', '!=', 'rejected')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();
    }

    /** Matches 09663946888, +639663946888 and spaced/dashed forms of the same number. */
    public static function phoneTaken(string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) < 10) {
            return false;
        }
        $last10 = substr($digits, -10);

        return Store::query()
            ->where('status', '!=', 'rejected')
            ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', '') LIKE ?", ['%'.$last10])
            ->exists();
    }
}
