<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_be_listed_created_and_deleted(): void
    {
        $existingUser = User::factory()->create(['name' => 'Ana Existing']);

        Livewire::test('admin.user-manager')
            ->assertSee($existingUser->name)
            ->set('name', 'Bruno Example')
            ->set('email', 'bruno@example.com')
            ->set('password', 'secret-password')
            ->call('saveUser')
            ->assertHasNoErrors()
            ->assertSee('Bruno Example')
            ->assertSee('Usuario creado correctamente.');

        $createdUser = User::query()->where('email', 'bruno@example.com')->firstOrFail();

        Livewire::test('admin.user-manager')
            ->call('deleteUser', $createdUser->id)
            ->assertDontSee('Bruno Example')
            ->assertSee('Usuario eliminado correctamente.');

        $this->assertDatabaseMissing('users', ['id' => $createdUser->id]);
        $this->assertDatabaseHas('users', ['id' => $existingUser->id]);
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test('admin.user-manager')
            ->set('name', 'Another User')
            ->set('email', 'taken@example.com')
            ->set('password', 'secret-password')
            ->call('saveUser')
            ->assertHasErrors(['email' => 'unique']);

        $this->assertDatabaseCount('users', 1);
    }
}
