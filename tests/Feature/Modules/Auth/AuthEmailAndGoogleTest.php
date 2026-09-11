<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Auth;

use App\Mail\InvitationMail;
use App\Mail\WelcomeUserMail;
use App\Models\User;
use App\Modules\MLM\Models\Invitation;
use App\Modules\MLM\Models\Network;
use App\Shared\Enums\InvitationStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthEmailAndGoogleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'cashier.secret' => null,
            'services.catalog.url' => 'http://catalog.test/api/v1',
            'services.catalog.token' => 'test-token',
            'services.catalog.cache_ttl' => 0,
            'services.google.client_id' => 'test-google-client.apps.googleusercontent.com',
            'rexmlm.frontend_url' => 'http://localhost:5173',
        ]);
        Mail::fake();
        $this->fakeCatalog();
    }

    public function test_registering_a_leader_sends_welcome_email(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana Líder',
            'email' => 'ana-welcome@auth.test',
            'password' => 'password12',
            'password_confirmation' => 'password12',
            'country' => 'BO',
            'catalog_company_id' => 1,
            'catalog_rank_id' => 10,
        ])->assertCreated();

        Mail::assertSent(WelcomeUserMail::class, function (WelcomeUserMail $mail) {
            return $mail->hasTo('ana-welcome@auth.test');
        });
    }

    public function test_invitation_is_sent_by_email_and_can_be_resent(): void
    {
        $leader = $this->leader('leader-invite@auth.test');
        Sanctum::actingAs($leader);

        $first = $this->postJson('/api/v1/invitations', [
            'email' => 'socio-mail@auth.test',
        ])->assertCreated();

        $this->assertTrue($first->json('email_sent'));
        $this->assertFalse($first->json('resent'));
        Mail::assertSent(InvitationMail::class, 1);

        $second = $this->postJson('/api/v1/invitations', [
            'email' => 'socio-mail@auth.test',
        ])->assertOk();

        $this->assertTrue($second->json('resent'));
        $this->assertNotSame($first->json('token'), $second->json('token'));
        Mail::assertSent(InvitationMail::class, 2);
        $this->assertSame(1, Invitation::query()->where('email', 'socio-mail@auth.test')->count());
    }

    public function test_google_creates_leader_and_sends_welcome_email(): void
    {
        $this->fakeGoogleToken('google-sub-1', 'google-leader@auth.test', 'Google Líder');

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'valid-id-token',
            'country' => 'BO',
            'catalog_company_id' => 1,
            'catalog_rank_id' => 10,
        ])->assertCreated()
            ->assertJsonPath('user.email', 'google-leader@auth.test');

        $this->assertDatabaseHas('users', [
            'email' => 'google-leader@auth.test',
            'google_id' => 'google-sub-1',
        ]);
        Mail::assertSent(WelcomeUserMail::class, fn (WelcomeUserMail $mail) => $mail->hasTo('google-leader@auth.test'));
    }

    public function test_google_logs_in_existing_user_without_new_welcome_mail(): void
    {
        $user = User::factory()->create([
            'email' => 'google-login@auth.test',
            'google_id' => 'google-sub-login',
        ]);
        $user->assignRole('leader');

        $this->fakeGoogleToken('google-sub-login', 'google-login@auth.test', $user->name);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'valid-id-token',
        ])->assertOk()
            ->assertJsonPath('user.email', 'google-login@auth.test');

        Mail::assertNothingSent();
    }

    public function test_google_accepts_invitation_when_emails_match(): void
    {
        $leader = $this->leader('leader-g@auth.test');
        $token = Invitation::generatePlainToken();
        Invitation::query()->create([
            'leader_id' => $leader->id,
            'network_id' => $leader->current_network_id,
            'email' => 'socio-google@auth.test',
            'token_hash' => Invitation::hashToken($token),
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        $this->fakeGoogleToken('google-sub-partner', 'socio-google@auth.test', 'Socio Google');

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'valid-id-token',
            'invitation_token' => $token,
        ])->assertCreated()
            ->assertJsonPath('user.email', 'socio-google@auth.test');

        $this->assertTrue(
            User::query()->where('email', 'socio-google@auth.test')->first()?->hasRole('partner') ?? false
        );
    }

    public function test_google_rejects_unconfigured_client(): void
    {
        config(['services.google.client_id' => '']);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'anything',
        ])->assertUnprocessable();
    }

    public function test_registration_options_expose_google_client_id(): void
    {
        $this->getJson('/api/v1/auth/registration-options')
            ->assertOk()
            ->assertJsonPath('google_client_id', 'test-google-client.apps.googleusercontent.com');
    }

    private function fakeCatalog(): void
    {
        Http::fake([
            'catalog.test/*' => Http::response([
                'data' => [
                    [
                        'id' => 1,
                        'name' => 'HGW Test',
                        'ranks' => [
                            ['id' => 10, 'name' => 'Bronce'],
                        ],
                    ],
                ],
            ], 200),
        ]);
    }

    private function fakeGoogleToken(string $sub, string $email, string $name): void
    {
        Http::fake(function ($request) use ($sub, $email, $name) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/tokeninfo')) {
                return Http::response([
                    'aud' => 'test-google-client.apps.googleusercontent.com',
                    'sub' => $sub,
                    'email' => $email,
                    'email_verified' => 'true',
                    'name' => $name,
                ], 200);
            }

            if (str_contains($request->url(), 'catalog.test')) {
                return Http::response([
                    'data' => [
                        [
                            'id' => 1,
                            'name' => 'HGW Test',
                            'ranks' => [
                                ['id' => 10, 'name' => 'Bronce'],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['message' => 'unmocked '.$request->url()], 500);
        });
    }

    private function leader(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'country' => 'BO',
            'catalog_company_id' => 1,
            'catalog_company_name' => 'HGW Test',
        ]);
        $user->assignRole('leader');
        $network = Network::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Red '.$user->id,
            'slug' => 'net-auth-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();

        return $user->fresh();
    }
}
