<?php
$admin_base = true;
require_once '../config/connect.php';

// Handle AJAX requests for settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $conn = getConnection();
    
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit;
    }
    
    if ($_POST['action'] === 'save_settings') {
        $settings = json_decode($_POST['settings'] ?? '{}', true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success' => false, 'error' => 'Invalid settings data']);
            $conn->close();
            exit;
        }
        
        $success = true;
        $errors = [];
        
        foreach ($settings as $key => $value) {
            $key_escaped = $conn->real_escape_string($key);
            $value_escaped = $conn->real_escape_string($value);
            
            $sql = "INSERT INTO settings (setting_key, setting_value) 
                    VALUES ('$key_escaped', '$value_escaped')
                    ON DUPLICATE KEY UPDATE setting_value = '$value_escaped'";
            
            if (!$conn->query($sql)) {
                $success = false;
                $errors[] = $conn->error;
            }
        }
        
        if ($success) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
        }
    }
    
    elseif ($_POST['action'] === 'get_settings') {
        $result = $conn->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        
        // Set default values if not exist
        $defaults = [
            'company_name' => 'Velocity Rentals',
            'company_email' => 'admin@velocityrentals.com',
            'company_phone' => '+1 555-100-2000',
            'company_tax_id' => 'VR-2026-9988',
            'company_address' => '100 Fleet Avenue, New York, NY',
            'terms_conditions' => "1. The renter is responsible for returning the vehicle in the same condition as released, excluding normal wear and tear.\n\n2. Late returns are subject to hourly charges based on the rental policy.\n\n3. Any damage, traffic violation, or legal issue incurred during the rental period is chargeable to the renter.\n\n4. Security deposits are refundable after post-return inspection and clearance.\n\n5. By proceeding, the renter agrees to all policies stated by Velocity Rentals.",
            'privacy_policy' => '',
            'rental_policy' => '',
            'cancellation_policy' => ''
        ];
        
        foreach ($defaults as $key => $value) {
            if (!isset($settings[$key])) {
                $settings[$key] = $value;
            }
        }
        
        echo json_encode(['success' => true, 'settings' => $settings]);
    }
    
    $conn->close();
    exit;
}

// Fetch settings for initial page load
$conn = getConnection();
$settings = [];

