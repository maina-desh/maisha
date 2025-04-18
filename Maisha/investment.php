<?php
// Define investment data
$investments = [
    [
        'name' => 'Leased Lands',
        'monthly_earnings' => 500000,
        'expenses' => 100000,
        'net_profit' => 400000
    ],
    [
        'name' => 'Farming',
        'monthly_earnings' => 800000,
        'expenses' => 300000,
        'net_profit' => 500000
    ],
    [
        'name' => 'Vehicles',
        'monthly_earnings' => 600000,
        'expenses' => 200000,
        'net_profit' => 400000
    ],
    [
        'name' => 'Rental Buildings',
        'monthly_earnings' => 1000000,
        'expenses' => 400000,
        'net_profit' => 600000
    ]
];

// Calculate totals
$total_earnings = 0;
$total_expenses = 0;
$total_profit = 0;

foreach ($investments as $investment) {
    $total_earnings += $investment['monthly_earnings'];
    $total_expenses += $investment['expenses'];
    $total_profit += $investment['net_profit'];
}

// Function to format currency
function formatCurrency($amount) {
    return 'Ksh ' . number_format($amount);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maisha Sacco Investments</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .container {
            width: 80%;
            margin: auto;
            overflow: hidden;
        }
        header {
            background: #008000;
            color: #fff;
            padding: 20px;
            text-align: center;
        }
        .investment {
            background: #fff;
            margin: 20px 0;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .investment h2 {
            color: #008000;
        }
        .investment p {
            font-size: 16px;
        }
        .summary {
            background: #008000;
            color: white;
            padding: 15px;
            text-align: center;
            margin-top: 20px;
            border-radius: 5px;
        }
        .totals {
            background: #004d00;
            color: white;
            padding: 15px;
            text-align: center;
            margin-top: 20px;
            border-radius: 5px;
        }
        .login-form {
            background: #fff;
            margin: 20px 0;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .btn {
            background: #008000;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn:hover {
            background: #006400;
        }
    </style>
</head>
<body>
    <header>
        <h1>Maisha Sacco Investment Report</h1>
        <p><?php echo date('F j, Y'); ?></p>
    </header>
    
    <div class="container">
        <?php if (!isset($_SESSION['logged_in'])): ?>
        <!-- Login Form -->
        <div class="login-form">
            <h2>Member Login</h2>
            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="form-group">
                    <label for="member_id">Member ID:</label>
                    <input type="text" id="member_id" name="member_id" required>
                </div>
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn">Login</button>
            </form>
        </div>
        <?php else: ?>
        
        <!-- Display investments -->
        <?php foreach ($investments as $investment): ?>
        <div class="investment">
            <h2><?php echo $investment['name']; ?></h2>
            <p><strong>Monthly Earnings:</strong> <?php echo formatCurrency($investment['monthly_earnings']); ?></p>
            <p><strong>Expenses:</strong> <?php echo formatCurrency($investment['expenses']); ?></p>
            <p><strong>Net Profit:</strong> <?php echo formatCurrency($investment['net_profit']); ?></p>
        </div>
        <?php endforeach; ?>
        
        <!-- Totals Section -->
        <div class="totals">
            <h2>Investment Totals</h2>
            <p><strong>Total Monthly Earnings:</strong> <?php echo formatCurrency($total_earnings); ?></p>
            <p><strong>Total Expenses:</strong> <?php echo formatCurrency($total_expenses); ?></p>
            <p><strong>Total Net Profit:</strong> <?php echo formatCurrency($total_profit); ?></p>
        </div>
        
        <div class="summary">
            <h2>Profit Distribution</h2>
            <p>Each member receives a share based on their investment contribution.</p>
            <p>Last Distribution Date: <?php echo date('F j, Y', strtotime('-1 month')); ?></p>
            <?php
            // Example member distribution calculation
            $member_share = isset($_GET['member_share']) ? (float)$_GET['member_share'] : 0.05; // 5% by default
            $member_distribution = $total_profit * $member_share;
            ?>
            <p><strong>Your Current Share:</strong> <?php echo formatCurrency($member_distribution); ?></p>
        </div>
        <?php endif; ?>
    </div>
    
    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Maisha Sacco. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>