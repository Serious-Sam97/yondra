<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\BoardRepository;
use App\Infrastructure\Models\Board;
use Illuminate\Support\Facades\Auth;

class BoardModelRepository implements BoardRepository {
	public function index() {
		$user = Auth::user();
		return Board::where('user_id', $user->id)->get();
	}
	
	public function save($request) {
		// TODO: Implement save method
	}
	
	public function update($request) {
		// TODO: Implement update method
	}
	
	public function delete($request) {
		// TODO: Implement delete method
	}
}
