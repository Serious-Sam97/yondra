<?php

namespace Database\Seeders;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/*
| Local demo data for Yutopia: two people sharing one project with a couple of
| boards, so the world has walls to show and teammates to walk up to.
|
|   php artisan db:seed --class=YutopiaDemoSeeder
|
| Accounts (local dev only): ana@yutopia.test / bia@yutopia.test,
| password = env YUTOPIA_DEMO_PASSWORD (default "yutopia-demo").
| Remove with: php artisan tinker --execute="App\Infrastructure\Models\User::where('email','like','%@yutopia.test')->delete();"
*/
class YutopiaDemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make(env('YUTOPIA_DEMO_PASSWORD', 'yutopia-demo'));
        $ana = User::updateOrCreate(['email' => 'ana@yutopia.test'], ['name' => 'Ana Demo', 'password' => $password]);
        $bia = User::updateOrCreate(['email' => 'bia@yutopia.test'], ['name' => 'Bia Demo', 'password' => $password]);

        $project = Project::firstOrCreate(['owner_id' => $ana->id, 'name' => 'Yutopia Demo'], ['color' => '#b5562f', 'description' => 'Demo studio']);
        $project->members()->syncWithoutDetaching([$bia->id => ['role' => 'member']]);

        // A sandbox for building (blank maps, rooms, locked doors) without touching the demo studio.
        $lab = Project::firstOrCreate(['owner_id' => $ana->id, 'name' => 'Yutopia Lab'], ['color' => '#6e7b3a', 'description' => 'Build sandbox']);
        $lab->members()->syncWithoutDetaching([$bia->id => ['role' => 'member']]);

        $boards = [
            'Product' => ['DEV', 'scrum', [['Backlog', ['Spatial audio polish', 'Avatar hats']], ['Doing', ['Board walls', 'Knock to enter']], ['Review', ['Lo-fi radio']], ['Done', ['Isometric renderer', 'Login handoff', 'Desk assignment']]]],
            'Sales' => ['SAL', 'kanban', [['Leads', ['Acme Records', 'Tape Co.']], ['Talking', ['Hi-Fi Ltd']], ['Won', ['Studio 84']]]],
        ];
        $pos = 0;
        foreach ($boards as $name => [$prefix, $type, $sections]) {
            $board = Board::firstOrCreate(
                ['project_id' => $project->id, 'name' => $name],
                ['user_id' => $ana->id, 'description' => '', 'position' => $pos++, 'ticket_prefix' => $prefix]
            );
            $board->update(['type' => $type]);
            if ($board->sections()->exists()) {
                continue;
            }
            $n = 1;
            foreach ($sections as $order => [$sectionName, $cards]) {
                $section = Section::create(['board_id' => $board->id, 'name' => $sectionName, 'order' => $order]);
                foreach ($cards as $i => $cardName) {
                    Card::create([
                        'board_id' => $board->id,
                        'section_id' => $section->id,
                        'name' => $cardName,
                        'description' => '',
                        'position' => $i,
                        'ticket_number' => $n++,
                        'assigned_user_id' => $i % 2 ? $bia->id : $ana->id,
                        'is_done' => $sectionName === 'Done',
                    ]);
                }
            }
            $board->update(['next_ticket_number' => $n]);
            $board->sharedWith()->syncWithoutDetaching([$bia->id => ['permission' => 'write']]);
        }
    }
}
