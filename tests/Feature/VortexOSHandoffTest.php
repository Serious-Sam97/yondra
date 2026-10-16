<?php

use App\Infrastructure\Models\User;

// Sign in to VortexOS with Yondra (yutopia/docs/plan/19-vortexos.md): a
// one-time code for the person at the desk, redeemed once by VortexOS's
// backend over a signed call.

beforeEach(function () {
    config(['vortexos.shared_secret' => 'vortexos-secret-for-tests-0123456789ab']);
});

function vortexosRedeem($test, array $body, ?string $secret = null, ?int $ts = null)
{
    $raw = json_encode($body);
    $ts ??= time();
    $sig = hash_hmac('sha256', $ts.'.'.$raw, $secret ?? config('vortexos.shared_secret'));

    return $test->call('POST', '/api/internal/vortexos/handoff', [], [], [], [
        'HTTP_X_VORTEXOS_TIMESTAMP' => (string) $ts,
        'HTTP_X_VORTEXOS_SIGNATURE' => $sig,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $raw);
}

test('a code is minted for the signed-in person and redeemed once', function () {
    $ana = User::factory()->create(['name' => 'Ana Demo']);
    $code = $this->actingAs($ana)->postJson('/api/vortexos/handoff')->assertOk()->json('code');
    expect($code)->toHaveLength(48);

    vortexosRedeem($this, ['code' => $code])->assertOk()->assertExactJson(['id' => $ana->id, 'name' => 'Ana Demo']);
    // used: gone
    vortexosRedeem($this, ['code' => $code])->assertNotFound();
});

test('minting a code needs a Yondra session', function () {
    $this->postJson('/api/vortexos/handoff')->assertUnauthorized();
});

test('redeeming needs the shared secret, a fresh timestamp and a real code', function () {
    $ana = User::factory()->create();
    $code = $this->actingAs($ana)->postJson('/api/vortexos/handoff')->json('code');

    vortexosRedeem($this, ['code' => $code], 'not-the-secret')->assertUnauthorized();
    vortexosRedeem($this, ['code' => $code], null, time() - 3600)->assertUnauthorized();
    vortexosRedeem($this, ['code' => str_repeat('x', 48)])->assertNotFound();
    // still good after the refused tries
    vortexosRedeem($this, ['code' => $code])->assertOk();
});

test('with no shared secret configured nobody can redeem', function () {
    $ana = User::factory()->create();
    $code = $this->actingAs($ana)->postJson('/api/vortexos/handoff')->json('code');
    config(['vortexos.shared_secret' => '']);
    vortexosRedeem($this, ['code' => $code], 'anything')->assertUnauthorized();
});
