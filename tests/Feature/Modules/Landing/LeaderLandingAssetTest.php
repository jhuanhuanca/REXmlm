<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Landing;

use App\Models\User;
use App\Modules\Landing\Models\LandingPage;
use App\Modules\MLM\Models\Network;
use App\Modules\Store\Models\Store;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaderLandingAssetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['cashier.secret' => null]);
    }

    public function test_leader_can_upload_photo_and_logo(): void
    {
        Storage::fake('public');
        $ana = $this->leader('ana-landing@test');
        Sanctum::actingAs($ana);

        $photo = UploadedFile::fake()->image('raquel.jpg', 400, 400);

        $this->post('/api/v1/my-landing/assets', [
            'kind' => 'photo',
            'file' => $photo,
        ], ['Accept' => 'application/json'])
            ->assertOk();

        $photoUrl = $ana->fresh()->landingPage?->content['hero']['photo'] ?? null;
        $this->assertIsString($photoUrl);
        $this->assertStringContainsString('/storage/landings/', $photoUrl);

        $logo = UploadedFile::fake()->image('logo.png', 120, 120);

        $this->post('/api/v1/my-landing/assets', [
            'kind' => 'logo',
            'file' => $logo,
        ], ['Accept' => 'application/json'])
            ->assertOk();

        $content = $ana->fresh()->landingPage?->content;
        $this->assertNotEmpty($content['logo'] ?? null);
        $this->assertNotEmpty($content['hero']['photo'] ?? null);
    }

    public function test_leader_can_upload_background_and_reasons_photos_apart(): void
    {
        Storage::fake('public');
        $ana = $this->leader('ana-landing-bg@test');
        Sanctum::actingAs($ana);

        $this->post('/api/v1/my-landing/assets', [
            'kind' => 'background',
            'file' => UploadedFile::fake()->image('fondo.jpg', 800, 600),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->post('/api/v1/my-landing/assets', [
            'kind' => 'reasons',
            'file' => UploadedFile::fake()->image('reasons.jpg', 400, 800),
        ], ['Accept' => 'application/json'])->assertOk();

        $content = $ana->fresh()->landingPage?->content;
        $this->assertNotEmpty($content['hero']['background'] ?? null);
        $this->assertNotEmpty($content['reasons']['photo'] ?? null);
        $this->assertNotEquals($content['hero']['background'] ?? '', $content['reasons']['photo'] ?? '');
    }

    public function test_update_keeps_photo_when_saving_copy(): void
    {
        $ana = $this->leader('ana-landing-copy@test');
        Sanctum::actingAs($ana);

        $this->putJson('/api/v1/my-landing', [
            'title' => 'Raquel',
            'content' => [
                'hero' => [
                    'title' => 'Hola',
                    'subtitle' => 'Te ayudo a emprender',
                    'kicker' => 'We Created',
                    'photo' => 'https://ejemplo.com/foto.jpg',
                    'background' => 'https://ejemplo.com/fondo.jpg',
                    'frame' => 'emerge',
                ],
                'palette' => [
                    'principal' => ['#e72fb9', '#cd8ddf'],
                    'complementarios' => ['#5a006a', '#71b7c1', '#b34e8e'],
                    'primary' => '#e72fb9',
                    'secondary' => '#5a006a',
                    'accent' => '#cd8ddf',
                ],
                'reasons' => [
                    'photo' => 'https://ejemplo.com/reasons.jpg',
                    'title' => '¿Por qué esta red?',
                ],
                'logo' => 'https://ejemplo.com/logo.png',
                'whatsapp' => '59168785473',
                'whatsapp_label' => 'Conversa con Raquel',
                'blocks' => [],
            ],
        ])->assertOk()
            ->assertJsonPath('data.title', 'Raquel')
            ->assertJsonPath('data.content.hero.photo', 'https://ejemplo.com/foto.jpg')
            ->assertJsonPath('data.content.hero.background', 'https://ejemplo.com/fondo.jpg')
            ->assertJsonPath('data.content.hero.kicker', 'We Created')
            ->assertJsonPath('data.content.hero.frame', 'emerge')
            ->assertJsonPath('data.content.palette.principal.0', '#e72fb9')
            ->assertJsonPath('data.content.palette.complementarios.1', '#71b7c1')
            ->assertJsonPath('data.content.reasons.photo', 'https://ejemplo.com/reasons.jpg')
            ->assertJsonPath('data.content.logo', 'https://ejemplo.com/logo.png')
            ->assertJsonPath('data.content.whatsapp_label', 'Conversa con Raquel')
            ->assertJsonPath('data.owner_name', $ana->name);

        $store = $ana->store;
        $this->assertNotNull($store);

        $this->getJson('/api/v1/store/'.$store->slug)
            ->assertOk()
            ->assertJsonPath('data.identity.title', 'Raquel')
            ->assertJsonPath('data.identity.logo', 'https://ejemplo.com/logo.png')
            ->assertJsonPath('data.identity.palette.principal.0', '#e72fb9');
    }

    private function leader(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'country' => 'BO',
        ]);
        $user->assignRole('leader');
        $network = Network::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Red '.$user->id,
            'slug' => 'net-land-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-land-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);
        LandingPage::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'title' => $user->name,
            'slug' => 'land-'.$user->id,
            'template' => 'default',
            'content' => ['hero' => ['title' => $user->name], 'blocks' => []],
            'is_published' => false,
        ]);

        return $user->fresh(['store', 'landingPage']);
    }
}
