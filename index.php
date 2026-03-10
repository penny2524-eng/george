<?php
// Simple file-based storage
define('TASKS_FILE', __DIR__ . '/tasks.json');

function loadTasks(): array {
    if (!file_exists(TASKS_FILE)) return [];
    $data = json_decode(file_get_contents(TASKS_FILE), true);
    return is_array($data) ? $data : [];
}

function saveTasks(array $tasks): void {
    file_put_contents(TASKS_FILE, json_encode(array_values($tasks), JSON_PRETTY_PRINT));
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title = trim($_POST['title'] ?? '');
        $due_date = trim($_POST['due_date'] ?? '');
        $follow_up_date = trim($_POST['follow_up_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $priority = $_POST['priority'] ?? 'medium';

        if ($title && $due_date) {
            $tasks = loadTasks();
            $tasks[] = [
                'id'             => uniqid('task_', true),
                'title'          => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                'due_date'       => $due_date,
                'follow_up_date' => $follow_up_date,
                'notes'          => htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'),
                'priority'       => $priority,
                'status'         => 'pending',
                'created_at'     => date('Y-m-d H:i:s'),
            ];
            saveTasks($tasks);
            $message = 'Task added successfully!';
            $messageType = 'success';
        } else {
            $message = 'Title and due date are required.';
            $messageType = 'error';
        }
    }

    if ($action === 'update_status') {
        $id     = $_POST['id'] ?? '';
        $status = $_POST['status'] ?? '';
        $allowed = ['pending', 'in_progress', 'completed', 'cancelled'];

        if ($id && in_array($status, $allowed)) {
            $tasks = loadTasks();
            foreach ($tasks as &$task) {
                if ($task['id'] === $id) {
                    $task['status'] = $status;
                    if ($status === 'completed') {
                        $task['completed_at'] = date('Y-m-d H:i:s');
                    }
                    break;
                }
            }
            saveTasks($tasks);
            $message = 'Task status updated!';
            $messageType = 'success';
        }
    }

    if ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        if ($id) {
            $tasks = loadTasks();
            $tasks = array_filter($tasks, fn($t) => $t['id'] !== $id);
            saveTasks($tasks);
            $message = 'Task deleted.';
            $messageType = 'success';
        }
    }
}

// Load tasks and compute stats
$tasks    = loadTasks();
$today    = date('Y-m-d');
$overdue  = array_filter($tasks, fn($t) => $t['due_date'] < $today && $t['status'] === 'pending');
$followUp = array_filter($tasks, fn($t) => !empty($t['follow_up_date']) && $t['follow_up_date'] <= $today && $t['status'] !== 'completed' && $t['status'] !== 'cancelled');

// Filter
$filter = $_GET['filter'] ?? 'all';
$filteredTasks = match($filter) {
    'pending'    => array_filter($tasks, fn($t) => $t['status'] === 'pending'),
    'in_progress'=> array_filter($tasks, fn($t) => $t['status'] === 'in_progress'),
    'completed'  => array_filter($tasks, fn($t) => $t['status'] === 'completed'),
    'overdue'    => $overdue,
    'follow_up'  => $followUp,
    default      => $tasks,
};

// Sort: overdue first, then by due date
usort($filteredTasks, function($a, $b) use ($today) {
    $aOverdue = $a['due_date'] < $today && $a['status'] === 'pending' ? 0 : 1;
    $bOverdue = $b['due_date'] < $today && $b['status'] === 'pending' ? 0 : 1;
    if ($aOverdue !== $bOverdue) return $aOverdue - $bOverdue;
    return strcmp($a['due_date'], $b['due_date']);
});

