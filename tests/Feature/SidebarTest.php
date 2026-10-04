<?php

namespace Tests\Feature;

use App\Livewire\Projects\Index;
use App\Livewire\Sidebar;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SidebarTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_new_project_appears_after_project_updated_event(): void
    {
        $this->actingAs(User::first());

        $sidebar = Livewire::test(Sidebar::class)->assertDontSee('Perbaikan KBLI');

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Perbaikan KBLI')
            ->call('save')
            ->assertDispatched('project-updated');

        $sidebar->dispatch('project-updated')->assertSee('Perbaikan KBLI');
    }

    public function test_active_project_lists_its_units_and_highlights_current_unit(): void
    {
        $this->get(route('projects.kecamatan', [Project::firstOrFail(), '010']))
            ->assertOk()
            ->assertSeeInOrder(['haruyan', 'batu benawa'])
            ->assertSee('w-0.5 rounded-full bg-brand-600', false);
    }

    public function test_units_are_not_rendered_when_project_is_not_open(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('FASIH Auto Ganti Wilayah OSS')
            ->assertDontSee('batu benawa')
            ->assertDontSee('w-0.5 rounded-full bg-brand-600', false);
    }
}
