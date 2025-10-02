<?php 

function frontend_url(string $path = '', array $params = []): string
{
    // Decide the base URL
    if (app()->runningInConsole()) {
        // Fallback for jobs, artisan, etc.
        $base = rtrim(config('app.frontend_url'), '/');
    } else {
        $host   = request()->getHost();
        $scheme = request()->getScheme();
        $base   = $scheme . '://' . $host . ':5173';
    }

    // Security check against allowed patterns
    $patterns = config('app.frontend_url_patterns');
    $isValid  = false;

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $base)) {
            $isValid = true;
            break;
        }
    }

    // Append params like /job-offer-status/4
    if (!empty($params)) {
        $path .= '/' . implode('/', $params);
    }

    return $base . '/' . ltrim($path, '/');
}
