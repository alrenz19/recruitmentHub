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
        // Allow viewing if user created the assessment or is an admin
        return $assessment->created_by_user_id === $user->id || $user->role_id === 1; // Adjust role check as needed
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Allow all authenticated users to create assessments
        // Add role restrictions if needed (e.g., only HR or Managers)
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Assessment $assessment): bool
    {
        // Allow update only if user created the assessment
        return $assessment->created_by_user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Assessment $assessment): bool
    {
        // Allow delete only if user created the assessment or is admin
        return $assessment->created_by_user_id === $user->id || $user->role_id === 1;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Assessment $assessment): bool
    {
        // Allow restore only if user created the assessment or is admin
        return $assessment->created_by_user_id === $user->id || $user->role_id === 1;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Assessment $assessment): bool
    {
        // Allow permanent delete only for admins
        return $user->role_id === 1; // Admin role
    }
}