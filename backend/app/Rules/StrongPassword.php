<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StrongPassword implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  \Closure  $fail
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (strlen($value) < 8) {
            $fail('The :attribute must be at least 8 characters.');
            return;
        }

        if (!preg_match('/[A-Z]/', $value)) {
            $fail('The :attribute must contain at least one uppercase letter.');
            return;
        }

        if (!preg_match('/[a-z]/', $value)) {
            $fail('The :attribute must contain at least one lowercase letter.');
            return;
        }

        if (!preg_match('/[0-9]/', $value)) {
            $fail('The :attribute must contain at least one number.');
            return;
        }

        if (!preg_match('/[^A-Za-z0-9]/', $value)) {
            $fail('The :attribute must contain at least one special character.');
            return;
        }
    }
}