<?php

return [
    'max_entries' => (int) env('BUSINESS_BACKUP_MAX_ENTRIES', 10000),
    'max_total_bytes' => (int) env('BUSINESS_BACKUP_MAX_TOTAL_BYTES', 10737418240),
    'max_entry_bytes' => (int) env('BUSINESS_BACKUP_MAX_ENTRY_BYTES', 2147483648),
    'max_manifest_bytes' => (int) env('BUSINESS_BACKUP_MAX_MANIFEST_BYTES', 2097152),
    'max_workbook_bytes' => (int) env('BUSINESS_BACKUP_MAX_WORKBOOK_BYTES', 268435456),
];
