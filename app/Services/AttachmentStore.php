<?php

namespace App\Services;

use App\Models\TicketMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores uploaded files for a ticket message on the private disk.
 *
 * Files get random names under organizations/{id}/tickets/{id}/, so a stored
 * path never contains user input. The original name is kept only as metadata
 * and is sanitised for use in a Content-Disposition header.
 */
class AttachmentStore
{
    public const DISK = 'local';

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $written  receives each stored path as soon as it is written
     */
    public function attach(TicketMessage $message, array $files, array &$written): void
    {
        foreach ($files as $file) {
            $path = $file->store(
                "organizations/{$message->organization_id}/tickets/{$message->ticket_id}",
                self::DISK,
            );
            $written[] = $path;

            $message->attachments()->create([
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => $this->sanitiseName($file->getClientOriginalName()),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
            ]);
        }
    }

    /**
     * Files are not part of the database transaction; when it rolls back, the
     * caller deletes what was written so no orphaned files remain.
     *
     * @param  list<string>  $paths
     */
    public function delete(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk(self::DISK)->delete($path);
            } catch (Throwable) {
                // Best effort: a leftover file is unreachable without a database row.
            }
        }
    }

    private function sanitiseName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]/u', '', basename($name)) ?: 'attachment';

        return Str::limit($name, 200, '');
    }
}