if ($conn) {
    $result = $conn->query("SELECT setting_key, setting_value FROM settings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    $conn->close();
}

// Default values
$defaults = [
    'company_name' => 'Velocity Rentals',
    'company_email' => 'admin@velocityrentals.com',
    'company_phone' => '+1 555-100-2000',
    'company_tax_id' => 'VR-2026-9988',
    'company_address' => '100 Fleet Avenue, New York, NY',
    'terms_conditions' => "1. The renter is responsible for returning the vehicle in the same condition as released, excluding normal wear and tear.\n\n2. Late returns are subject to hourly charges based on the rental policy.\n\n3. Any damage, traffic violation, or legal issue incurred during the rental period is chargeable to the renter.\n\n4. Security deposits are refundable after post-return inspection and clearance.\n\n5. By proceeding, the renter agrees to all policies stated by Velocity Rentals."
];

foreach ($defaults as $key => $value) {
    if (!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Velocity Rentals</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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

        html {
            font-size: 14px;
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
                            <h1 class="text-xl font-bold text-white">System Settings</h1>
                            <p class="text-xs text-gray-500">Configure company, policy, legal, and data settings</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <button onclick="saveSettings()" class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-500 hover:to-red-600 text-white rounded-lg text-sm font-medium transition-all shadow-lg shadow-red-600/20">
                        <i class="fas fa-save"></i>
                        <span>Save Settings</span>
                    </button>
                </div>
            </div>
        </header>

        <div class="p-4 lg:p-6">
            <div class="grid grid-cols-2 md:grid-cols-2 gap-3 mb-6">
                <button class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-building text-red-500"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Company Information</span>
                </button>
                <button class="card-glow flex flex-col items-center gap-2 p-4 bg-gray-900 hover:bg-gray-800 border border-gray-700/30 hover:border-red-500/50 rounded-xl transition-all group">
                    <i class="fas fa-file-contract text-red-500"></i>
                    <span class="text-xs font-medium text-gray-400 group-hover:text-white">Terms & Conditions</span>
                </button>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Company Information</h2>
                        <p class="text-sm text-gray-500">Core company profile and branding</p>
                    </div>
                    <form class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <input type="text" id="company_name" placeholder="Company Name" value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>" class="bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500">
                        <input type="email" id="company_email" placeholder="Email Address" value="<?php echo htmlspecialchars($settings['company_email'] ?? ''); ?>" class="bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500">
                        <input type="text" id="company_phone" placeholder="Phone Number" value="<?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?>" class="bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500">
                        <input type="text" id="company_tax_id" placeholder="Tax ID" value="<?php echo htmlspecialchars($settings['company_tax_id'] ?? ''); ?>" class="bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500">
                        <textarea id="company_address" placeholder="Business Address" class="md:col-span-2 h-24 bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white placeholder-gray-500 outline-none focus:border-red-500"><?php echo htmlspecialchars($settings['company_address'] ?? ''); ?></textarea>
                    </form>
                </div>

                <div class="card-glow bg-gray-900 rounded-xl border border-gray-700/30">
                    <div class="light-strip p-5 border-b border-gray-700/30">
                        <h2 class="text-lg font-semibold text-white">Terms & Conditions</h2>
                        <p class="text-sm text-gray-500">Customer-facing legal clauses</p>
                    </div>
                    <div class="p-5">
                        <textarea id="terms_conditions" class="w-full h-72 bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 text-sm text-gray-300 outline-none focus:border-red-500"><?php echo htmlspecialchars($settings['terms_conditions'] ?? ''); ?></textarea>
                    </div>
                </div>
                
            </div>

        </div>
    </main>
    
    <script>
    // Success Modal functions
    function closeSuccessModal() {
        document.getElementById('successModal').classList.add('hidden');
    }

    function showSuccess(message) {
        document.getElementById('successMessage').textContent = message;
        document.getElementById('successModal').classList.remove('hidden');
        setTimeout(() => {
            closeSuccessModal();
        }, 3000);
    }

    function showError(message) {
        document.getElementById('errorMessage').textContent = message;
        document.getElementById('errorModal').classList.remove('hidden');
        setTimeout(() => {
            closeErrorModal();
        }, 3000);
    }

    function closeErrorModal() {
        document.getElementById('errorModal').classList.add('hidden');
    }

    async function saveSettings() {
        const settings = {
            company_name: document.getElementById('company_name').value,
            company_email: document.getElementById('company_email').value,
            company_phone: document.getElementById('company_phone').value,
            company_tax_id: document.getElementById('company_tax_id').value,
            company_address: document.getElementById('company_address').value,
            terms_conditions: document.getElementById('terms_conditions').value
        };
        
        try {
            const formData = new FormData();
            formData.append('action', 'save_settings');
            formData.append('settings', JSON.stringify(settings));
            
            const response = await fetch('settings.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                showSuccess('Settings saved successfully!');
            } else {
                showError('Failed to save settings');
            }
        } catch (error) {
            console.error('Error saving settings:', error);
            showError('Error saving settings: ' + error.message);
        }
    }
    </script>
    
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
    
    <!-- Error Modal -->
    <div id="errorModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/70 backdrop-blur-sm" onclick="closeErrorModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-gray-900 rounded-2xl border border-red-500/50 w-full max-w-md modal-enter">
                <div class="p-5 border-b border-gray-700">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-red-500/20 flex items-center justify-center">
                            <i class="fas fa-exclamation-circle text-red-500 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white">Error</h3>
                    </div>
                </div>
                <div class="p-5">
                    <p id="errorMessage" class="text-gray-300">An error occurred!</p>
                </div>
                <div class="flex justify-end p-5 border-t border-gray-700">
                    <button onclick="closeErrorModal()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">OK</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
