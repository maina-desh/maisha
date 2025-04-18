<?php
// Start session management
session_start();

// Check if user is logged in, if not redirect to login page
if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Database connection
$servername = "localhost";
$username = "maisha_user";
$password = "secure_password_123";
$dbname = "maisha_sacco";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch user data
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM members WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Get user's last login
$last_login = isset($_SESSION['last_login']) ? $_SESSION['last_login'] : "First time login";

// Update current login time
$_SESSION['last_login'] = date("F j, Y \a\\t g:i A");

// Handle logout
if(isset($_GET['logout'])) {
    // Destroy session
    session_destroy();
    // Redirect to login page
    header("Location: login.php");
    exit();
}

// Fetch user's account summary
$sql_account = "SELECT balance, total_contributions FROM accounts WHERE member_id = ?";
$stmt_account = $conn->prepare($sql_account);
$stmt_account->bind_param("i", $user_id);
$stmt_account->execute();
$result_account = $stmt_account->get_result();
$account = $result_account->fetch_assoc();

// Fetch user's active loans
$sql_loans = "SELECT COUNT(*) as active_loans FROM loans WHERE member_id = ? AND status = 'active'";
$stmt_loans = $conn->prepare($sql_loans);
$stmt_loans->bind_param("i", $user_id);
$stmt_loans->execute();
$result_loans = $stmt_loans->get_result();
$loans = $result_loans->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maisha Sacco - Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        /* Global Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f0f2f5;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            width: 95%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Header Styles */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #ffffff;
            padding: 15px 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .logo {
            display: flex;
            align-items: center;
        }
        
        .logo img {
            width: 50px;
            height: 50px;
            margin-right: 15px;
        }
        
        .logo h1 {
            color: #006a4e;
            font-size: 1.8rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #006a4e;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 15px;
        }
        
        .user-name {
            font-weight: 600;
        }
        
        .logout-btn {
            background-color: #f44336;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            margin-left: 20px;
            transition: background-color 0.3s;
        }
        
        .logout-btn:hover {
            background-color: #d32f2f;
        }
        
        /* Dashboard Cards */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }
        
        .dashboard-card {
            background-color: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: pointer;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
        }
        
        .card-header {
            height: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
        }
        
        .card-body {
            padding: 20px;
            text-align: center;
        }
        
        .card-body h3 {
            margin-bottom: 10px;
            font-size: 1.5rem;
            color: #333;
        }
        
        .card-body p {
            color: #666;
            margin-bottom: 15px;
        }
        
        .card-link {
            display: inline-block;
            background-color: #006a4e;
            color: white;
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 600;
            transition: background-color 0.3s;
        }
        
        .card-link:hover {
            background-color: #004d3a;
        }
        
        /* Card Colors */
        .rules-card .card-header {
            background-color: #3f51b5;
        }
        
        .members-card .card-header {
            background-color: #009688;
        }
        
        .loans-card .card-header {
            background-color: #ff9800;
        }
        
        .investments-card .card-header {
            background-color: #9c27b0;
        }
        
        /* Account Summary */
        .account-summary {
            background-color: #ffffff;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .account-summary h3 {
            color: #006a4e;
            margin-bottom: 15px;
            font-size: 1.3rem;
        }
        
        .account-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .account-item {
            padding: 15px;
            background-color: #f5f5f5;
            border-radius: 8px;
            text-align: center;
        }
        
        .account-item h4 {
            color: #555;
            margin-bottom: 5px;
        }
        
        .account-item p {
            font-size: 1.2rem;
            font-weight: 600;
            color: #006a4e;
        }
        
        /* Responsive Design */
        @media screen and (max-width: 768px) {
            .header {
                flex-direction: column;
                text-align: center;
                padding: 15px;
            }
            
            .logo {
                margin-bottom: 15px;
            }
            
            .user-info {
                flex-direction: column;
            }
            
            .user-avatar {
                margin-right: 0;
                margin-bottom: 10px;
            }
            
            .logout-btn {
                margin-left: 0;
                margin-top: 15px;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .account-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Welcome Message */
        .welcome-message {
            background-color: #e8f5e9;
            border-left: 5px solid #4caf50;
            padding: 15px 20px;
            border-radius: 5px;
            margin-bottom: 30px;
        }
        
        .welcome-message h2 {
            color: #2e7d32;
            margin-bottom: 5px;
        }
        
        .welcome-message p {
            color: #555;
        }
        
        /* Footer */
        footer {
            margin-top: 50px;
            text-align: center;
            color: #666;
            font-size: 0.9rem;
        }
        
        footer p {
            margin-bottom: 10px;
        }
        
        /* Notification */
        .notification {
            padding: 15px;
            background-color: #fff3cd;
            border-left: 5px solid #ffc107;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .notification h4 {
            color: #856404;
            margin-bottom: 5px;
        }
        
        .notification p {
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo">
                <img src="/api/placeholder/50/50" alt="Maisha Sacco Logo">
                <h1>Maisha Sacco</h1>
            </div>
            <div class="user-info">
                <div class="user-avatar"><?php echo substr($user['firstname'], 0, 1) . substr($user['lastname'], 0, 1); ?></div>
                <span class="user-name"><?php echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname']); ?></span>
                <a href="dashboard.php?logout=1" class="logout-btn">Logout</a>
            </div>
        </div>
        
        <!-- Welcome Message -->
        <div class="welcome-message">
            <h2>Welcome back, <?php echo htmlspecialchars($user['firstname']); ?>!</h2>
            <p>You last logged in on <?php echo htmlspecialchars($last_login); ?>. Click on any card below to access its information.</p>
        </div>
        
        <?php if(isset($user['membership_anniversary']) && date('Y-m-d') === $user['membership_anniversary']): ?>
        <!-- Anniversary Notification -->
        <div class="notification">
            <h4>Happy Anniversary!</h4>
            <p>Today marks your <?php echo date('Y') - date('Y', strtotime($user['join_date'])); ?> year(s) with Maisha Sacco. Thank you for your continued membership!</p>
        </div>
        <?php endif; ?>
        
        <!-- Account Summary -->
        <div class="account-summary">
            <h3>Account Summary</h3>
            <div class="account-grid">
                <div class="account-item">
                    <h4>Current Balance</h4>
                    <p>KES <?php echo number_format($account['balance'] ?? 0, 2); ?></p>
                </div>
                <div class="account-item">
                    <h4>Total Contributions</h4>
                    <p>KES <?php echo number_format($account['total_contributions'] ?? 0, 2); ?></p>
                </div>
                <div class="account-item">
                    <h4>Active Loans</h4>
                    <p><?php echo $loans['active_loans'] ?? 0; ?></p>
                </div>
                <div class="account-item">
                    <h4>Member Since</h4>
                    <p><?php echo date('M Y', strtotime($user['join_date'] ?? date('Y-m-d'))); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Dashboard Cards -->
        <div class="dashboard-grid">
            <!-- Sacco Rules Card -->
            <div class="dashboard-card rules-card" onclick="window.location.href='rules.php'">
                <div class="card-header">
                    <i class="fas fa-book"></i>
                </div>
                <div class="card-body">
                    <h3>Sacco Rules</h3>
                    <p>View our terms, conditions, and operating guidelines.</p>
                    <a href="rules.php" class="card-link">View Rules</a>
                </div>
            </div>
            
            <!-- Members Card -->
            <div class="dashboard-card members-card" onclick="window.location.href='members.php'">
                <div class="card-header">
                    <i class="fas fa-users"></i>
                </div>
                <div class="card-body">
                    <h3>Members</h3>
                    <p>Browse member profiles and manage your account.</p>
                    <a href="members.php" class="card-link">View Members</a>
                </div>
            </div>
            
            <!-- Loans Card -->
            <div class="dashboard-card loans-card" onclick="window.location.href='loans.php'">
                <div class="card-header">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="card-body">
                    <h3>Loans</h3>
                    <p>Apply for loans and view your loan history.</p>
                    <a href="loans.php" class="card-link">Manage Loans</a>
                </div>
            </div>
            
            <!-- Investments Card -->
            <div class="dashboard-card investments-card" onclick="window.location.href='investments.php'">
                <div class="card-header">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="card-body">
                    <h3>Investments</h3>
                    <p>Explore investment opportunities and track returns.</p>
                    <a href="investments.php" class="card-link">View Investments</a>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <footer>
            <p>© <?php echo date("Y"); ?> Maisha Sacco. All rights reserved.</p>
            <p>Need help? Contact support@maishasacco.co.ke</p>
        </footer>
    </div>

    <script>
        // JavaScript for handling card clicks and logout
        document.addEventListener('DOMContentLoaded', function() {
            // Add confirmation for logout
            document.querySelector('.logout-btn').addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to logout?')) {
                    e.preventDefault();
                }
            });
            
            // Add loading state for card clicks
            const cards = document.querySelectorAll('.dashboard-card');
            cards.forEach(card => {
                card.addEventListener('click', function() {
                    this.style.opacity = '0.7';
                    this.style.cursor = 'wait';
                });
            });
        });
    </script>
</body>
</html>
<?php
// Close the database connection
$conn->close();
?>