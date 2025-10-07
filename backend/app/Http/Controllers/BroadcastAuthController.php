<?php
// app/Http/Controllers/BroadcastAuthController.php
use App\Events\HRStaffListUpdated;
use App\Models\HRStaff;

public function authenticate(Request $request)
{
    $response = Broadcast::auth($request);

    // Fetch all HR staff (even offline)
    $staff = HRStaff::select('id', 'full_name')->get();

    // Broadcast full list to the channel
    broadcast(new HRStaffListUpdated($staff));

    return $response;
}
