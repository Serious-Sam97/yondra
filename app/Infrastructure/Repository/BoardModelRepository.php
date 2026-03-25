<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\BoardRepository;
use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\Section;
use Illuminate\Support\Facades\Auth;

class BoardModelRepository implements BoardRepository {
	public function index() {
		$user = Auth::user();
		return Board::where('user_id', $user->id)->get();
	}

	public function show($id) {
		return Board::with(['sections', 'cards'])->findOrFail($id);
	}

	public function save($request) {
		$user = Auth::user();
		$board = Board::create([
			'user_id'     => $user->id,
			'name'        => $request['name'],
			'description' => $request['description'] ?? '',
		]);

		foreach (['To Do', 'In Progress', 'Done'] as $sectionName) {
			Section::create(['board_id' => $board->id, 'name' => $sectionName]);
		}

		return $board->load('sections');
	}
	
	public function update($request) {
		// TODO: Implement update method
	}
	
	public function delete($request) {
		// TODO: Implement delete method
	}
}
