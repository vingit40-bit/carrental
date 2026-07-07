<?php
session_start();
require_once '../config/connect.php';

$admin_base = true;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        $conn = getConnection();
        
        // Generate unique task number
        function generateTaskNumber($conn) {
            $year = date('Y');
            $month = date('m');
            $prefix = "MT-{$year}{$month}-";
            
            $sql = "SELECT COUNT(*) as count FROM maintenance_tasks WHERE task_number LIKE '$prefix%'";
            $result = $conn->query($sql);
            $row = $result->fetch_assoc();
            $count = $row['count'] + 1;
            
            return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
        }
        
        // Get Dashboard Statistics
        if ($_POST['action'] === 'get_stats') {
            // Scheduled this week
            $week_start = date('Y-m-d', strtotime('monday this week'));
            $week_end = date('Y-m-d', strtotime('sunday this week'));
            $scheduled_week = $conn->query("SELECT COUNT(*) as count FROM maintenance_tasks WHERE due_date BETWEEN '$week_start' AND '$week_end' AND status IN ('Scheduled', 'In Progress')")->fetch_assoc()['count'];
            
            // In Progress tasks
            $in_progress = $conn->query("SELECT COUNT(*) as count FROM maintenance_tasks WHERE status = 'In Progress'")->fetch_assoc()['count'];
            
            // Completed this month
            $month_start = date('Y-m-01');
            $month_end = date('Y-m-t');
            $completed_month = $conn->query("SELECT COUNT(*) as count FROM maintenance_tasks WHERE status = 'Completed' AND DATE(completion_date) BETWEEN '$month_start' AND '$month_end'")->fetch_assoc()['count'];
            
            // Overdue tasks
            $overdue = $conn->query("SELECT COUNT(*) as count FROM maintenance_tasks WHERE status IN ('Scheduled', 'In Progress') AND due_date < CURDATE()")->fetch_assoc()['count'];
            
            echo json_encode([
                'success' => true,
                'stats' => [
                    'scheduled_week' => $scheduled_week,
                    'in_progress' => $in_progress,
                    'completed_month' => $completed_month,
                    'overdue' => $overdue
                ]
            ]);
        }
        
        // Get Maintenance Schedule
        elseif ($_POST['action'] === 'get_schedule') {
            $priority = isset($_POST['priority']) ? $conn->real_escape_string($_POST['priority']) : 'all';
            
            $where = [];
            if ($priority !== 'all') {
                $where[] = "mt.priority = '$priority'";
            }
            
            $where_clause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";
            
            $sql = "SELECT mt.*, 
                    v.vehicle_name, v.license_plate, v.maintenance_status
                    FROM maintenance_tasks mt
                    JOIN vehicles v ON mt.vehicle_id = v.id
                    $where_clause
                    ORDER BY 
                        CASE 
                            WHEN mt.priority = 'Critical' THEN 1
                            WHEN mt.priority = 'High' THEN 2
                            WHEN mt.priority = 'Medium' THEN 3
                            ELSE 4
                        END,
                        mt.due_date ASC";
            
            $result = $conn->query($sql);
            $tasks = [];
            
            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    // Check if overdue
                    if ($row['status'] == 'Scheduled' && $row['due_date'] && strtotime($row['due_date']) < time()) {
                        $row['is_overdue'] = true;
                    } else {
                        $row['is_overdue'] = false;
                    }
                    $tasks[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'tasks' => $tasks]);
        }
        
        // Get Vehicles for Dropdown
        elseif ($_POST['action'] === 'get_vehicles') {
            $sql = "SELECT id, vehicle_name, license_plate, maintenance_status 
                    FROM vehicles 
                    ORDER BY vehicle_name ASC";
            
            $result = $conn->query($sql);
            $vehicles = [];
            
            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $vehicles[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'vehicles' => $vehicles]);
        }
        
        // Create Maintenance Task
        elseif ($_POST['action'] === 'create_task') {
            $vehicle_id = (int)$_POST['vehicle_id'];
            $task_name = $conn->real_escape_string($_POST['task_name']);
            $description = $conn->real_escape_string($_POST['description']);
            $task_type = $conn->real_escape_string($_POST['task_type']);
            $priority = $conn->real_escape_string($_POST['priority']);
            $due_date = !empty($_POST['due_date']) ? $conn->real_escape_string($_POST['due_date']) : 'NULL';
            $due_mileage = !empty($_POST['due_mileage']) ? (int)$_POST['due_mileage'] : 'NULL';
            $estimated_cost = (float)$_POST['estimated_cost'];
            $assigned_to = $conn->real_escape_string($_POST['assigned_to']);
            
            $task_number = generateTaskNumber($conn);
            
            $sql = "INSERT INTO maintenance_tasks (
                    task_number, vehicle_id, task_name, description, task_type,
                    priority, due_date, due_mileage, estimated_cost, assigned_to, status
                    ) VALUES (
                    '$task_number', $vehicle_id, '$task_name', '$description', '$task_type',
                    '$priority', " . ($due_date !== 'NULL' ? "'$due_date'" : "NULL") . ", 
                    " . ($due_mileage !== 'NULL' ? $due_mileage : "NULL") . ", 
                    $estimated_cost, '$assigned_to', 'Scheduled'
                    )";
            
            if ($conn->query($sql)) {
                // Update vehicle maintenance status and status
                $conn->query("UPDATE vehicles SET maintenance_status = 'Due Soon', status = 'Maintenance' WHERE id = $vehicle_id");
                
                echo json_encode(['success' => true, 'message' => 'Maintenance task created successfully', 'task_number' => $task_number]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
            }
        }
        
        // Update Task Status
        elseif ($_POST['action'] === 'update_task_status') {
            $id = (int)$_POST['id'];
            $status = $conn->real_escape_string($_POST['status']);
            
            $sql = "UPDATE maintenance_tasks SET status = '$status'";
            
            if ($status === 'Completed') {
                $sql .= ", completion_date = NOW()";
            }
            
            $sql .= " WHERE id = $id";
            
            if ($conn->query($sql)) {
                // Get vehicle_id for this task
                $vehicle_sql = "SELECT vehicle_id FROM maintenance_tasks WHERE id = $id";
                $vehicle_result = $conn->query($vehicle_sql);
                $vehicle_id = $vehicle_result->fetch_assoc()['vehicle_id'];
                
                // Check if there are any other pending tasks for this vehicle
                $pending_sql = "SELECT COUNT(*) as count FROM maintenance_tasks WHERE vehicle_id = $vehicle_id AND status IN ('Scheduled', 'In Progress') AND id != $id";
                $pending_result = $conn->query($pending_sql);
                $pending_count = $pending_result->fetch_assoc()['count'];
                
                // Update vehicle maintenance status and status
                if ($status === 'Completed') {
                    if ($pending_count == 0) {
                        $conn->query("UPDATE vehicles SET maintenance_status = 'Good', status = 'Available' WHERE id = $vehicle_id");
                    } else {
                        $conn->query("UPDATE vehicles SET maintenance_status = 'Good' WHERE id = $vehicle_id");
                    }
                } else if ($status === 'In Progress') {
                    $conn->query("UPDATE vehicles SET maintenance_status = 'In Maintenance', status = 'Maintenance' WHERE id = $vehicle_id");
                } else if ($status === 'Scheduled') {
                    $conn->query("UPDATE vehicles SET maintenance_status = 'Due Soon', status = 'Maintenance' WHERE id = $vehicle_id");
                } else if ($status === 'Cancelled') {
                    if ($pending_count == 0) {
                        $conn->query("UPDATE vehicles SET maintenance_status = 'Good', status = 'Available' WHERE id = $vehicle_id");
                    }
                }
                
                echo json_encode(['success' => true, 'message' => 'Task status updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
            }
        }
        
        // Get Task Details
        elseif ($_POST['action'] === 'get_task_details') {
            $id = (int)$_POST['id'];
            
            $sql = "SELECT mt.*, v.vehicle_name, v.license_plate 
                    FROM maintenance_tasks mt
                    JOIN vehicles v ON mt.vehicle_id = v.id
                    WHERE mt.id = $id";
            
            $result = $conn->query($sql);
            
            if ($result && $result->num_rows > 0) {
                $task = $result->fetch_assoc();
                echo json_encode(['success' => true, 'task' => $task]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Task not found']);
            }
        }
        
        $conn->close();
        exit;
    }
}

// Fetch initial statistics
$conn = getConnection();

$scheduled_week = 0;
$in_progress = 0;
$completed_month = 0;
$overdue = 0;

if ($conn) {
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $week_end = date('Y-m-d', strtotime('sunday this week'));
    $scheduled = $conn->query("SELECT COUNT(*) as count FROM maintenance_tasks WHERE due_date BETWEEN '$week_start' AND '$week_end' AND status IN ('Scheduled', 'In Progress')");
    $scheduled_week = $scheduled ? $scheduled->fetch_assoc()['count'] : 0;
    
    $progress = $conn->query("SELECT COUNT(*) as count FROM maintenance_tasks WHERE status = 'In Progress'");
    $in_progress = $progress ? $progress->fetch_assoc()['count'] : 0;
    
    $month_start = date('Y-m-01');
    $month_end = date('Y-m-t');
    $completed = $conn->query("SELECT COUNT(*) as count FROM maintenance_tasks WHERE status = 'Completed' AND DATE(completion_date) BETWEEN '$month_start' AND '$month_end'");
    $completed_month = $completed ? $completed->fetch_assoc()['count'] : 0;
    
    $over = $conn->query("SELECT COUNT(*) as count FROM maintenance_tasks WHERE status IN ('Scheduled', 'In Progress') AND due_date < CURDATE()");
    $overdue = $over ? $over->fetch_assoc()['count'] : 0;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Management - Velocity Rentals</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        html {
            font-size: 14px;
        }
        
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #1f2937;
        }
        ::-webkit-scrollbar-thumb {
            background: #dc2626;
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #b91c1c;
        }

        .card-glow {
            box-shadow:
                0 0 0 1px rgba(255, 255, 255, 0.04),
                0 10px 30px rgba(0, 0, 0, 0.35),
                0 0 22px rgba(255, 255, 255, 0.05);
        }
        .card-glow:hover {
            box-shadow:
                0 0 0 1px rgba(220, 38, 38, 0.28),
                0 14px 34px rgba(0, 0, 0, 0.45),
                0 0 30px rgba(220, 38, 38, 0.18);
        }

        .header-glow {
            box-shadow:
                0 0 0 1px rgba(255, 255, 255, 0.05),
                0 8px 24px rgba(0, 0, 0, 0.4),
                0 0 24px rgba(255, 255, 255, 0.06);
        }

        .light-strip {
            position: relative;
        }
        .light-strip::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(220, 38, 38, 0.5), transparent);
        }

        /* Modal animations */
        .modal-enter {
            animation: modalFadeIn 0.3s ease-out;
        }
        
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* Loading spinner */
        .spinner {
            border: 3px solid rgba(220, 38, 38, 0.1);
            border-top: 3px solid #dc2626;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Status badges */
        .priority-critical { @apply bg-red-500/10 text-red-500; }
        .priority-high { @apply bg-orange-500/10 text-orange-500; }
        .priority-medium { @apply bg-amber-500/10 text-amber-500; }
        .priority-low { @apply bg-emerald-500/10 text-emerald-500; }
        
        .status-scheduled { @apply bg-blue-500/10 text-blue-500; }
        .status-progress { @apply bg-amber-500/10 text-amber-500; }
        .status-completed { @apply bg-emerald-500/10 text-emerald-500; }
        .status-overdue { @apply bg-red-500/10 text-red-500; }
        .status-cancelled { @apply bg-gray-500/10 text-gray-500; }
    </style>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen">
    <?php include '../includes/sidebar.php'; ?>

    <main class="lg:ml-64 min-h-screen">
        <header class="header-glow bg-gray-900/90 backdrop-blur-md border-b border-gray-700/50 sticky top-0 z-30">
            <div class="flex items-center justify-between h-16 px-4 lg:px-6">
                <div class="flex items-center gap-4">
                    <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg hover:bg-gray-800 text-gray-400 hover:text-white transition-colors">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <div class="flex items-center gap-3">
                        <div class="w-1 h-8 bg-gradient-to-b from-red-500 to-red-700 rounded-full"></div>
                        <div>
                            <h1 class="text-xl font-bold text-white">Maintenance Management</h1>
                            <p class="text-xs text-gray-500">Schedule and track vehicle maintenance tasks</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="hidden md:flex items-center bg-gray-800/80 rounded-lg px-3 py-2 border border-gray-600/30">
                        <i class="fas fa-search text-gray-500 text-sm"></i>
                        <input type="text" id="globalSearch" placeholder="Search vehicle or task..." onkeyup="searchTasks()" class="bg-transparent border-none outline-none text-sm text-gray-300 placeholder-gray-500 ml-2 w-56">
                    </div>
                    <button onclick="openTaskModal()" class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-500 hover:to-red-600 text-white rounded-lg text-sm font-medium transition-all shadow-lg shadow-red-600/20">
                        <i class="fas fa-tools"></i>
                        <span>Schedule Maintenance</span>
                    </button>
                </div>
            </div>
        </header>

        <div class="p-4 lg:p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-blue-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-600/30 to-blue-700/10 flex items-center justify-center border border-blue-500/20">
                            <i class="fas fa-calendar-check text-blue-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Scheduled This Week</h3>
                    <p class="text-2xl font-bold text-white" id="scheduledWeek"><?php echo $scheduled_week; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-amber-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-600/30 to-amber-700/10 flex items-center justify-center border border-amber-500/20">
                            <i class="fas fa-tools text-amber-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">In Progress</h3>
                    <p class="text-2xl font-bold text-white" id="inProgress"><?php echo $in_progress; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-emerald-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-600/30 to-emerald-700/10 flex items-center justify-center border border-emerald-500/20">
                            <i class="fas fa-check-circle text-emerald-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Completed This Month</h3>
                    <p class="text-2xl font-bold text-white" id="completedMonth"><?php echo $completed_month; ?></p>
                </div>
                <div class="card-glow bg-gray-900 rounded-xl p-5 border border-gray-700/30 hover:border-red-500/50 transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-600/30 to-red-700/10 flex items-center justify-center border border-red-500/20">
                            <i class="fas fa-exclamation-triangle text-red-500 text-xl"></i>
                        </div>
                    </div>
                    <h3 class="text-gray-400 text-sm font-medium mb-1">Overdue</h3>
                    <p class="text-2xl font-bold text-white" id="overdue"><?php echo $overdue; ?></p>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <button onclick="loadSchedule()" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-calendar-check text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">All Tasks</span>
                </button>
                <button onclick="filterByStatus('Scheduled')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-blue-500/50 rounded-xl transition-all group">
                    <i class="fas fa-clock text-blue-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Scheduled</span>
                </button>
                <button onclick="filterByStatus('In Progress')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-amber-500/50 rounded-xl transition-all group">
                    <i class="fas fa-tools text-amber-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">In Progress</span>
                </button>
                <button onclick="filterByStatus('Overdue')" class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-exclamation-triangle text-red-500 text-xl"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Overdue</span>
                </button>
            </div>

            <!-- Maintenance Schedule Table -->
            <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                <div class="light-strip flex items-center justify-between p-5 border-b border-gray-700/30">
                    <div>
                        <h2 class="text-lg font-semibold text-white">Maintenance Schedule</h2>
                        <p class="text-sm text-gray-500">All maintenance tasks</p>
                    </div>
                    <div class="flex gap-2">
                        <select id="priorityFilter" onchange="filterSchedule()" class="bg-gray-800/80 border border-gray-600/30 text-gray-300 text-sm rounded-lg px-3 py-2 outline-none">
                            <option value="all">All Priorities</option>
                            <option value="Critical">Critical</option>
                            <option value="High">High</option>
                            <option value="Medium">Medium</option>
                            <option value="Low">Low</option>
                        </select>
                        <select id="statusFilter" onchange="filterSchedule()" class="bg-gray-800/80 border border-gray-600/30 text-gray-300 text-sm rounded-lg px-3 py-2 outline-none">
                            <option value="all">All Status</option>
                            <option value="Scheduled">Scheduled</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Completed">Completed</option>
                            <option value="Overdue">Overdue</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-700/30 bg-gray-800/30">
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Task #</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Vehicle</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Task</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Type</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Due Date</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Priority</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Status</th>
                                <th class="text-left text-xs font-medium text-gray-400 uppercase tracking-wider px-5 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="scheduleTableBody" class="divide-y divide-gray-700/30">
                            <tr>
                                <td colspan="8" class="px-5 py-8 text-center text-gray-500">
                                    <div class="spinner"></div>
                                    <p class="mt-2">Loading schedule...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Schedule Maintenance Modal -->
    <div id="taskModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeTaskModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-2xl max-h-[90vh] overflow-y-auto modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700 sticky top-0 bg-gray-900">
                    <h2 class="text-xl font-semibold text-white">Schedule Maintenance Task</h2>
                    <button onclick="closeTaskModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <form id="taskForm" class="p-5 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Select Vehicle</label>
                            <select id="taskVehicleId" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                                <option value="">Choose a vehicle...</option>
                            </select>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Task Name</label>
                            <input type="text" id="taskName" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="e.g., Oil Change, Brake Inspection">
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-400 mb-2">Description</label>
                            <textarea id="taskDescription" rows="2" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="Detailed description..."></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Task Type</label>
                            <select id="taskType" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                                <option value="Preventive">Preventive</option>
                                <option value="Corrective">Corrective</option>
                                <option value="Inspection">Inspection</option>
                                <option value="Repair">Repair</option>
                                <option value="Replacement">Replacement</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Priority</label>
                            <select id="taskPriority" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                                <option value="Low">Low</option>
                                <option value="Medium">Medium</option>
                                <option value="High">High</option>
                                <option value="Critical">Critical</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Due Date (Optional)</label>
                            <input type="date" id="taskDueDate" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Due Mileage (Optional)</label>
                            <input type="number" id="taskDueMileage" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500" placeholder="km">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Estimated Cost (₱)</label>
                            <input type="number" id="taskEstimatedCost" step="0.01" min="0" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white outline-none focus:border-red-500" value="0">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Assigned To</label>
                            <input type="text" id="taskAssignedTo" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500" placeholder="Technician name">
                        </div>
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-4">
                        <button type="button" onclick="closeTaskModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                            <i class="fas fa-save mr-2"></i>Schedule Task
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Task Modal -->
    <div id="viewTaskModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeViewTaskModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-lg modal-enter">
                <div class="flex items-center justify-between p-5 border-b border-gray-700">
                    <h2 class="text-xl font-semibold text-white">Task Details</h2>
                    <button onclick="closeViewTaskModal()" class="text-gray-400 hover:text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div id="taskDetails" class="p-5">
                    <!-- Task details will be loaded here -->
                </div>
                <div class="flex justify-end p-5 border-t border-gray-700">
                    <button onclick="closeViewTaskModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeSuccessModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-md modal-enter">
                <div class="p-5 border-b border-gray-700">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-emerald-500/20 flex items-center justify-center">
                            <i class="fas fa-check-circle text-emerald-500 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white">Success</h3>
                    </div>
                </div>
                <div class="p-5">
                    <p id="successMessage" class="text-gray-300">Operation completed successfully!</p>
                </div>
                <div class="flex justify-end p-5 border-t border-gray-700">
                    <button onclick="closeSuccessModal()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeConfirmModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-gray-700 w-full max-w-md modal-enter">
                <div class="p-5 border-b border-gray-700">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-amber-500/20 flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-amber-500 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white">Confirm Action</h3>
                    </div>
                </div>
                <div class="p-5">
                    <p id="confirmMessage" class="text-gray-300">Are you sure you want to proceed?</p>
                </div>
                <div class="flex justify-end gap-3 p-5 border-t border-gray-700">
                    <button onclick="closeConfirmModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors">Cancel</button>
                    <button id="confirmActionBtn" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            sidebar.classList.toggle('-translate-x-full');
            if (sidebar.classList.contains('-translate-x-full')) {
                overlay.classList.add('hidden');
            } else {
                overlay.classList.remove('hidden');
            }
        }

        // Close sidebar when clicking overlay on mobile
        document.getElementById('sidebar-overlay')?.addEventListener('click', toggleSidebar);

        // Modal functions
        function closeSuccessModal() {
            document.getElementById('successModal').classList.add('hidden');
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').classList.add('hidden');
        }

        function showSuccess(message) {
            document.getElementById('successMessage').textContent = message;
            document.getElementById('successModal').classList.remove('hidden');
            setTimeout(() => {
                closeSuccessModal();
            }, 3000);
        }

        // Load initial data
        document.addEventListener('DOMContentLoaded', function() {
            loadSchedule();
            loadVehicleSelects();
        });

        // Schedule Functions
        function loadSchedule() {
            const priority = document.getElementById('priorityFilter').value;
            const status = document.getElementById('statusFilter').value;
            
            let url = 'action=get_schedule&priority=' + priority;
            
            fetch('maintenance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: url
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displaySchedule(data.tasks, status);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        function displaySchedule(tasks, statusFilter) {
            const tbody = document.getElementById('scheduleTableBody');
            
            // Filter by status if needed
            let filteredTasks = tasks;
            if (statusFilter !== 'all') {
                if (statusFilter === 'Overdue') {
                    filteredTasks = tasks.filter(t => t.is_overdue);
                } else {
                    filteredTasks = tasks.filter(t => t.status === statusFilter);
                }
            }
            
            if (filteredTasks.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="px-5 py-8 text-center text-gray-500">
                            <i class="fas fa-tools text-4xl mb-2 opacity-50"></i>
                            <p>No maintenance tasks found</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            let html = '';
            filteredTasks.forEach(task => {
                const priorityClass = task.priority === 'Critical' ? 'priority-critical' :
                                     task.priority === 'High' ? 'priority-high' :
                                     task.priority === 'Medium' ? 'priority-medium' : 'priority-low';
                
                const statusClass = task.is_overdue ? 'status-overdue' :
                                   task.status === 'Scheduled' ? 'status-scheduled' :
                                   task.status === 'In Progress' ? 'status-progress' :
                                   task.status === 'Completed' ? 'status-completed' : 'status-cancelled';
                
                const statusText = task.is_overdue ? 'Overdue' : task.status;
                
                html += `
                    <tr class="hover:bg-gray-800/40 transition-colors">
                        <td class="px-5 py-4 text-sm font-mono text-gray-300">${task.task_number}</td>
                        <td class="px-5 py-4 text-sm text-white">${task.vehicle_name}<br><span class="text-xs text-gray-500">${task.license_plate}</span></td>
                        <td class="px-5 py-4 text-sm text-gray-300">${task.task_name}</td>
                        <td class="px-5 py-4 text-sm text-gray-300">${task.task_type}</td>
                        <td class="px-5 py-4 text-sm text-gray-300">${task.due_date ? new Date(task.due_date).toLocaleDateString() : '-'}</td>
                        <td class="px-5 py-4"><span class="px-2 py-1 text-xs font-medium ${priorityClass} rounded-full">${task.priority}</span></td>
                        <td class="px-5 py-4"><span class="px-2 py-1 text-xs font-medium ${statusClass} rounded-full">${statusText}</span></td>
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-2">
                                <button onclick="viewTask(${task.id})" class="p-2 text-gray-400 hover:text-blue-500 hover:bg-gray-700 rounded-lg transition-colors" title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <select onchange="updateTaskStatus(${task.id}, this.value)" class="text-xs bg-gray-800 border border-gray-700 rounded px-2 py-1 text-gray-300 outline-none focus:border-red-500">
                                    <option value="Scheduled" ${task.status === 'Scheduled' && !task.is_overdue ? 'selected' : ''}>Scheduled</option>
                                    <option value="In Progress" ${task.status === 'In Progress' ? 'selected' : ''}>In Progress</option>
                                    <option value="Completed" ${task.status === 'Completed' ? 'selected' : ''}>Completed</option>
                                    <option value="Cancelled" ${task.status === 'Cancelled' ? 'selected' : ''}>Cancelled</option>
                                </select>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
        }

        function filterSchedule() {
            loadSchedule();
        }

        function filterByStatus(status) {
            document.getElementById('statusFilter').value = status;
            loadSchedule();
        }

        function updateTaskStatus(id, status) {
            const formData = new FormData();
            formData.append('action', 'update_task_status');
            formData.append('id', id);
            formData.append('status', status);
            
            fetch('maintenance.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccess('Task status updated');
                    loadSchedule();
                }
            });
        }

        function viewTask(id) {
            fetch('maintenance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_task_details&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayTaskDetails(data.task);
                }
            });
        }

        function displayTaskDetails(task) {
            const priorityClass = task.priority === 'Critical' ? 'text-red-500' :
                                 task.priority === 'High' ? 'text-orange-500' :
                                 task.priority === 'Medium' ? 'text-amber-500' : 'text-emerald-500';
            
            const statusClass = task.is_overdue ? 'text-red-500' :
                               task.status === 'Scheduled' ? 'text-blue-500' :
                               task.status === 'In Progress' ? 'text-amber-500' :
                               task.status === 'Completed' ? 'text-emerald-500' : 'text-gray-500';
            
            const details = `
                <div class="space-y-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm text-gray-400">Task Number</p>
                            <p class="text-lg font-mono font-bold text-white">${task.task_number}</p>
                        </div>
                        <span class="px-3 py-1 text-sm font-medium ${statusClass} bg-opacity-10 rounded-full">${task.status}</span>
                    </div>
                    
                    <div class="bg-gray-800/50 p-3 rounded-lg">
                        <p class="text-gray-400 text-sm mb-2">Vehicle Information</p>
                        <p class="text-white font-medium">${task.vehicle_name}</p>
                        <p class="text-xs text-gray-400">License: ${task.license_plate}</p>
                    </div>
                    
                    <div class="bg-gray-800/50 p-3 rounded-lg">
                        <p class="text-gray-400 text-sm mb-2">Task Details</p>
                        <p class="text-white font-medium">${task.task_name}</p>
                        <p class="text-xs text-gray-400 mt-1">${task.description || 'No description'}</p>
                        <p class="text-xs text-gray-400 mt-2">Type: ${task.task_type}</p>
                        <p class="text-xs text-gray-400">Priority: <span class="${priorityClass}">${task.priority}</span></p>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-xs">Due Date</p>
                            <p class="text-sm text-white">${task.due_date ? new Date(task.due_date).toLocaleDateString() : 'Not set'}</p>
                        </div>
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-xs">Due Mileage</p>
                            <p class="text-sm text-white">${task.due_mileage ? task.due_mileage.toLocaleString() + ' km' : 'Not set'}</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-xs">Estimated Cost</p>
                            <p class="text-sm text-white">₱${parseFloat(task.estimated_cost).toFixed(2)}</p>
                        </div>
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-xs">Assigned To</p>
                            <p class="text-sm text-white">${task.assigned_to || 'Unassigned'}</p>
                        </div>
                    </div>
                    
                    ${task.completion_date ? `
                        <div class="bg-gray-800/50 p-3 rounded-lg">
                            <p class="text-gray-400 text-xs">Completed On</p>
                            <p class="text-sm text-white">${new Date(task.completion_date).toLocaleString()}</p>
                        </div>
                    ` : ''}
                </div>
            `;
            
            document.getElementById('taskDetails').innerHTML = details;
            document.getElementById('viewTaskModal').classList.remove('hidden');
        }

        function closeViewTaskModal() {
            document.getElementById('viewTaskModal').classList.add('hidden');
        }

        // Load Vehicle Selects
        function loadVehicleSelects() {
            fetch('maintenance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_vehicles'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const taskSelect = document.getElementById('taskVehicleId');
                    
                    let options = '<option value="">Select vehicle...</option>';
                    data.vehicles.forEach(v => {
                        options += `<option value="${v.id}">${v.vehicle_name} (${v.license_plate})</option>`;
                    });
                    
                    if (taskSelect) taskSelect.innerHTML = options;
                }
            });
        }

        // Task Modal
        function openTaskModal() {
            document.getElementById('taskModal').classList.remove('hidden');
        }

        function closeTaskModal() {
            document.getElementById('taskModal').classList.add('hidden');
            document.getElementById('taskForm').reset();
        }

        // Task Form Submit
        document.getElementById('taskForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'create_task');
            formData.append('vehicle_id', document.getElementById('taskVehicleId').value);
            formData.append('task_name', document.getElementById('taskName').value);
            formData.append('description', document.getElementById('taskDescription').value);
            formData.append('task_type', document.getElementById('taskType').value);
            formData.append('priority', document.getElementById('taskPriority').value);
            formData.append('due_date', document.getElementById('taskDueDate').value);
            formData.append('due_mileage', document.getElementById('taskDueMileage').value);
            formData.append('estimated_cost', document.getElementById('taskEstimatedCost').value);
            formData.append('assigned_to', document.getElementById('taskAssignedTo').value);
            
            fetch('maintenance.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeTaskModal();
                    showSuccess('Task scheduled successfully');
                    loadSchedule();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        });

        // Search
        function searchTasks() {
            const search = document.getElementById('globalSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#scheduleTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(search) || search === '') {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeTaskModal();
                closeViewTaskModal();
                closeSuccessModal();
                closeConfirmModal();
            }
        });
    </script>
</body>
</html>