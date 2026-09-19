<?php

declare(strict_types=1);

namespace App\Modules\Landing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Landing\Http\Requests\StoreLandingAssetRequest;
use App\Modules\Landing\Http\Requests\UpdateLandingRequest;
use App\Modules\Landing\Http\Resources\LandingPageResource;
use App\Modules\Landing\Models\LandingPage;
use App\Shared\Auth\Owned;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LandingController extends Controller
{
    public function showPublic(string $slug): LandingPageResource
    {
        $landing = LandingPage::query()
            ->with(['user:id,name,country,catalog_company_id,catalog_company_name,catalog_rank_name', 'user.store:id,user_id,slug,name'])
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        return new LandingPageResource($landing);
    }

    public function show(Request $request): LandingPageResource|JsonResponse
    {
        $landing = $request->user()->landingPage()?->with(['user:id,name,country,catalog_company_id,catalog_company_name,catalog_rank_name', 'user.store:id,user_id,slug,name'])->first();

        if ($landing === null) {
            return response()->json(['message' => 'No tienes landing page'], 404);
        }

        return new LandingPageResource($landing);
    }

    public function update(UpdateLandingRequest $request): LandingPageResource
    {
        $user = $request->user();
        $landing = $user->landingPage;

        if ($landing === null) {
            $landing = $user->landingPage()->create([
                'title' => $user->name,
                'slug' => (Str::slug($user->name) ?: 'user').'-'.$user->id,
                'template' => 'default',
                'content' => ['hero' => ['title' => $user->name], 'blocks' => []],
                'network_id' => $user->current_network_id,
                'is_published' => false,
            ]);
        }

        $payload = $request->validated();
        if (isset($payload['content']) && is_array($payload['content'])) {
            $current = is_array($landing->content) ? $landing->content : [];
            $incoming = $payload['content'];
            $payload['content'] = array_merge($current, $incoming);
            foreach (['hero', 'reasons', 'palette'] as $section) {
                if (isset($incoming[$section]) && is_array($incoming[$section])) {
                    $payload['content'][$section] = array_merge(
                        is_array($current[$section] ?? null) ? $current[$section] : [],
                        $incoming[$section],
                    );
                }
            }
        }

        $landing->update($payload);

        $whatsapp = $request->input('content.whatsapp');
        if (is_string($whatsapp) && $user->store) {
            $settings = $user->store->settings ?? [];
            $settings['whatsapp'] = $whatsapp;
            $user->store->update(['settings' => $settings]);
        }

        return $this->resource($landing);
    }

    public function storeAsset(StoreLandingAssetRequest $request): LandingPageResource|JsonResponse
    {
        $user = $request->user();
        $landing = $user->landingPage;

        if ($landing === null) {
            return response()->json(['message' => 'No tienes landing page'], 404);
        }

        $kind = $request->string('kind')->toString();
        $file = $request->file('file');

        if ($file === null) {
            return response()->json(['message' => 'Falta el archivo.'], 422);
        }

        $directory = 'landings/'.$user->id;
        $extension = strtolower((string) ($file->guessExtension() ?: 'jpg'));

        foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $old) {
            Storage::disk('public')->delete($directory.'/'.$kind.'.'.$old);
        }

        $path = $file->storeAs($directory, $kind.'.'.$extension, 'public');
        $url = Storage::disk('public')->url($path);

        $content = $landing->content ?? [];
        $content['hero'] = is_array($content['hero'] ?? null) ? $content['hero'] : [];
        $content['reasons'] = is_array($content['reasons'] ?? null) ? $content['reasons'] : [];

        match ($kind) {
            'logo' => $content['logo'] = $url,
            'background' => $content['hero']['background'] = $url,
            'reasons' => $content['reasons']['photo'] = $url,
            default => $content['hero']['photo'] = $url,
        };

        $landing->update(['content' => $content]);

        return $this->resource($landing);
    }

    public function togglePublish(Request $request): LandingPageResource|JsonResponse
    {
        $landing = $request->user()->landingPage;

        if ($landing === null) {
            return response()->json(['message' => 'No tienes landing page'], 404);
        }

        Owned::find('update', $landing);

        $landing->is_published = ! $landing->is_published;
        $landing->save();

        return $this->resource($landing);
    }

    private function resource(LandingPage $landing): LandingPageResource
    {
        $landing->load([
            'user:id,name,country,catalog_company_id,catalog_company_name,catalog_rank_name',
            'user.store:id,user_id,slug,name',
        ]);

        return new LandingPageResource($landing);
    }
}
