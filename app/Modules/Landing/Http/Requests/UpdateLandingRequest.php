<?php

declare(strict_types=1);

namespace App\Modules\Landing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $landing = $user?->landingPage;

        if ($user === null || ! $user->can('landing.manage')) {
            return false;
        }

        return $landing === null || $user->can('update', $landing);
    }

    public function rules(): array
    {
        $blockTypes = config('rexmlm.landing_block_types', ['text', 'image', 'store_cta']);

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'template' => ['sometimes', 'string', 'max:50'],
            'content' => ['sometimes', 'array'],
            'content.hero' => ['sometimes', 'array'],
            'content.hero.title' => ['nullable', 'string', 'max:255'],
            'content.hero.subtitle' => ['nullable', 'string', 'max:500'],
            'content.hero.cta_label' => ['nullable', 'string', 'max:80'],
            'content.hero.cta_href' => ['nullable', 'string', 'max:500'],
            'content.hero.photo' => ['nullable', 'string', 'max:2048'],
            'content.hero.background' => ['nullable', 'string', 'max:2048'],
            'content.hero.kicker' => ['nullable', 'string', 'max:80'],
            'content.hero.frame' => ['nullable', 'string', Rule::in(['phone', 'circle', 'emerge', 'arch', 'blob'])],
            'content.palette' => ['sometimes', 'array'],
            'content.palette.primary' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'content.palette.secondary' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'content.palette.accent' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'content.palette.principal' => ['sometimes', 'array', 'max:2'],
            'content.palette.principal.*' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'content.palette.complementarios' => ['sometimes', 'array', 'max:3'],
            'content.palette.complementarios.*' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'content.reasons' => ['sometimes', 'array'],
            'content.reasons.photo' => ['nullable', 'string', 'max:2048'],
            'content.reasons.kicker' => ['nullable', 'string', 'max:80'],
            'content.reasons.title' => ['nullable', 'string', 'max:255'],
            'content.reasons.body' => ['nullable', 'string', 'max:1500'],
            'content.reasons.benefits' => ['sometimes', 'array', 'max:12'],
            'content.reasons.benefits.*' => ['nullable', 'string', 'max:80'],
            'content.logo' => ['nullable', 'string', 'max:2048'],
            'content.whatsapp' => ['nullable', 'string', 'max:30'],
            'content.blocks' => ['sometimes', 'array', 'max:30'],
            'content.blocks.*.type' => ['required', Rule::in($blockTypes)],
            'content.blocks.*.body' => ['nullable', 'string', 'max:5000'],
            'content.blocks.*.path' => ['nullable', 'string', 'max:500'],
        ];
    }
}
