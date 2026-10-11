<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Shared upload filename hardening (used by enrollment docs, permanent
 * student docs, classroom submissions, avatars).
 *
 * Never trust the user-supplied extension: only allowlisted extensions reach
 * the disk, which blocks stored-HTML/SVG/JS uploads served same-origin.
 * Filenames always embed ≥64-bit randomness plus IDs (unguessable URLs).
 */
final class Uploads
{
    /** Extensions ever allowed to land in public_html/uploads. */
    public const ALLOWED_EXT = [
        'pdf', 'jpg', 'jpeg', 'png', 'webp',
        'doc', 'docx', 'txt', 'zip',
    ];

    /**
     * Safe extension for a client filename + detected MIME, or null to reject.
     * Falls back by MIME only when the client gave no usable extension.
     */
    public static function safeExt(string $clientName, string $mime): ?string
    {
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        if ($ext !== '') {
            return in_array($ext, self::ALLOWED_EXT, true) ? $ext : null;
        }
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt',
            'application/zip', 'application/x-zip-compressed' => 'zip',
            default => null,
        };
    }

    /** Unguessable stored basename: prefix + ids + 64-bit random + ext. */
    public static function storedName(string $prefix, string $ext): string
    {
        return $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    }
}
