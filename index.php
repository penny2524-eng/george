<?php
declare(strict_types=1);

// ── PHP 8.1 Backed Enums ──────────────────────────────────────────────────

enum Priority: string {
    case High   = 'high';
    case Medium = 'medium';
    case Low    = 'low';

    public function color(): string {
        return match($this) {
            self::High   => '#ef4444',
            self::Medium => '#f59e0b',
            self::Low    => '#10b981',
        };
    }

    public function label(): string {
        return match($this) {
            self::High   => '🔴 High',
            self::Medium => '🟡 Medium',
            self::Low    => '🟢 Low',
        };
    }
}

enum Status: string {
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';

    public function color(): string {
        return match($this) {
            self::Pending    => '#6b7280',
            self::InProgress => '#3b82f6',
            self::Completed  => '#10b981',
            self::Cancelled  => '#9ca3af',
        };
    }

    public function label(): string {
        return match($this) {
            self::Pending    => 'Pending',
            self::InProgress => 'In Progress',
            self::Completed  => 'Completed',
            self::Cancelled  => 'Cancelled',
        };
    }

    public function isTerminal(): bool {
        return $this === self::Completed || $this === self::Cancelled;
    }
}

// ── Storage ───────────────────────────────────────────────────────────────

const TASKS_FILE = __DIR__ . '/tasks.json';

function loadTasks(): array {
    if (!file_exists(TASKS_FILE)) return [];
    $raw = file_get_contents(TASKS_FILE);
    return ($raw !== false) ? (json_decode($raw, associative: true) ?? []) : [];
}

function saveTasks(array $tasks): void {
    file_put_contents(
        TASKS_FILE,
        json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    );
}

// ── Helpers ───────────────────────────────────────────────────────────────

/** HTML-escape for safe output. Store raw; escape on output. */
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmtDate(string $date): string {
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false ? $d->format('M j, Y') : e($date);
}

function fmtDateTime(string $dt): string {
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dt);
    return $d !== false ? $d->format('M j, Y g:i a') : e($dt);
}

// ── CSRF ──────────────────────────────────────────────────────────────────

session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$csrf = (string) $_SESSION['csrf'];

// ── Actions ───────────────────────────────────────────────────────────────

$flash = null; // ['msg' => string, 'type' => 'success'|'error']

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $flash = ['msg' => 'Invalid security token. Please refresh and try again.', 'type' => 'error'];
    } else {
        $action = $_POST['action'] ?? '';
        $tasks  = loadTasks();

        if ($action === 'add') {
            $title    = trim($_POST['title']          ?? '');
            $dueDate  = trim($_POST['due_date']        ?? '');
            $followUp = trim($_POST['follow_up_date']  ?? '');
            $notes    = trim($_POST['notes']           ?? '');
            $priority = Priority::tryFrom($_POST['priority'] ?? '') ?? Priority::Medium;

            if (!$title || !$dueDate || strtotime($dueDate) === false) {
                $flash = ['msg' => 'Title and a valid due date are required.', 'type' => 'error'];
            } elseif ($followUp !== '' && strtotime($followUp) === false) {
                $flash = ['msg' => 'Invalid follow-up date.', 'type' => 'error'];
            } else {
                $tasks[] = [
                    'id'             => bin2hex(random_bytes(8)),
                    'title'          => $title,       // stored raw; escaped on output
                    'due_date'       => $dueDate,
                    'follow_up_date' => $followUp,
                    'notes'          => $notes,
                    'priority'       => $priority->value,
                    'status'         => Status::Pending->value,
                    'created_at'     => date('Y-m-d H:i:s'),
                ];
                saveTasks($tasks);
                $flash = ['msg' => 'Task added successfully!', 'type' => 'success'];
            }
        } elseif ($action === 'update_status') {
            $id     = $_POST['id']    ?? '';
            $status = Status::tryFrom($_POST['status'] ?? '');

            if ($id !== '' && $status !== null) {
                foreach ($tasks as &$task) {
                    if ($task['id'] === $id) {
                        $task['status'] = $status->value;
                        if ($status === Status::Completed) {
                            $task['completed_at'] = date('Y-m-d H:i:s');
                        } else {
                            unset($task['completed_at']);
                        }
                        break;
                    }
                }
                unset($task);
                saveTasks($tasks);
                $flash = ['msg' => 'Status updated.', 'type' => 'success'];
            }
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            if ($id !== '') {
                $tasks = array_values(array_filter($tasks, fn($t) => $t['id'] !== $id));
                saveTasks($tasks);
                $flash = ['msg' => 'Task deleted.', 'type' => 'success'];
            }
        }
    }
}

// ── View data ─────────────────────────────────────────────────────────────

$tasks  = loadTasks();
$today  = date('Y-m-d');

