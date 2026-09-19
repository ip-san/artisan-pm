<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Who uploaded an attachment — Redmine's Attachment#author. Media Library
 * has no such column, so it lives in the `uploaded_by` custom property, which
 * survives Media::move() (used to attach a pending API upload to its
 * issue). It is filled in one place, the Media creating hook registered in
 * AppServiceProvider, from the request's user, so no upload path can forget
 * it; a file created outside a request (incoming mail, queued work) sets it
 * explicitly or stays anonymous.
 */
final class AttachmentUploader
{
    public const string PROPERTY = 'uploaded_by';

    public static function idOf(Media $media): ?int
    {
        $id = $media->getCustomProperty(self::PROPERTY);

        return is_numeric($id) ? (int) $id : null;
    }

    public static function userOf(Media $media): ?User
    {
        $id = self::idOf($media);

        return $id === null ? null : User::query()->find($id);
    }

    /**
     * Uploaders of several attachments in one query, keyed by user id.
     *
     * @param  iterable<int, Media>  $medias
     * @return Collection<int, User>
     */
    public static function usersFor(iterable $medias): Collection
    {
        $ids = collect($medias)->map(fn (Media $media) => self::idOf($media))->filter()->unique()->values();

        return $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids)->get()->keyBy('id');
    }
}
