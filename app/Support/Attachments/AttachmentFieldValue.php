<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use App\CustomFields\Formats\AttachmentFormat;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The file behind an attachment custom field value (B'-02): setting it,
 * and who may read it. Redmine's AttachmentFormat#set_custom_field_value /
 * after_save_custom_value and CustomValue#attachments_visible?.
 */
final class AttachmentFieldValue
{
    /**
     * Sets $field's value on $record from a form or API input: an uploaded
     * file or an API `{token}` becomes the record's new file (the previous
     * one is deleted), an empty value clears it (deleting the file), and an
     * id keeps the current file only when it is this record's file for this
     * field — any other id leaves the value as it was, so a value can never
     * point at somebody else's file.
     */
    public static function assign(Model&HasMedia $record, CustomField $field, mixed $raw): void
    {
        $value = CustomFieldValue::query()->firstOrNew([
            'custom_field_id' => $field->id,
            'customized_type' => $record->getMorphClass(),
            'customized_id' => $record->getKey(),
        ]);
        $current = $value->value_int !== null ? self::fileOf($record, $field, (int) $value->value_int) : null;

        $new = match (true) {
            $raw instanceof UploadedFile => $record->addMedia($raw)
                ->usingFileName($raw->getClientOriginalName())
                ->withCustomProperties(['custom_field_id' => $field->id])
                ->toMediaCollection(AttachmentFormat::COLLECTION),
            is_array($raw) && filled($raw['token'] ?? null) => self::claim($record, $field, $raw),
            $raw === null || $raw === '' || $raw === [] => false,
            default => $current,
        };

        if ($new === $current || $new === null || ($new === false && $value->value_int === null)) {
            return;
        }

        $value->value_int = $new === false ? null : $new->id;
        $value->save();

        $current?->delete();
    }

    /**
     * Whether $user may read $media when it is an attachment field's file:
     * the record must be visible (checked by the caller) and so must the
     * field. Media outside the collection is not this class's concern.
     */
    public static function visibleTo(Media $media, ?User $user): bool
    {
        if ($media->collection_name !== AttachmentFormat::COLLECTION) {
            return true;
        }

        $record = $media->model;

        if ($record === null || ! method_exists($record, 'relevantCustomFields')) {
            return false;
        }

        $fieldId = (int) $media->getCustomProperty('custom_field_id');

        return $record->relevantCustomFields($user)->contains('id', $fieldId);
    }

    /**
     * Gate::authorize('view', owner) plus, for an attachment field's file,
     * the field's visibility — the check every attachment download,
     * thumbnail, preview and API read goes through.
     */
    public static function authorizeView(Media $media, ?User $user): void
    {
        $owner = $media->model;

        abort_if($owner === null, 404);

        if ($media->collection_name === AttachmentFormat::COLLECTION) {
            abort_unless(self::ownerVisibleTo($owner, $user), 403);
        } else {
            Gate::forUser($user)->authorize('view', $owner);
        }

        abort_unless(self::visibleTo($media, $user), 403);
    }

    /**
     * Whether $user may see the record an attachment field's file belongs
     * to (Redmine's customized.visible?, B'-02b): a version by the roadmap's
     * view_issues rather than VersionPolicy::view (the Files module's
     * view_files), a user by the users_visibility rule of their profile and
     * GET /users/{id}; everything else by its own `view` ability. Groups and
     * enumerations have no page outside administration, so their `view`
     * leaves the files to administrators.
     */
    private static function ownerVisibleTo(Model $owner, ?User $user): bool
    {
        return match (true) {
            $owner instanceof Version => Gate::forUser($user)->allows('viewRoadmap', [Version::class, $owner->project]),
            $owner instanceof User => $owner->isVisibleTo($user),
            default => Gate::forUser($user)->allows('view', $owner),
        };
    }

    /**
     * Deletes every file stored for $field (the field is being deleted).
     */
    public static function deleteFilesOf(CustomField $field): void
    {
        Media::query()
            ->where('collection_name', AttachmentFormat::COLLECTION)
            ->where('custom_properties->custom_field_id', $field->id)
            ->get()
            ->each(fn (Media $media) => $media->delete());
    }

    private static function fileOf(Model&HasMedia $record, CustomField $field, int $mediaId): ?Media
    {
        $media = Media::query()->find($mediaId);

        return $media !== null
            && $media->model_type === $record->getMorphClass()
            && (int) $media->model_id === (int) $record->getKey()
            && $media->collection_name === AttachmentFormat::COLLECTION
            && (int) $media->getCustomProperty('custom_field_id') === $field->id
            ? $media
            : null;
    }

    /**
     * @param  array<string, mixed>  $upload
     */
    private static function claim(Model&HasMedia $record, CustomField $field, array $upload): ?Media
    {
        $media = PendingUploadAttacher::attach(['token' => $upload['token'], 'filename' => $upload['filename'] ?? '', 'description' => $upload['description'] ?? ''], $record, AttachmentFormat::COLLECTION);

        if ($media === null) {
            return null;
        }

        $media->setCustomProperty('custom_field_id', $field->id);
        $media->save();

        return $media;
    }
}