$priorityColors = ['high' => '#ef4444', 'medium' => '#f59e0b', 'low' => '#10b981'];
$statusLabels   = ['pending' => 'Pending', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
$statusColors   = ['pending' => '#6b7280', 'in_progress' => '#3b82f6', 'completed' => '#10b981', 'cancelled' => '#9ca3af'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Follow-Up Manager</title>
    <!-- Flatpickr calendar picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4f8;
            color: #1e293b;
            min-height: 100vh;
        }

        header {
            background: linear-gradient(135deg, #1e40af, #3b82f6);
            color: #fff;
            padding: 1.25rem 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 2px 8px rgba(0,0,0,.2);
        }
        header h1 { font-size: 1.5rem; font-weight: 700; }
        header span { font-size: 1.75rem; }

        .container { max-width: 1100px; margin: 0 auto; padding: 1.5rem; }

        /* Stats bar */
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            text-align: center;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
            cursor: pointer;
            transition: transform .15s, box-shadow .15s;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.12); }
        .stat-card.active { border: 2px solid #3b82f6; }
        .stat-card .num { font-size: 2rem; font-weight: 700; }
        .stat-card .label { font-size: .8rem; color: #64748b; margin-top: .2rem; }
        .stat-card.overdue .num { color: #ef4444; }
        .stat-card.followup .num { color: #f59e0b; }
        .stat-card.done .num { color: #10b981; }

        /* Flash message */
        .flash {
            padding: .75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: 500;
        }
        .flash.success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .flash.error   { background: #fee2e2; color: #7f1d1d; border: 1px solid #fca5a5; }

        /* Add task form */
        .card {
            background: #fff;
            border-radius: 14px;
            padding: 1.5rem;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
            margin-bottom: 1.5rem;
        }
        .card h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; color: #1e40af; }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .form-grid .full { grid-column: 1 / -1; }

        label { display: block; font-size: .82rem; font-weight: 600; color: #475569; margin-bottom: .3rem; }

        input[type="text"],
        input[type="date"],
        textarea,
        select {
            width: 100%;
            padding: .6rem .85rem;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: .95rem;
            background: #f8fafc;
            transition: border-color .2s;
            font-family: inherit;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #3b82f6;
            background: #fff;
        }
        textarea { resize: vertical; min-height: 80px; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .6rem 1.25rem;
            border: none;
            border-radius: 8px;
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            transition: filter .15s, transform .1s;
            font-family: inherit;
        }
        .btn:hover { filter: brightness(1.08); }
        .btn:active { transform: scale(.97); }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-sm { padding: .35rem .75rem; font-size: .8rem; }
        .btn-ghost { background: #f1f5f9; color: #475569; }
        .btn-danger { background: #fee2e2; color: #991b1b; }

        /* Task list */
        .task-list { display: flex; flex-direction: column; gap: .75rem; }

        .task-item {
            background: #fff;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.07);
            border-left: 4px solid #3b82f6;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: .5rem;
            align-items: start;
            transition: box-shadow .15s;
        }
        .task-item:hover { box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        .task-item.overdue  { border-left-color: #ef4444; background: #fff8f8; }
        .task-item.completed { border-left-color: #10b981; opacity: .75; }
        .task-item.cancelled { border-left-color: #9ca3af; opacity: .65; }

        .task-title {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: .35rem;
        }
        .task-meta {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            font-size: .78rem;
            color: #64748b;
            align-items: center;
        }
        .badge {
            display: inline-block;
            padding: .15rem .55rem;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 700;
            color: #fff;
        }
        .task-notes {
            margin-top: .5rem;
            font-size: .84rem;
            color: #475569;
            background: #f8fafc;
            border-radius: 6px;
            padding: .4rem .65rem;
        }

        .task-actions {
            display: flex;
            flex-direction: column;
            gap: .4rem;
            align-items: flex-end;
        }

        .follow-up-alert {
            background: #fffbeb;
            border: 1.5px solid #fbbf24;
            border-radius: 6px;
            padding: .25rem .6rem;
            font-size: .75rem;
            color: #92400e;
            font-weight: 600;
        }

        .empty {
            text-align: center;
            padding: 3rem 1rem;
            color: #94a3b8;
            font-size: 1rem;
        }

        /* Toggle form */
        #addFormSection { display: none; }
        #addFormSection.open { display: block; }

        @media (max-width: 640px) {
            .form-grid { grid-template-columns: 1fr; }
            .task-item { grid-template-columns: 1fr; }
            .task-actions { flex-direction: row; flex-wrap: wrap; }
        }
    </style>
</head>
<body>

<header>
    <span>📋</span>
    <h1>Task Follow-Up Manager</h1>
</header>

<div class="container">

    <?php if ($message): ?>
    <div class="flash <?= $messageType ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats">
        <?php
        $total     = count($tasks);
        $pending   = count(array_filter($tasks, fn($t) => $t['status'] === 'pending'));
        $inProg    = count(array_filter($tasks, fn($t) => $t['status'] === 'in_progress'));
        $done      = count(array_filter($tasks, fn($t) => $t['status'] === 'completed'));
        $nOverdue  = count($overdue);
        $nFollowUp = count($followUp);
        ?>
        <a href="?filter=all"        class="stat-card <?= $filter === 'all' ? 'active' : '' ?>">
            <div class="num"><?= $total ?></div><div class="label">All Tasks</div>
        </a>
        <a href="?filter=pending"    class="stat-card <?= $filter === 'pending' ? 'active' : '' ?>">
            <div class="num"><?= $pending ?></div><div class="label">Pending</div>
        </a>
        <a href="?filter=in_progress" class="stat-card <?= $filter === 'in_progress' ? 'active' : '' ?>">
            <div class="num"><?= $inProg ?></div><div class="label">In Progress</div>
        </a>
        <a href="?filter=overdue"    class="stat-card overdue <?= $filter === 'overdue' ? 'active' : '' ?>">
            <div class="num"><?= $nOverdue ?></div><div class="label">Overdue</div>
        </a>
        <a href="?filter=follow_up"  class="stat-card followup <?= $filter === 'follow_up' ? 'active' : '' ?>">
            <div class="num"><?= $nFollowUp ?></div><div class="label">Follow-Up Due</div>
        </a>
        <a href="?filter=completed"  class="stat-card done <?= $filter === 'completed' ? 'active' : '' ?>">
            <div class="num"><?= $done ?></div><div class="label">Completed</div>
        </a>
    </div>

    <!-- Add task toggle -->
    <div style="margin-bottom:1rem;">
        <button class="btn btn-primary" onclick="toggleForm()">
            <span id="toggleIcon">➕</span> <span id="toggleLabel">Add New Task</span>
        </button>
    </div>

    <!-- Add task form -->
    <div id="addFormSection">
        <div class="card">
            <h2>📝 New Task</h2>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
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
                            <option value="high">🔴 High</option>
                            <option value="medium" selected>🟡 Medium</option>
                            <option value="low">🟢 Low</option>
                        </select>
                    </div>
                    <div></div>
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
        <h2 style="margin-bottom:1rem;">
            <?php
            $filterLabel = match($filter) {
                'pending'     => '⏳ Pending Tasks',
                'in_progress' => '🔄 In Progress',
                'completed'   => '✅ Completed Tasks',
                'overdue'     => '🚨 Overdue Tasks',
                'follow_up'   => '🔔 Follow-Up Due Today',
                default       => '📋 All Tasks',
            };
            echo $filterLabel;
            ?>
        </h2>

        <?php if (empty($filteredTasks)): ?>
        <div class="empty">No tasks found for this view.</div>
        <?php else: ?>
        <div class="task-list">
            <?php foreach ($filteredTasks as $task):
                $isOverdue = $task['due_date'] < $today && $task['status'] === 'pending';
                $isFollowUp = !empty($task['follow_up_date']) && $task['follow_up_date'] <= $today && !in_array($task['status'], ['completed', 'cancelled']);
                $itemClass = $isOverdue ? 'overdue' : ($task['status'] === 'completed' ? 'completed' : ($task['status'] === 'cancelled' ? 'cancelled' : ''));
                $priorityColor = $priorityColors[$task['priority']] ?? '#6b7280';
                $statusColor   = $statusColors[$task['status']] ?? '#6b7280';
            ?>
            <div class="task-item <?= $itemClass ?>">
                <div>
                    <div class="task-title">
                        <?= htmlspecialchars_decode($task['title']) ?>
                    </div>
                    <div class="task-meta">
                        <span class="badge" style="background:<?= $priorityColor ?>">
                            <?= ucfirst($task['priority']) ?> Priority
                        </span>
                        <span class="badge" style="background:<?= $statusColor ?>">
                            <?= $statusLabels[$task['status']] ?? $task['status'] ?>
                        </span>
                        <span>📅 Due: <strong><?= date('M j, Y', strtotime($task['due_date'])) ?></strong></span>
                        <?php if (!empty($task['follow_up_date'])): ?>
                        <span>🔔 Follow-up: <strong><?= date('M j, Y', strtotime($task['follow_up_date'])) ?></strong></span>
                        <?php endif; ?>
                        <?php if ($isOverdue): ?>
                        <span style="color:#ef4444;font-weight:700;">⚠️ OVERDUE</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isFollowUp): ?>
                    <div class="follow-up-alert" style="margin-top:.5rem;">
                        🔔 Follow-up action required today!
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($task['notes'])): ?>
                    <div class="task-notes"><?= htmlspecialchars_decode($task['notes']) ?></div>
                    <?php endif; ?>
                    <div style="margin-top:.4rem;font-size:.72rem;color:#94a3b8;">
                        Created: <?= date('M j, Y g:i a', strtotime($task['created_at'])) ?>
                        <?php if (!empty($task['completed_at'])): ?>
                        &bull; Completed: <?= date('M j, Y g:i a', strtotime($task['completed_at'])) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="task-actions">
                    <!-- Status update -->
                    <?php if ($task['status'] !== 'completed' && $task['status'] !== 'cancelled'): ?>
                    <form method="POST" style="display:flex;gap:.4rem;flex-wrap:wrap;justify-content:flex-end;">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="id" value="<?= $task['id'] ?>">
                        <?php if ($task['status'] === 'pending'): ?>
                        <button name="status" value="in_progress" class="btn btn-sm btn-ghost">🔄 Start</button>
                        <?php endif; ?>
                        <?php if ($task['status'] === 'in_progress'): ?>
                        <button name="status" value="pending" class="btn btn-sm btn-ghost">⏸ Pause</button>
                        <?php endif; ?>
                        <button name="status" value="completed" class="btn btn-sm" style="background:#d1fae5;color:#065f46;">✅ Done</button>
                        <button name="status" value="cancelled" class="btn btn-sm btn-ghost">✖ Cancel</button>
                    </form>
                    <?php elseif ($task['status'] === 'completed' || $task['status'] === 'cancelled'): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="id" value="<?= $task['id'] ?>">
                        <button name="status" value="pending" class="btn btn-sm btn-ghost">↩ Reopen</button>
                    </form>
                    <?php endif; ?>

                    <!-- Delete -->
                    <form method="POST" onsubmit="return confirm('Delete this task?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $task['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">🗑 Delete</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Flatpickr -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
    // Initialize date pickers
    const pickerConfig = {
        dateFormat: 'Y-m-d',
        allowInput: true,
        disableMobile: false,
    };

    flatpickr('#due_date', {
        ...pickerConfig,
        minDate: 'today',
        onChange: function(selectedDates) {
            // Auto-set follow-up 3 days before due date as a suggestion
            if (selectedDates.length && !document.getElementById('follow_up_date').value) {
                const due = new Date(selectedDates[0]);
                due.setDate(due.getDate() - 3);
                if (due >= new Date()) {
                    followUpPicker.setDate(due);
                }
            }
        }
    });

    const followUpPicker = flatpickr('#follow_up_date', {
        ...pickerConfig,
    });

    // Toggle add form
    function toggleForm() {
        const section = document.getElementById('addFormSection');
        const icon    = document.getElementById('toggleIcon');
        const label   = document.getElementById('toggleLabel');
        const isOpen  = section.classList.toggle('open');
        icon.textContent  = isOpen ? '✖' : '➕';
        label.textContent = isOpen ? 'Close Form' : 'Add New Task';
        if (isOpen) {
            document.getElementById('title').focus();
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    // Auto-open form if just submitted a task with an error
    <?php if ($messageType === 'error'): ?>
    toggleForm();
    <?php endif; ?>
</script>
</body>
</html>
