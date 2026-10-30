<?php

namespace Tests\Feature;

use App\Livewire\Account\Security;
use App\Livewire\Auth\Login;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The whole ceremony against the real checks, with a small software authenticator in place of the device. */
class PasskeyTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://media.example.test';

    private const RP = 'media.example.test';

    private \OpenSSLAsymmetricKey $key;

    private string $credentialId;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['passkeys.relying_party_id' => self::RP, 'passkeys.allowed_origins' => [self::ORIGIN], 'passkeys.throttle' => null]);
        // OpenSSL needs some config file, and a portable PHP on Windows has none in its default place.
        $config = tempnam(sys_get_temp_dir(), 'ssl');
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n");
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048, 'config' => $config]);
        unlink($config);
        $this->credentialId = random_bytes(32);
    }

    public function test_a_person_adds_a_passkey_and_signs_in_with_it(): void
    {
        $maya = $this->user('maya');
        $this->register($maya);
        $this->assertSame(1, $maya->passkeys()->count());
        $this->assertSame('My laptop', $maya->passkeys()->first()->name);

        auth()->logout();
        $this->flushSession();

        $this->signIn()->assertOk()->assertJsonStructure(['redirect']);
        $this->assertAuthenticatedAs($maya);
    }

    public function test_a_passkey_made_for_another_site_does_not_sign_in(): void
    {
        $this->register($this->user('maya'));
        auth()->logout();
        $this->flushSession();

        $this->assertFalse($this->signIn(origin: 'https://media-example.evil.test')->isSuccessful());
        $this->assertGuest();
    }

    public function test_a_stopped_account_signs_in_neither_way(): void
    {
        $maya = $this->user('maya');
        $this->register($maya);
        $maya->profile->update(['is_active' => false]);
        auth()->logout();
        $this->flushSession();

        $this->signIn()->assertUnprocessable();
        $this->assertGuest();

        Livewire::test(Login::class)->set('email', $maya->email)->set('password', 'password')->call('login')->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_changing_passkeys_needs_the_password_and_only_ones_own(): void
    {
        $maya = $this->user('maya');
        $this->actingAs($maya)->getJson(route('passkey.registration-options'))->assertStatus(423);

        Livewire::actingAs($maya)->test(Security::class)->set('password', 'wrong')->call('confirmPassword')->assertHasErrors('password');
        $this->assertNull(session('auth.password_confirmed_at'));

        Livewire::actingAs($maya)->test(Security::class)->set('password', 'password')->call('confirmPassword')->assertHasNoErrors();
        $this->actingAs($maya)->getJson(route('passkey.registration-options'))->assertOk();

        $this->register($maya);
        $passkey = $maya->passkeys()->first();

        $fadi = $this->user('fadi');
        $this->actingAs($fadi)->withSession(['auth.password_confirmed_at' => time()])
            ->deleteJson(route('passkey.destroy', $passkey))->assertForbidden();
        $this->assertTrue($passkey->fresh() !== null);

        $this->actingAs($maya)->withSession(['auth.password_confirmed_at' => time()])
            ->deleteJson(route('passkey.destroy', $passkey))->assertSuccessful();
        $this->assertNull($passkey->fresh());
    }

    private function user(string $who): User
    {
        return User::where('email', 'like', $who.'%')->firstOrFail();
    }

    private function register(User $user): void
    {
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $options = $this->getJson(route('passkey.registration-options'))->assertOk()->json('options');

        $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => self::ORIGIN]);
        $details = openssl_pkey_get_details($this->key)['ec'];
        $cose = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20".str_pad($details['x'], 32, "\0", STR_PAD_LEFT)."\x22\x58\x20".str_pad($details['y'], 32, "\0", STR_PAD_LEFT);
        $authData = hash('sha256', self::RP, true)."\x45".pack('N', $this->counter).str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$cose;
        $attestation = "\xA3\x63fmt\x64none\x67attStmt\xA0\x68authData\x59".pack('n', strlen($authData)).$authData;

        $this->postJson(route('passkey.store'), ['name' => 'My laptop', 'credential' => $this->credential([
            'clientDataJSON' => $this->b64($clientData),
            'attestationObject' => $this->b64($attestation),
            'transports' => ['internal'],
        ])])->assertSuccessful();
    }

    private function signIn(string $origin = self::ORIGIN)
    {
        $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');

        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => $origin]);
        $authData = hash('sha256', self::RP, true)."\x05".pack('N', ++$this->counter);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $this->postJson(route('passkey.login'), ['remember' => true, 'credential' => $this->credential([
            'authenticatorData' => $this->b64($authData),
            'clientDataJSON' => $this->b64($clientData),
            'signature' => $this->b64($signature),
        ])]);
    }

    private function credential(array $response): array
    {
        $id = $this->b64($this->credentialId);

        return ['id' => $id, 'rawId' => $id, 'type' => 'public-key', 'response' => $response];
    }

    private function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