$isOverdue  = fn(array $t): bool =>
    $t['due_date'] < $today && $t['status'] === Status::Pending->value;

$isFollowUp = fn(array $t): bool =>
    $t['follow_up_date'] !== '' &&
    $t['follow_up_date'] <= $today &&
    !in_array($t['status'], [Status::Completed->value, Status::Cancelled->value], strict: true);

$overdueList  = array_filter($tasks, $isOverdue);
$followUpList = array_filter($tasks, $isFollowUp);

$validFilters = ['all', 'pending', 'in_progress', 'overdue', 'follow_up', 'completed'];
$filter = in_array($_GET['filter'] ?? '', $validFilters, strict: true) ? $_GET['filter'] : 'all';

$view = match ($filter) {
    'pending'     => array_filter($tasks, fn($t) => $t['status'] === Status::Pending->value),
    'in_progress' => array_filter($tasks, fn($t) => $t['status'] === Status::InProgress->value),
    'completed'   => array_filter($tasks, fn($t) => $t['status'] === Status::Completed->value),
    'overdue'     => $overdueList,
    'follow_up'   => $followUpList,
    default       => $tasks,
};

usort($view, fn($a, $b) =>
    ($isOverdue($a) ? 0 : 1) <=> ($isOverdue($b) ? 0 : 1)
    ?: strcmp($a['due_date'], $b['due_date'])
);

$stats = [
    'all'         => count($tasks),
    'pending'     => count(array_filter($tasks, fn($t) => $t['status'] === Status::Pending->value)),
    'in_progress' => count(array_filter($tasks, fn($t) => $t['status'] === Status::InProgress->value)),
    'overdue'     => count($overdueList),
    'follow_up'   => count($followUpList),
    'completed'   => count(array_filter($tasks, fn($t) => $t['status'] === Status::Completed->value)),
];

$filterLabel = match ($filter) {
    'pending'     => '⏳ Pending Tasks',
    'in_progress' => '🔄 In Progress',
    'completed'   => '✅ Completed Tasks',
    'overdue'     => '🚨 Overdue Tasks',
    'follow_up'   => '🔔 Follow-Up Due Today',
    default       => '📋 All Tasks',
};

