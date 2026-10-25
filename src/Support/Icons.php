<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Support;

/** SVG path data of the built-in icons (24×24, stroke), the same as `ICONS` in @acunsoft/datatable-dom. */
final class Icons
{
    public const PATHS = [
        'view' => 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
        'edit' => 'M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z',
        'delete' => 'M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6',
        'more' => 'M12 5.5h.01M12 12h.01M12 18.5h.01',
        'copy' => 'M9 9h11v11H9zM5 15H4V4h11v1',
        'check' => 'M20 6 9 17l-5-5',
        'download' => 'M12 3v12M7 10l5 5 5-5M5 21h14',
        'close' => 'M18 6 6 18M6 6l12 12',
        'filter' => 'M3 5h18l-7 8.5V19l-4 2v-7.5z',
        'columns' => 'M9 4v16M15 4v16M4 4h16v16H4z',
        'search' => 'M21 21l-4.35-4.35M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14z',
        'expand' => 'M12 8v8M8 12h8M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z',
        'up' => 'M18 15l-6-6-6 6',
        'down' => 'M6 9l6 6 6-6',
    ];

    /** The path of a built-in icon, or the given path data when it only holds path commands. */
    public static function path(string $icon): ?string
    {
        if (isset(self::PATHS[$icon])) {
            return self::PATHS[$icon];
        }

        return preg_match('/^[MmLlHhVvCcSsQqTtAaZz0-9.,\s-]{1,2000}$/', $icon) === 1 ? $icon : null;
    }
}
