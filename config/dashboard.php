<?php

declare(strict_types=1);

return [
    'admin_cache_ttl' => (int) env('ADMIN_DASHBOARD_CACHE_TTL', 300),
    'admin_kpi_cache_key' => 'enrollment-stats:admin:kpi',
    'admin_completion_rate_cache_key' => 'enrollment-stats:admin:completion-rate',
];