$statCards = [
    ['key' => 'all',         'label' => 'All Tasks',    'class' => ''],
    ['key' => 'pending',     'label' => 'Pending',      'class' => ''],
    ['key' => 'in_progress', 'label' => 'In Progress',  'class' => ''],
    ['key' => 'overdue',     'label' => 'Overdue',      'class' => 'overdue'],
    ['key' => 'follow_up',   'label' => 'Follow-Up Due','class' => 'followup'],
    ['key' => 'completed',   'label' => 'Completed',    'class' => 'done'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Follow-Up Manager</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root {
            --blue:    #3b82f6;
            --blue-dk: #1e40af;
            --green:   #10b981;
            --red:     #ef4444;
            --amber:   #f59e0b;
            --gray:    #6b7280;
            --bg:      #f0f4f8;
            --surface: #fff;
            --border:  #cbd5e1;
            --text:    #1e293b;
            --muted:   #64748b;
            --radius:  12px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

        header {
            background: linear-gradient(135deg, var(--blue-dk), var(--blue));
            color: #fff;
            padding: 1.25rem 2rem;
            display: flex;
            align-items: center;
            gap: .75rem;
            box-shadow: 0 2px 8px rgba(0,0,0,.2);
        }
        header h1 { font-size: 1.5rem; font-weight: 700; }

        .container { max-width: 1100px; margin: 0 auto; padding: 1.5rem; }

        /* Stats */
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
            text-align: center;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
            text-decoration: none;
            color: inherit;
            display: block;
            transition: transform .15s, box-shadow .15s;
            border: 2px solid transparent;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.12); }
        .stat-card.active { border-color: var(--blue); }
        .stat-card .num   { font-size: 2rem; font-weight: 700; }
        .stat-card .lbl   { font-size: .8rem; color: var(--muted); margin-top: .2rem; }
        .stat-card.overdue  .num { color: var(--red); }
        .stat-card.followup .num { color: var(--amber); }
        .stat-card.done     .num { color: var(--green); }

        /* Flash */
        .flash { padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-weight: 500; }
        .flash.success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .flash.error   { background: #fee2e2; color: #7f1d1d; border: 1px solid #fca5a5; }

        /* Card */
        .card { background: var(--surface); border-radius: 14px; padding: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); margin-bottom: 1.5rem; }
        .card h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; color: var(--blue-dk); }

        /* Form */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-grid .full { grid-column: 1 / -1; }
        label { display: block; font-size: .82rem; font-weight: 600; color: #475569; margin-bottom: .3rem; }

        input[type="text"], textarea, select {
            width: 100%; padding: .6rem .85rem;
            border: 1.5px solid var(--border); border-radius: 8px;
            font-size: .95rem; background: #f8fafc;
            transition: border-color .2s; font-family: inherit;
        }
        input:focus, textarea:focus, select:focus { outline: none; border-color: var(--blue); background: #fff; }
        textarea { resize: vertical; min-height: 80px; }

        /* Buttons */
        .btn {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .6rem 1.25rem; border: none; border-radius: 8px;
            font-size: .9rem; font-weight: 600; cursor: pointer;
            transition: filter .15s, transform .1s; font-family: inherit;
        }
        .btn:hover  { filter: brightness(1.08); }
        .btn:active { transform: scale(.97); }
        .btn-primary { background: var(--blue);  color: #fff; }
        .btn-ghost   { background: #f1f5f9; color: #475569; }
        .btn-danger  { background: #fee2e2; color: #991b1b; }
        .btn-done    { background: #d1fae5; color: #065f46; }
        .btn-sm      { padding: .35rem .75rem; font-size: .8rem; }

        /* Task list */
        .task-list { display: flex; flex-direction: column; gap: .75rem; }
        .task-item {
            background: var(--surface); border-radius: var(--radius);
            padding: 1rem 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,.07);
            border-left: 4px solid var(--blue);
            display: grid; grid-template-columns: 1fr auto;
            gap: .5rem; align-items: start;
            transition: box-shadow .15s;
        }
        .task-item:hover   { box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        .task-item.overdue   { border-left-color: var(--red);   background: #fff8f8; }
        .task-item.completed { border-left-color: var(--green); opacity: .75; }
        .task-item.cancelled { border-left-color: var(--gray);  opacity: .65; }

        .task-title { font-weight: 600; font-size: 1rem; margin-bottom: .35rem; }
        .task-meta  { display: flex; flex-wrap: wrap; gap: .5rem; font-size: .78rem; color: var(--muted); align-items: center; }

        .badge { display: inline-block; padding: .15rem .55rem; border-radius: 999px; font-size: .72rem; font-weight: 700; color: #fff; }

        .task-notes { margin-top: .5rem; font-size: .84rem; color: #475569; background: #f8fafc; border-radius: 6px; padding: .4rem .65rem; }

        .task-actions { display: flex; flex-direction: column; gap: .4rem; align-items: flex-end; }

        .follow-up-alert {
            background: #fffbeb; border: 1.5px solid #fbbf24;
            border-radius: 6px; padding: .25rem .6rem;
            font-size: .75rem; color: #92400e; font-weight: 600; margin-top: .5rem;
        }

        .empty { text-align: center; padding: 3rem 1rem; color: #94a3b8; }

        #addFormSection { display: none; }
        #addFormSection.open { display: block; }

        @media (max-width: 640px) {
            .form-grid  { grid-template-columns: 1fr; }
            .task-item  { grid-template-columns: 1fr; }
            .task-actions { flex-direction: row; flex-wrap: wrap; }
        }
    </style>
</head>
<body>

<header>
    <span aria-hidden="true">📋</span>
    <h1>Task Follow-Up Manager</h1>
</header>

<div class="container">

    <?php if ($flash !== null): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <!-- Stats bar -->
    <div class="stats">
        <?php foreach ($statCards as $card): ?>
        <a href="?filter=<?= e($card['key']) ?>"
           class="stat-card <?= e($card['class']) ?> <?= $filter === $card['key'] ? 'active' : '' ?>">
            <div class="num"><?= $stats[$card['key']] ?></div>
            <div class="lbl"><?= e($card['label']) ?></div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Toggle button -->
    <div style="margin-bottom:1rem;">
        <button class="btn btn-primary" onclick="toggleForm()" id="toggleBtn">
            ➕ Add New Task
        </button>
    </div>

    <!-- Add task form -->
    <div id="addFormSection">
        <div class="card">
            <h2>📝 New Task</h2>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf"   value="<?= e($csrf) ?>">
                <div class="form-grid">
                    <div class="full">
                        <label for="title">Task Title *</label>
                        <input type="text" id="title" name="title" placeholder="Describe the task…" required>
                    </div>
                    <div>
                        <label for="due_date">Due Date *</label>
                        <input type="text" id="due_date" name="due_date" placeholder="Pick due date" required>
                    </div>
                    <div>
                        <label for="follow_up_date">Follow-Up Date</label>
                        <input type="text" id="follow_up_date" name="follow_up_date" placeholder="Pick follow-up date">
                    </div>
                    <div>
                        <label for="priority">Priority</label>
                        <select id="priority" name="priority">
                            <?php foreach (Priority::cases() as $p): ?>
                            <option value="<?= e($p->value) ?>" <?= $p === Priority::Medium ? 'selected' : '' ?>>
                                <?= e($p->label()) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="full">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" placeholder="Additional notes or context…"></textarea>
                    </div>
                    <div class="full" style="display:flex;gap:.75rem;">
                        <button type="submit" class="btn btn-primary">Save Task</button>
                        <button type="button" class="btn btn-ghost" onclick="toggleForm()">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Task list -->
    <div class="card" style="padding:1.25rem;">
        <h2 style="margin-bottom:1rem;"><?= $filterLabel ?></h2>

        <?php if (empty($view)): ?>
        <div class="empty">No tasks found for this view.</div>
        <?php else: ?>
        <div class="task-list">
            <?php foreach ($view as $task):
                $status   = Status::tryFrom($task['status'])   ?? Status::Pending;
                $priority = Priority::tryFrom($task['priority']) ?? Priority::Medium;
                $overdue  = $isOverdue($task);
                $followUp = $isFollowUp($task);
                $itemClass = $overdue ? 'overdue' : match($status) {
                    Status::Completed => 'completed',
                    Status::Cancelled => 'cancelled',
                    default           => '',
                };
            ?>
            <div class="task-item <?= $itemClass ?>">
                <div>
                    <div class="task-title"><?= e($task['title']) ?></div>
                    <div class="task-meta">
                        <span class="badge" style="background:<?= e($priority->color()) ?>">
                            <?= e($priority->label()) ?>
                        </span>
                        <span class="badge" style="background:<?= e($status->color()) ?>">
                            <?= e($status->label()) ?>
                        </span>
                        <span>📅 Due: <strong><?= fmtDate($task['due_date']) ?></strong></span>
                        <?php if ($task['follow_up_date'] !== ''): ?>
                        <span>🔔 Follow-up: <strong><?= fmtDate($task['follow_up_date']) ?></strong></span>
                        <?php endif; ?>
                        <?php if ($overdue): ?>
                        <span style="color:var(--red);font-weight:700;">⚠️ OVERDUE</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($followUp): ?>
                    <div class="follow-up-alert">🔔 Follow-up action required today!</div>
                    <?php endif; ?>
                    <?php if ($task['notes'] !== ''): ?>
                    <div class="task-notes"><?= e($task['notes']) ?></div>
                    <?php endif; ?>
                    <div style="margin-top:.4rem;font-size:.72rem;color:#94a3b8;">
                        Created: <?= fmtDateTime($task['created_at']) ?>
                        <?php if (!empty($task['completed_at'])): ?>
                        &bull; Completed: <?= fmtDateTime($task['completed_at']) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="task-actions">
                    <form method="POST" style="display:flex;gap:.4rem;flex-wrap:wrap;justify-content:flex-end;">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="csrf"   value="<?= e($csrf) ?>">
                        <input type="hidden" name="id"     value="<?= e($task['id']) ?>">
                        <?php if (!$status->isTerminal()): ?>
                            <?php if ($status === Status::Pending): ?>
                            <button name="status" value="in_progress" class="btn btn-sm btn-ghost">🔄 Start</button>
                            <?php else: ?>
                            <button name="status" value="pending"     class="btn btn-sm btn-ghost">⏸ Pause</button>
                            <?php endif; ?>
                            <button name="status" value="completed" class="btn btn-sm btn-done">✅ Done</button>
                            <button name="status" value="cancelled" class="btn btn-sm btn-ghost">✖ Cancel</button>
                        <?php else: ?>
                            <button name="status" value="pending"   class="btn btn-sm btn-ghost">↩ Reopen</button>
                        <?php endif; ?>
                    </form>
                    <form method="POST" onsubmit="return confirm('Delete this task?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="csrf"   value="<?= e($csrf) ?>">
                        <input type="hidden" name="id"     value="<?= e($task['id']) ?>">
                        <button type="submit" class="btn btn-sm btn-danger">🗑 Delete</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
    const pickerCfg = { dateFormat: 'Y-m-d', allowInput: true };
    const followUpPicker = flatpickr('#follow_up_date', pickerCfg);

    flatpickr('#due_date', {
        ...pickerCfg,
        minDate: 'today',
        onChange([date]) {
            if (!date || document.getElementById('follow_up_date').value) return;
            const suggest = new Date(date);
            suggest.setDate(suggest.getDate() - 3);
            if (suggest >= new Date()) followUpPicker.setDate(suggest);
        },
    });

    function toggleForm() {
        const section = document.getElementById('addFormSection');
        const btn     = document.getElementById('toggleBtn');
        const open    = section.classList.toggle('open');
        btn.textContent = open ? '✖ Close Form' : '➕ Add New Task';
        if (open) {
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            document.getElementById('title').focus();
        }
    }

    <?php if ($flash !== null && $flash['type'] === 'error'): ?>
    toggleForm();
    <?php endif; ?>
</script>
</body>
</html>
