<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\News;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreNewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [News::class, $this->route('project')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Redmine本家はtitle最大60文字だが、本アプリの既存Web UIフォーム
            // (resources/views/livewire/news/form.blade.php)は既にmax:255で
            // 運用中のため、APIもそれに合わせる。
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string'],
        ];
    }
}
