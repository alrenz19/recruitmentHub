<?php
// app/Policies/AssessmentPolicy.php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AssessmentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Allow all authenticated users to view assessments list
        // You can add role-based logic here if needed
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Assessment $assessment): bool
    {
        // Allow viewing if user created the assessment or has role 1, 2, 3
        return $assessment->created_by_user_id === $user->id || in_array($user->role_id, [1, 2, 3]);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Only users with role_id 1, 2, or 3 can create assessments
        return in_array($user->role_id, [1, 2, 3]);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Assessment $assessment): bool
    {
        // Only users with role_id 1, 2, or 3 and creator can update
        return $user->role_id === 1 // Admins can update any assessment
        || in_array($user->role_id, [1, 2, 3]);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Assessment $assessment): bool
    {
        // Only users with role_id 1, 2, or 3 can delete (creator or any of those roles)
        return $user->role_id === 1 // Admins can update any assessment
        || in_array($user->role_id, [1, 2, 3]);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Assessment $assessment): bool
    {
        // Only users with role_id 1, 2, or 3 can restore
        return in_array($user->role_id, [1, 2, 3]);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Assessment $assessment): bool
    {
        // Only role_id 1 (admin) can force delete
        return $user->role_id === 1;
    }
}