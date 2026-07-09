<?php

namespace App\Services\Vortex;

use App\Infrastructure\Models\Board;
use App\Infrastructure\Models\BoardShare;
use App\Infrastructure\Models\Card;
use App\Infrastructure\Models\CardComment;
use App\Infrastructure\Models\CardImage;
use App\Infrastructure\Models\Project;
use App\Infrastructure\Models\Section;
use App\Infrastructure\Models\User;

/**
 * Single source of truth for the Vortex data explorers: which models are
 * browsable, which columns can be searched/sorted/edited, and what to
 * eager-load on drill-down. Anything not declared here is not reachable.
 */
class EntityRegistry
{
    public const ENTITIES = [
        'users' => [
            'model' => User::class,
            'label' => 'Users',
            'searchable' => ['name', 'email'],
            'editable' => ['name', 'email'],
            'sortable' => ['id', 'name', 'email', 'created_at'],
            'default_sort' => ['created_at', 'desc'],
            'with' => [],
            'counts' => ['boards'],
        ],
        'projects' => [
            'model' => Project::class,
            'label' => 'Projects',
            'searchable' => ['name', 'description'],
            'editable' => ['name', 'description', 'color'],
            'sortable' => ['id', 'name', 'created_at'],
            'default_sort' => ['created_at', 'desc'],
            'with' => ['owner:id,name,email'],
            'counts' => ['boards', 'members'],
        ],
        'boards' => [
            'model' => Board::class,
            'label' => 'Boards',
            'searchable' => ['name', 'description'],
            'editable' => ['name', 'description', 'type', 'archived_at'],
            'sortable' => ['id', 'name', 'type', 'created_at'],
            'default_sort' => ['created_at', 'desc'],
            'with' => ['owner:id,name,email', 'project:id,name'],
            'counts' => ['cards', 'sections', 'sharedWith'],
        ],
        'cards' => [
            'model' => Card::class,
            'label' => 'Cards',
            'searchable' => ['name', 'description', 'ticket_number'],
            'editable' => ['name', 'description', 'priority', 'archived_at'],
            'sortable' => ['id', 'name', 'priority', 'due_date', 'created_at'],
            'default_sort' => ['created_at', 'desc'],
            'with' => ['board:id,name', 'section:id,name', 'assignedUser:id,name'],
            'counts' => ['comments', 'images', 'checklistItems'],
        ],
        'sections' => [
            'model' => Section::class,
            'label' => 'Sections',
            'searchable' => ['name'],
            'editable' => ['name'],
            'sortable' => ['id', 'name', 'order'],
            'default_sort' => ['id', 'desc'],
            'with' => ['board:id,name'],
            'counts' => ['cards'],
        ],
        'files' => [
            'model' => CardImage::class,
            'label' => 'Files',
            'searchable' => ['original_name', 'mime_type'],
            'editable' => [],
            'sortable' => ['id', 'original_name', 'size', 'created_at'],
            'default_sort' => ['created_at', 'desc'],
            'with' => ['card:id,name,board_id', 'uploader:id,name'],
            'counts' => [],
        ],
        'comments' => [
            'model' => CardComment::class,
            'label' => 'Comments',
            'searchable' => ['body'],
            'editable' => [],
            'sortable' => ['id', 'created_at'],
            'default_sort' => ['created_at', 'desc'],
            'with' => ['user:id,name', 'card:id,name'],
            'counts' => [],
        ],
        'shares' => [
            'model' => BoardShare::class,
            'label' => 'Shares',
            'searchable' => [],
            'editable' => ['permission'],
            'sortable' => ['id', 'permission', 'created_at'],
            'default_sort' => ['id', 'desc'],
            'with' => ['user:id,name,email', 'board:id,name'],
            'counts' => [],
        ],
    ];

    /** @return array<string, mixed> */
    public static function get(string $slug): array
    {
        abort_unless(array_key_exists($slug, self::ENTITIES), 404, "Unknown entity: {$slug}");

        return self::ENTITIES[$slug];
    }
}
