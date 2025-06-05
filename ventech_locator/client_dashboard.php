<?php
// **1. Start Session**
session_start();

// **2. Include Database Connection**
require_once 'includes/db_connection.php';

// **3. Check User Authentication**
if (!isset($_SESSION['user_id'])) {
    header("Location: client_login.php"); // Adjust path if client_login.php is elsewhere
    exit;
}
$loggedInOwnerUserId = $_SESSION['user_id'];

// **4. Check if PDO connection is available**
if (!isset($pdo) || !$pdo instanceof  PDO) {
    error_log("PDO connection not available in client_dashboard.php");
    die("Sorry, we're experiencing technical difficulties with the database. Please try again later.");
}

// **5. Fetch Logged-in User (Owner) Details**
try {
    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->execute([$loggedInOwnerUserId]);
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$owner) {
        error_log("Invalid user_id in session: " . $loggedInOwnerUserId);
        session_unset();
        session_destroy();
        header("Location: client_login.php?error=invalid_session"); // Adjust path
        exit;
    }
    if ($owner['role'] !== 'client' && $owner['role'] !== 'admin') { // Assuming admin can also access
         error_log("User ID {$loggedInOwnerUserId} attempted to access client dashboard with role: {$owner['role']}");
         session_unset();
         session_destroy();
         header("Location: client_login.php?error=unauthorized_access"); // Adjust path
         exit;
    }

} catch (PDOException $e) {
    error_log("Error fetching user details for user ID {$loggedInOwnerUserId}: " . $e->getMessage());
    die("Error loading your information. Please try refreshing the page or contact support.");
}

// **6. Fetch Venues Owned by the Logged-in User**
$venues = [];
$venue_ids_owned = [];
try {
    $status_filter = $_GET['status'] ?? 'all';
    $allowed_statuses = ['all', 'open', 'closed'];

    $sql = "SELECT id, title, price, status, reviews, image_path, created_at FROM venue WHERE user_id = ?";
    $params = [$loggedInOwnerUserId];

    if (in_array($status_filter, $allowed_statuses) && $status_filter !== 'all') {
        $sql .= " AND status = ?";
        $params[] = $status_filter;
    }
    $sql .= " ORDER BY created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $venues = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $venue_ids_owned = array_column($venues, 'id');

} catch (PDOException $e) {
    error_log("Error fetching venues for user $loggedInOwnerUserId (status: $status_filter): " . $e->getMessage());
}


// **7. Fetch Dashboard Counts for Owned Venues**
$total_venue_bookings_count = 0;
$pending_reservations_count = 0;
$cancelled_reservations_count = 0;

if (!empty($venue_ids_owned)) {
    try {
        $in_placeholders = implode(',', array_fill(0, count($venue_ids_owned), '?'));

        $stmtTotalBookings = $pdo->prepare("SELECT COUNT(*) FROM venue_reservations WHERE venue_id IN ($in_placeholders)");
        $stmtTotalBookings->execute($venue_ids_owned);
        $total_venue_bookings_count = $stmtTotalBookings->fetchColumn();

        $stmtPendingBookings = $pdo->prepare("SELECT COUNT(*) FROM venue_reservations WHERE venue_id IN ($in_placeholders) AND status = 'pending'");
        $stmtPendingBookings->execute($venue_ids_owned); // Re-execute with same params
        $pending_reservations_count = $stmtPendingBookings->fetchColumn();

        $stmtCancelledBookings = $pdo->prepare("SELECT COUNT(*) FROM venue_reservations WHERE venue_id IN ($in_placeholders) AND (status = 'cancelled' OR status = 'cancellation_requested')");
        $stmtCancelledBookings->execute($venue_ids_owned); // Re-execute with same params
        $cancelled_reservations_count = $stmtCancelledBookings->fetchColumn();

    } catch (PDOException $e) {
        error_log("Error fetching dashboard counts for owned venues (Owner ID: $loggedInOwnerUserId): " . $e->getMessage());
    }
}


// **8. Fetch Recent Reservations for Owned Venues**
$recent_venue_reservations = [];
if (!empty($venue_ids_owned)) {
     try {
        $in_placeholders_reservations = implode(',', array_fill(0, count($venue_ids_owned), '?')); // Ensure unique placeholder name if needed, but it's fine here.
         $sql_reservations = "SELECT
                     r.id, r.event_date, r.status, r.created_at,
                     v.id as venue_id, v.title as venue_title,
                     u.id as booker_user_id, u.username as booker_username, u.email as booker_email
                   FROM venue_reservations r
                   JOIN venue v ON r.venue_id = v.id
                   LEFT JOIN users u ON r.user_id = u.id
                   WHERE r.venue_id IN ($in_placeholders_reservations)
                   ORDER BY r.created_at DESC
                   LIMIT 10";

         $stmt_reservations = $pdo->prepare($sql_reservations);
         $stmt_reservations->execute($venue_ids_owned);
         $recent_venue_reservations = $stmt_reservations->fetchAll(PDO::FETCH_ASSOC);

     } catch (PDOException $e) {
         error_log("Error fetching recent reservations for owned venues (Owner ID: $loggedInOwnerUserId): " . $e->getMessage());
     }
}


// **9. Handle Messages (Modified to use session for one-time display)**
$new_venue_message = "";
$new_venue_id_for_link = null;
if (isset($_GET['new_venue']) && $_GET['new_venue'] == 'true') {
    $_SESSION['new_venue_message'] = "Venue successfully added!";
    try {
        $stmtLastVenue = $pdo->prepare("SELECT id FROM venue WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmtLastVenue->execute([$loggedInOwnerUserId]);
        $lastVenue = $stmtLastVenue->fetch(PDO::FETCH_ASSOC);
        if ($lastVenue) {
             $_SESSION['new_venue_id_for_link'] = $lastVenue['id'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching last venue ID for user {$loggedInOwnerUserId}: " . $e->getMessage());
    }
    // Redirect to clean the URL, preventing message from reappearing on refresh
    header("Location: client_dashboard.php");
    exit;
}

// Retrieve and unset session messages
if (isset($_SESSION['new_venue_message'])) {
    $new_venue_message = $_SESSION['new_venue_message'];
    $new_venue_id_for_link = $_SESSION['new_venue_id_for_link'] ?? null;
    unset($_SESSION['new_venue_message']);
    unset($_SESSION['new_venue_id_for_link']);
}


$venue_updated_message = "";
if (isset($_GET['venue_updated']) && $_GET['venue_updated'] == 'true') {
    $_SESSION['venue_updated_message'] = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-md mb-4' role='alert'><p class='font-bold'><i class='fas fa-check-circle mr-2'></i>Success!</p><p>Venue details updated successfully!</p></div>";
    header("Location: client_dashboard.php");
    exit;
}
if (isset($_SESSION['venue_updated_message'])) {
    $venue_updated_message = $_SESSION['venue_updated_message'];
    unset($_SESSION['venue_updated_message']);
}

// Handle venue deletion messages
$venue_deleted_message = "";
if (isset($_GET['venue_deleted']) && $_GET['venue_deleted'] == 'true') {
    $_SESSION['venue_deleted_message'] = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-md mb-4' role='alert'><p class='font-bold'><i class='fas fa-check-circle mr-2'></i>Success!</p><p>Venue successfully deleted!</p></div>";
    header("Location: client_dashboard.php");
    exit;
}
if (isset($_SESSION['venue_deleted_message'])) {
    $venue_deleted_message = $_SESSION['venue_deleted_message'];
    unset($_SESSION['venue_deleted_message']);
}

$venue_delete_error_message = "";
if (isset($_GET['delete_error'])) {
    $delete_error_map = [
        'invalid_id' => "Error: Invalid venue ID.",
        'unauthorized' => "Error: You are not authorized to delete this venue.",
        'db_error' => "Error: A database error occurred during deletion."
    ];
    $_SESSION['venue_delete_error_message'] = $delete_error_map[$_GET['delete_error']] ?? "An unknown error occurred during deletion.";
    header("Location: client_dashboard.php");
    exit;
}
if (isset($_SESSION['venue_delete_error_message'])) {
    $venue_delete_error_message = $_SESSION['venue_delete_error_message'];
    unset($_SESSION['venue_delete_error_message']);
}


$reservation_created_message = "";
if (isset($_GET['reservation_created']) && $_GET['reservation_created'] == 'true') {
    $_SESSION['reservation_created_message'] = "Reservation successfully created!";
    header("Location: client_dashboard.php");
    exit;
}
if (isset($_SESSION['reservation_created_message'])) {
    $reservation_created_message = $_SESSION['reservation_created_message'];
    unset($_SESSION['reservation_created_message']);
}


$reservation_error_message = "";
if (isset($_GET['error'])) {
    // Basic error mapping, can be expanded
    $error_map = [
        'reservation_failed' => "Failed to create reservation. Please try again.",
        'invalid_reservation_data' => "Invalid reservation data. Please check your input.",
        'unauthorized_access' => "You do not have permission to access this page.",
        'invalid_session' => "Your session is invalid. Please log in again."
    ];
    $_SESSION['reservation_error_message'] = $error_map[$_GET['error']] ?? "An unspecified error occurred.";
    header("Location: client_dashboard.php");
    exit;
}
if (isset($_SESSION['reservation_error_message'])) {
    $reservation_error_message = $_SESSION['reservation_error_message'];
    unset($_SESSION['reservation_error_message']);
}


$reservation_action_message = "";
if (isset($_GET['action_success'])) {
    $action_success_map = [
        'accepted' => "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-md mb-4' role='alert'><p class='font-bold'><i class='fas fa-check-circle mr-2'></i>Success!</p><p>Reservation accepted.</p></div>",
        'rejected' => "<div class='bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded-md mb-4' role='alert'><p class='font-bold'><i class='fas fa-check-circle mr-2'></i>Success!</p><p>Reservation rejected.</p></div>",
        'confirmed' => "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-md mb-4' role='alert'><p class='font-bold'><i class='fas fa-check-circle mr-2'></i>Success!</p><p>Reservation confirmed.</p></div>",
        'cancelled' => "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md mb-4' role='alert'><p class='font-bold'><i class='fas fa-check-circle mr-2'></i>Success!</p><p>Reservation cancelled.</p></div>",
        'completed' => "<div class='bg-purple-100 border-l-4 border-purple-500 text-purple-700 p-4 rounded-md mb-4' role='alert'><p class='font-bold'><i class='fas fa-check-circle mr-2'></i>Success!</p><p>Reservation marked as completed.</p></div>"
    ];
    $_SESSION['reservation_action_message'] = $action_success_map[$_GET['action_success']] ?? '';
    header("Location: client_dashboard.php");
    exit;
} elseif (isset($_GET['action_error'])) {
     $action_error_map = [
        'invalid' => "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md mb-4' role='alert'><p class='font-bold'><i class='fas fa-exclamation-triangle mr-2'></i>Error!</p><p>Invalid action or reservation ID.</p></div>",
        'db_error' => "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md mb-4' role='alert'><p class='font-bold'><i class='fas fa-exclamation-triangle mr-2'></i>Error!</p><p>A database error occurred.</p></div>",
    ];
    $_SESSION['reservation_action_message'] = $action_error_map[$_GET['action_error']] ?? "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md mb-4' role='alert'><p class='font-bold'><i class='fas fa-exclamation-triangle mr-2'></i>Error!</p><p>An error occurred.</p></div>";
    header("Location: client_dashboard.php");
    exit;
}
if (isset($_SESSION['reservation_action_message'])) {
    $reservation_action_message = $_SESSION['reservation_action_message'];
    unset($_SESSION['reservation_action_message']);
}


// --- Helper function for status badges ---
function getStatusBadgeClass($status) {
    $status = strtolower($status ?? 'unknown');
    switch ($status) {
        case 'open': case 'confirmed': case 'accepted': case 'completed': return 'bg-green-100 text-green-800';
        case 'closed': case 'cancelled': case 'rejected': case 'cancellation_requested': return 'bg-red-100 text-red-800';
        case 'pending': return 'bg-yellow-100 text-yellow-800';
        default: return 'bg-gray-100 text-gray-800';
    }
}
// Adjust path for client_logout.php if client_dashboard.php is in a subfolder like 'client'
$logoutPath = '/ventech_locator/client/client_logout.php'; // Default if in root
// Example if client_dashboard.php is in 'client/' folder:
// $logoutPath = 'client_logout.php'; // or just 'client_logout.php' if it's also in 'client/'
// $addVenuePath = 'add_venue.php';
// $clientMapPath = 'client_map.php';
// $clientProfilePath = '/ventech_locator/client/client_profile.php';
// $reservationManagePath = 'reservation_manage.php';
// $clientNotificationListPath = '../client_notification_list.php'; // Assuming it's one level up
// $indexPath = '../index.php'; // Assuming one level up

// For simplicity, assuming client_dashboard.php and other client-specific pages are in the same directory.
// And Ventech Locator (index.php) is one level up or at a known path.
$indexPath = '/ventech_locator/index.php'; // Use absolute path from web root if known
// $addVenuePath = '/ventech_locator/client/add_venue.php'; // This will now open the modal
$clientMapPath = 'client_map.php';
$clientProfilePath = '/ventech_locator/client/client_profile.php';
$reservationManagePath = '/ventech_locator/reservation_manage.php';
$clientNotificationListPath = 'client_notification_list.php'; // Assuming in same dir for simplicity
$clientNotificationEndpoint = 'client_notification.php'; // For JS fetch

// Path for venue_display.php and edit_venue.php
$venueDisplayPath = '/ventech_locator/venue_display.php'; // Assuming this path for public viewing
$editVenuePath = '/ventech_locator/client/edit_venue.php'; // Path for editing a venue
$deleteVenueEndpoint = '/ventech_locator/client/delete_venue.php'; // New path for deleting a venue

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard - Ventech Locator</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Roboto', sans-serif; }
        /* Sidebar transition */
        #sidebar { transition: transform 0.3s ease-in-out; }
        /* Overlay for mobile menu */
        #sidebar-overlay { transition: opacity 0.3s ease-in-out; }

        /* Custom scrollbar for sidebar (optional) */
        #sidebar::-webkit-scrollbar { width: 6px; }
        #sidebar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 3px; }
        #sidebar::-webkit-scrollbar-track { background-color: #f1f5f9; }

        .notification-icon-container { position: relative; display: inline-block; margin-right: 1rem; }
        .notification-badge {
            position: absolute; top: -5px; right: -8px; /* Adjusted for better visibility */
            background-color: #ef4444; color: white;
            border-radius: 9999px; padding: 0.1rem 0.4rem;
            font-size: 0.7rem; font-weight: bold; min-width: 1.1rem; /* Adjusted size */
            text-align: center; line-height: 1;
            /* Initially hidden, will be shown by JS if count > 0 */
            display: none;
        }
        /* Ensure table headers are sticky for horizontal scroll if needed */
        .table-sticky-header th { position: sticky; top: 0; background-color: #f9fafb; /* Match thead bg */ z-index: 1; }

        /* Custom aspect ratio for square venue boxes */
        .aspect-square-img-container {
            position: relative;
            width: 100%;
            padding-top: 100%; /* 1:1 Aspect Ratio (height equals width) */
            overflow: hidden;
        }

        .aspect-square-img-container img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover; /* Ensures the image covers the area without distortion */
        }

        /* --- Custom styles from add_venue.php merged here --- */
        input:focus, textarea:focus, select:focus {
            border-color: #f59e0b; /* Amber 500 */
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.4); /* Amber focus ring, slightly adjusted alpha */
            outline: none;
        }
        /* Style file input label as button */
        .file-input-button {
            cursor: pointer;
            background-color: #4f46e5; /* Indigo 600 */
            color: white;
            padding: 0.6rem 1rem;
            border-radius: 0.375rem; /* rounded-md */
            transition: background-color 0.2s;
            display: inline-flex; /* Use inline-flex */
            align-items: center;
            font-weight: 500; /* medium */
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); /* shadow-sm */
        }
        .file-input-button:hover {
            background-color: #4338ca; /* Indigo 700 */
        }
        .file-input-button i {
            margin-right: 0.5rem; /* mr-2 */
        }
        /* Visually hide the actual file input */
        .sr-only {
            position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border-width: 0;
        }

        /* --- Loading Overlay Styles (from add_venue.php) --- */
        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.8); /* White with transparency */
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999; /* Ensure it's on top of everything */
            transition: opacity 0.3s ease-in-out;
            opacity: 0; /* Start hidden */
            visibility: hidden; /* Start hidden */
        }

        #loading-overlay.visible {
            opacity: 1;
            visibility: visible;
        }

        /* Loading Animation Styles */
        .loader-container {
            text-align: center;
        }

        .loader-pin {
            color: #ff6347; /* Orange color for the pin */
            font-size: 3rem; /* Adjust size as needed */
            margin-bottom: 10px;
        }

        .loader-bar {
            width: 200px; /* Width of the loading bar */
            height: 4px;
            background-color: #e0e0e0; /* Light gray track */
            border-radius: 2px;
            position: relative;
            margin: 0 auto; /* Center the bar */
        }

        .loader-indicator {
            width: 10px; /* Size of the moving dot */
            height: 10px;
            background-color: #ff6347; /* Orange dot */
            border-radius: 50%;
            position: absolute;
            top: -3px; /* Center vertically on the bar */
            left: 0;
            animation: moveIndicator 2s infinite ease-in-out; /* Animation */
        }

        /* Keyframes for the animation */
        @keyframes moveIndicator {
            0% { left: 0; }
            50% { left: calc(100% - 10px); } /* Move to the end of the bar */
            100% { left: 0; }
        }
        /* --- End Loading Overlay Styles --- */

        /* Enhanced Transitions for interactive elements */
        .hover\:shadow-md, .hover\:shadow-lg {
            transition: box-shadow 0.3s ease-in-out;
        }
        .hover\:text-orange-200, .hover\:bg-gray-200, .hover\:text-orange-600, .hover\:bg-orange-50, .hover\:text-red-600, .hover\:bg-red-50, .hover\:opacity-90, .hover\:text-blue-800, .hover\:bg-gray-600, .hover\:bg-blue-600, .hover\:bg-green-600, .hover\:bg-red-600 {
            transition: all 0.2s ease-in-out;
        }

        /* Modal Styles */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5); /* Semi-transparent black overlay */
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 50; /* Above sidebar, below loading overlay */
            opacity: 0; /* Initially hidden */
            visibility: hidden; /* Initially hidden */
            transition: opacity 0.3s ease-out, visibility 0.3s ease-out;
        }
        .modal.show {
            opacity: 1;
            visibility: visible;
        }
        .modal-content {
            background-color: #fff;
            padding: 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-width: 90%;
            max-height: 90%;
            overflow-y: auto; /* Enable scrolling for long forms */
            position: relative;
            transform: scale(0.9); /* Start slightly smaller */
            transition: transform 0.3s ease-out, opacity 0.3s ease-out;
        }
        .modal.show .modal-content {
            transform: scale(1); /* Scale to normal size when shown */
            opacity: 1;
        }
        @media (min-width: 768px) { /* md breakpoint */
            .modal-content {
                max-width: 600px; /* Adjust max-width for larger screens */
            }
        }

        /* Confirmation Modal Specific Styles */
        #confirmationModal .modal-content {
            max-width: 400px;
            text-align: center;
        }
        #confirmationModal .modal-content h3 {
            margin-bottom: 1rem;
            font-size: 1.25rem;
            color: #333;
        }
        #confirmationModal .modal-content p {
            margin-bottom: 1.5rem;
            color: #555;
        }
        #confirmationModal .modal-content .button-group {
            display: flex;
            justify-content: center;
            gap: 1rem;
        }
    </style>
</head>
<body class="bg-gray-100">

    <div id="loading-overlay">
        <div class="loader-container">
            <i class="fas fa-map-marker-alt loader-pin"></i>
            <div class="loader-bar">
                <div class="loader-indicator"></div>
            </div>
        </div>
    </div>

    <nav class="bg-orange-600 p-4 text-white shadow-md sticky top-0 z-30">
        <div class="container mx-auto flex justify-between items-center">
            <div class="flex items-center">
                <button id="sidebar-toggle" class="text-white focus:outline-none mr-3 md:hidden">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <a href="<?php echo htmlspecialchars($indexPath); ?>" class="text-xl font-bold hover:text-orange-200">Ventech Locator</a>
            </div>
            <div class="flex items-center">
                <div class="notification-icon-container">
                    <a href="<?php echo htmlspecialchars($reservationManagePath); ?>?status_filter=pending" class="text-white hover:text-orange-200" title="View Pending Reservations">
                        <i class="fas fa-bell text-xl"></i>
                    </a>
                    <span id="client-notification-count-badge" class="notification-badge">
                        <?php echo htmlspecialchars($pending_reservations_count); ?>
                    </span>
                </div>
                <span class="mr-4 hidden sm:inline">Welcome, <?= htmlspecialchars($owner['username'] ?? 'Owner') ?>!</span>
                 <a href="<?php echo htmlspecialchars($logoutPath); ?>" class="bg-white text-orange-600 hover:bg-gray-200 py-1 px-3 rounded text-sm font-medium transition duration-150 ease-in-out shadow">Logout</a>
            </div>
        </div>
    </nav>

    <div class="flex relative min-h-screen">
        <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 md:hidden hidden"></div>

        <aside id="sidebar" class="w-64 bg-white p-5 shadow-lg flex flex-col flex-shrink-0 fixed md:sticky inset-y-0 left-0 transform -translate-x-full md:translate-x-0 z-40 md:z-10 h-full md:h-auto md:top-[64px] md:max-h-[calc(100vh-64px)] overflow-y-auto">
            <h2 class="text-lg font-semibold mb-5 border-b pb-3 text-gray-700">Navigation</h2>
            <ul class="space-y-2 flex-grow">
                 <li><a href="client_dashboard.php" class="flex items-center text-gray-700 font-semibold bg-orange-50 rounded p-2"><i class="fas fa-tachometer-alt fa-fw mr-3 w-5 text-center text-orange-600"></i>Dashboard</a></li>
                <li><a href="javascript:void(0);" onclick="openAddVenueModal();" class="flex items-center text-gray-700 hover:text-orange-600 hover:bg-orange-50 rounded p-2"><i class="fas fa-plus-square fa-fw mr-3 w-5 text-center"></i>Add Venue</a></li>
                 <li><a href="<?php echo htmlspecialchars($clientMapPath); ?>" class="flex items-center text-gray-700 hover:text-orange-600 hover:bg-orange-50 rounded p-2"><i class="fas fa-map-marked-alt fa-fw mr-3 w-5 text-center"></i>Map</a></li>
                 <li><a href="<?php echo htmlspecialchars($clientProfilePath); ?>" class="flex items-center text-gray-700 hover:text-orange-600 hover:bg-orange-50 rounded p-2"><i class="fas fa-user-circle fa-fw mr-3 w-5 text-center"></i>Profile</a></li>
                 <li><a href="<?php echo htmlspecialchars($reservationManagePath); ?>" class="flex items-center text-gray-700 hover:text-orange-600 hover:bg-orange-50 rounded p-2"><i class="fas fa-calendar-check fa-fw mr-3 w-5 text-center"></i>Manage Reservation</a></li>
                <li><a href="client_dashboard.php?status=all" class="flex items-center text-gray-700 hover:text-orange-600 hover:bg-orange-50 rounded p-2"><i class="fas fa-store fa-fw mr-3 w-5 text-center"></i>My Venues</a></li>
            </ul>
            <div class="mt-auto pt-4 border-t">
                 <a href="<?php echo htmlspecialchars($logoutPath); ?>" class="flex items-center text-gray-700 hover:text-red-600 hover:bg-red-50 rounded p-2"><i class="fas fa-sign-out-alt fa-fw mr-3 w-5 text-center"></i>Logout</a>
            </div>
        </aside>

        <main id="main-content" class="flex-1 p-4 sm:p-6 md:p-8 lg:p-10 overflow-y-auto bg-gray-50 md:ml-64 transition-all duration-300 ease-in-out">
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6">Owner Dashboard</h1>

            <?php if (!empty($new_venue_message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-3 sm:p-4 rounded-md mb-6 shadow relative" role="alert">
                    <p class="font-bold"><i class="fas fa-check-circle mr-2"></i>Success!</p>
                    <p class="text-sm sm:text-base"><?= htmlspecialchars($new_venue_message) ?>
                        <?php if ($new_venue_id_for_link): ?>
                            You can now view or edit its details.
                            <a href="<?php echo htmlspecialchars($venueDisplayPath); ?>?id=<?= htmlspecialchars($new_venue_id_for_link) ?>" class="font-medium text-blue-600 hover:text-blue-800 underline ml-1">View Venue</a> or
                            <a href="<?php echo htmlspecialchars($editVenuePath); ?>?id=<?= htmlspecialchars($new_venue_id_for_link) ?>" class="font-medium text-blue-600 hover:text-blue-800 underline ml-1">Edit Details</a>.
                        <?php else: ?>
                            Please find it in your list below to add/edit details.
                        <?php endif; ?>
                    </p>
                    <button type="button" class="absolute top-0 right-0 mt-1 mr-1 sm:mt-2 sm:mr-2 text-green-700 hover:text-green-900" onclick="this.parentElement.style.display='none';" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>
            <?php if (!empty($venue_updated_message)): ?>
                <?= $venue_updated_message ?>
            <?php endif; ?>
            <?php if (!empty($venue_deleted_message)): ?>
                <?= $venue_deleted_message ?>
            <?php endif; ?>
            <?php if (!empty($venue_delete_error_message)): ?>
                 <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 sm:p-4 rounded-md mb-6 shadow relative" role="alert">
                    <p class="font-bold"><i class="fas fa-exclamation-triangle mr-2"></i>Error!</p>
                    <p class="text-sm sm:text-base"><?= htmlspecialchars($venue_delete_error_message) ?></p>
                    <button type="button" class="absolute top-0 right-0 mt-1 mr-1 sm:mt-2 sm:mr-2 text-red-700 hover:text-red-900" onclick="this.parentElement.style.display='none';" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>
            <?php if (!empty($reservation_created_message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-3 sm:p-4 rounded-md mb-6 shadow relative" role="alert">
                    <p class="font-bold"><i class="fas fa-check-circle mr-2"></i>Success!</p>
                    <p class="text-sm sm:text-base"><?= htmlspecialchars($reservation_created_message) ?></p>
                    <button type="button" class="absolute top-0 right-0 mt-1 mr-1 sm:mt-2 sm:mr-2 text-green-700 hover:text-green-900" onclick="this.parentElement.style.display='none';" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>
            <?php if (!empty($reservation_error_message)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 sm:p-4 rounded-md mb-6 shadow relative" role="alert">
                    <p class="font-bold"><i class="fas fa-exclamation-triangle mr-2"></i>Error!</p>
                    <p class="text-sm sm:text-base"><?= htmlspecialchars($reservation_error_message) ?></p>
                    <button type="button" class="absolute top-0 right-0 mt-1 mr-1 sm:mt-2 sm:mr-2 text-red-700 hover:text-red-900" onclick="this.parentElement.style.display='none';" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>
            <?= $reservation_action_message ?>


            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-8">
                <div class="bg-white p-4 sm:p-5 rounded-lg shadow hover:shadow-md transition-shadow flex flex-col">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-600 mb-2 flex items-center"><i class="fas fa-store mr-2 text-blue-500"></i>Your Venues</h3>
                    <p class="text-2xl sm:text-3xl font-bold text-blue-600 mt-auto"><?= htmlspecialchars(count($venues)) ?></p>
                </div>
                <div class="bg-white p-4 sm:p-5 rounded-lg shadow hover:shadow-md transition-shadow flex flex-col">
                   <h3 class="text-base sm:text-lg font-semibold text-gray-600 mb-2 flex items-center"><i class="fas fa-calendar-alt mr-2 text-green-500"></i>Venue Bookings</h3>
                   <p class="text-2xl sm:text-3xl font-bold text-green-600 mt-auto"><?= htmlspecialchars($total_venue_bookings_count) ?></p>
                   <p class="text-xs text-gray-500 mt-1">Total booking requests.</p>
                </div>
                <div class="bg-white p-4 sm:p-5 rounded-lg shadow hover:shadow-md transition-shadow flex flex-col">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-600 mb-2 flex items-center"><i class="fas fa-hourglass-half mr-2 text-yellow-500"></i>Pending Requests</h3>
                    <p class="text-2xl sm:text-3xl font-bold text-yellow-600 mt-auto"><?= htmlspecialchars($pending_reservations_count) ?></p>
                     <p class="text-xs text-gray-500 mt-1">Requests needing confirmation.</p>
                </div>
                <div class="bg-white p-4 sm:p-5 rounded-lg shadow hover:shadow-md transition-shadow flex flex-col">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-600 mb-2 flex items-center"><i class="fas fa-ban mr-2 text-red-500"></i>Cancellations</h3>
                    <p class="text-2xl sm:text-3xl font-bold text-red-600 mt-auto"><?= htmlspecialchars($cancelled_reservations_count) ?></p>
                    <p class="text-xs text-gray-500 mt-1">Cancelled or requested.</p>
                    <a href="<?php echo htmlspecialchars($reservationManagePath); ?>?status_filter=cancelled" class="text-xs text-blue-600 hover:text-blue-800 mt-2 self-start">View Details &rarr;</a>
                </div>
            </section>

            <section class="mb-8">
                <div class="flex flex-col sm:flex-row justify-between items-center mb-4 flex-wrap gap-3 sm:gap-4">
                    <h2 class="text-xl sm:text-2xl font-semibold text-gray-800">Your Venues</h2>
                    <div>
                        <label for="status-filter" class="text-xs sm:text-sm text-gray-600 mr-2">Filter by status:</label>
                        <select id="status-filter" onchange="window.location.href='client_dashboard.php?status='+this.value" class="text-xs sm:text-sm border-gray-300 rounded-md shadow-sm focus:border-orange-500 focus:ring focus:ring-orange-200 focus:ring-opacity-50 py-1 px-2 sm:py-1.5 sm:px-3">
                            <option value="all" <?= ($status_filter ?? 'all') === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="open" <?= ($status_filter ?? '') === 'open' ? 'selected' : '' ?>>Open</option>
                            <option value="closed" <?= ($status_filter ?? '') === 'closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 sm:gap-6">
                    <?php if (count($venues) > 0): ?>
                        <?php foreach ($venues as $venue): ?>
                            <?php
                                $imagePathFromDB = $venue['image_path'] ?? null;
                                $uploadsBaseUrl = '/ventech_locator/uploads/'; // Ensure this is correct for your setup
                                $placeholderImg = 'https://placehold.co/400x400/fbbf24/ffffff?text=No+Image'; // Changed to square placeholder
                                $imgSrc = $placeholderImg;
                                if (!empty($imagePathFromDB)) {
                                    $imgSrc = rtrim($uploadsBaseUrl, '/') . '/' . ltrim(htmlspecialchars($imagePathFromDB), '/');
                                }
                            ?>
                            <div class="border rounded-lg shadow-md overflow-hidden bg-white flex flex-col transition duration-300 ease-in-out hover:shadow-lg relative">
                                <!-- Delete Button -->
                                <button type="button" onclick="confirmDelete(<?= htmlspecialchars($venue['id']) ?>, '<?= htmlspecialchars($venue['title']) ?>')" class="absolute top-2 right-2 bg-red-500 text-white rounded-full w-7 h-7 flex items-center justify-center text-sm font-bold hover:bg-red-600 transition-colors duration-200 z-10" title="Delete Venue">
                                    <i class="fas fa-times"></i>
                                </button>

                                <a href="<?php echo htmlspecialchars($venueDisplayPath); ?>?id=<?= htmlspecialchars($venue['id']) ?>" class="block hover:opacity-90 aspect-square-img-container">
                                    <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($venue['title'] ?? 'Venue Image') ?>" loading="lazy" onerror="this.onerror=null;this.src='<?= $placeholderImg ?>';" />
                                </a>
                                <div class="p-3 sm:p-4 flex flex-col flex-grow">
                                    <div class="flex justify-between items-start mb-1 sm:mb-2">
                                        <h3 class="text-sm sm:text-md font-semibold text-gray-800 leading-tight flex-grow mr-2">
                                            <a href="<?php echo htmlspecialchars($venueDisplayPath); ?>?id=<?= htmlspecialchars($venue['id']) ?>" class="hover:text-orange-600">
                                                <?= htmlspecialchars($venue['title'] ?? 'N/A') ?>
                                            </a>
                                        </h3>
                                        <span class="flex-shrink-0 inline-block px-1.5 sm:px-2 py-0.5 text-xs font-semibold rounded-full <?= getStatusBadgeClass($venue['status']) ?>">
                                            <?= ucfirst(htmlspecialchars($venue['status'] ?? 'unknown')); ?>
                                        </span>
                                    </div>
                                    <div class="text-sm sm:text-base text-gray-600 mb-2 sm:mb-3">
                                        <p class="text-md sm:text-lg font-bold text-gray-900">₱<?= number_format((float)($venue['price'] ?? 0), 2) ?> <span class="text-xs font-normal">/ Hour</span></p>
                                    </div>
                                    <div class="flex items-center text-xs text-gray-500 mb-3 sm:mb-4">
                                         <div class="flex text-yellow-400 mr-1 sm:mr-1.5">
                                             <?php for($i=0; $i<5; $i++): ?><i class="fas fa-star<?= ($i < ($venue['reviews_avg'] ?? 0) ? '' : ($i < ceil($venue['reviews_avg'] ?? 0) ? '-half-alt' : ' far fa-star')) ?>"></i><?php endfor; // Example stars, replace with actual review logic ?>
                                         </div>
                                         <span>(<?= htmlspecialchars($venue['reviews'] ?? 0) ?> Reviews)</span>
                                    </div>
                                    <div class="mt-auto pt-2 sm:pt-3 border-t border-gray-200 flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-2">
                                         <a href="<?php echo htmlspecialchars($venueDisplayPath); ?>?id=<?= htmlspecialchars($venue['id']) ?>" title="View Public Page" class="flex-1 inline-flex items-center justify-center bg-gray-500 hover:bg-gray-600 text-white text-xs font-medium py-1.5 px-2 sm:px-3 rounded shadow-sm transition duration-150 ease-in-out">
                                            <i class="fas fa-eye mr-1"></i> View
                                        </a>
                                         <a href="<?php echo htmlspecialchars($editVenuePath); ?>?id=<?= htmlspecialchars($venue['id']) ?>" title="Edit Details" class="flex-1 inline-flex items-center justify-center bg-blue-500 hover:bg-blue-600 text-white text-xs font-medium py-1.5 px-2 sm:px-3 rounded shadow-sm transition duration-150 ease-in-out">
                                            <i class="fas fa-edit mr-1"></i> Edit
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="col-span-full text-gray-600 bg-white p-6 rounded-lg shadow text-center">
                            You haven't added any venues yet<?php if ($status_filter !== 'all') echo " matching status '" . htmlspecialchars($status_filter) . "'"; ?>.
                             <a href="javascript:void(0);" onclick="openAddVenueModal();" class="text-orange-600 hover:underline font-medium ml-1">Add your first venue now!</a>
                        </p>
                    <?php endif; ?>
                </div>
            </section>

            <section>
                <div class="flex flex-col sm:flex-row justify-between items-center mb-4 flex-wrap gap-3 sm:gap-4">
                    <h2 class="text-xl sm:text-2xl font-semibold text-gray-800">Recent Booking Requests</h2>
                     <a href="<?php echo htmlspecialchars($reservationManagePath); ?>" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                        Manage All Bookings &rarr;
                    </a>
                </div>
                <div class="bg-white shadow-md rounded-lg overflow-x-auto">
                    <?php if (count($recent_venue_reservations) > 0): ?>
                        <table class="w-full table-auto text-xs sm:text-sm text-left">
                            <thead class="bg-gray-100 text-xs text-gray-600 uppercase table-sticky-header">
                                <tr>
                                    <th scope="col" class="px-4 py-3 sm:px-6">Booker</th>
                                    <th scope="col" class="px-4 py-3 sm:px-6">Venue</th>
                                    <th scope="col" class="px-4 py-3 sm:px-6 hidden md:table-cell">Event Date</th>
                                    <th scope="col" class="px-4 py-3 sm:px-6">Status</th>
                                    <th scope="col" class="px-4 py-3 sm:px-6 hidden lg:table-cell">Requested On</th>
                                    <th scope="col" class="px-4 py-3 sm:px-6">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_venue_reservations as $reservation): ?>
                                <tr class="bg-white border-b hover:bg-gray-50">
                                    <td class="px-4 py-3 sm:px-6 font-medium text-gray-900 whitespace-nowrap" title="<?= htmlspecialchars($reservation['booker_email'] ?? '') ?>">
                                         <?= htmlspecialchars($reservation['booker_username'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-4 py-3 sm:px-6 font-medium text-gray-700 whitespace-nowrap">
                                         <a href="<?php echo htmlspecialchars($venueDisplayPath); ?>?id=<?= htmlspecialchars($reservation['venue_id'] ?? '') ?>" class="hover:text-orange-600" title="View Venue">
                                            <?= htmlspecialchars($reservation['venue_title'] ?? 'N/A') ?>
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 sm:px-6 whitespace-nowrap hidden md:table-cell">
                                        <?= htmlspecialchars(date("D, M d, Y", strtotime($reservation['event_date'] ?? ''))) ?>
                                    </td>
                                    <td class="px-4 py-3 sm:px-6">
                                        <span class="px-1.5 sm:px-2 py-0.5 inline-block rounded-full text-xs font-semibold <?= getStatusBadgeClass($reservation['status']) ?>">
                                            <?= htmlspecialchars(ucfirst($reservation['status'] ?? 'N/A')) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 sm:px-6 text-gray-600 whitespace-nowrap hidden lg:table-cell">
                                        <?= htmlspecialchars(date("M d, Y H:i", strtotime($reservation['created_at'] ?? ''))) ?>
                                    </td>
                                    <td class="px-4 py-3 sm:px-6 whitespace-nowrap">
                                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-1 sm:gap-2">
                                         <?php if (strtolower($reservation['status'] ?? '') === 'pending'): ?>
                                             <form method="post" action="process_reservation_action.php" class="inline-block">
                                                 <input type="hidden" name="reservation_id" value="<?= htmlspecialchars($reservation['id']) ?>">
                                                 <input type="hidden" name="action" value="accept">
                                                 <button type="submit" class="bg-green-500 hover:bg-green-600 text-white text-xs font-medium py-1 px-1.5 sm:px-2 rounded focus:outline-none focus:shadow-outline w-full sm:w-auto">Accept</button>
                                             </form>
                                              <form method="post" action="process_reservation_action.php" class="inline-block">
                                                 <input type="hidden" name="reservation_id" value="<?= htmlspecialchars($reservation['id']) ?>">
                                                 <input type="hidden" name="action" value="reject">
                                                 <button type="submit" class="bg-red-500 hover:bg-red-600 text-white text-xs font-medium py-1 px-1.5 sm:px-2 rounded focus:outline-none focus:shadow-outline w-full sm:w-auto">Reject</button>
                                             </form>
                                         <?php else: ?>
                                             <span class="text-gray-500 text-xs italic">No pending actions</span>
                                         <?php endif; ?>
                                          <a href="<?php echo htmlspecialchars($reservationManagePath); ?>?id=<?= htmlspecialchars($reservation['id'] ?? '') ?>" class="text-blue-600 hover:text-blue-800 text-xs font-medium whitespace-nowrap">View Details</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="p-6 text-center text-gray-600">No booking requests received for your venues yet.</p>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <!-- Add Venue Modal -->
    <div id="addVenueModal" class="modal">
        <div class="modal-content">
            <button type="button" class="absolute top-3 right-3 text-gray-400 hover:text-gray-600" onclick="closeAddVenueModal();" aria-label="Close modal">
                <i class="fas fa-times text-xl"></i>
            </button>
            <h2 class="text-xl font-semibold text-gray-800 mb-6">Add New Venue</h2>
            <form id="addVenueForm" method="POST" action="/ventech_locator/client/add_venue.php" enctype="multipart/form-data">
                <div class="mb-5">
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Venue Title</label>
                    <input type="text" id="title" name="title" value="" placeholder="e.g., The Grand Ballroom" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none transition duration-150 ease-in-out text-sm">
                </div>

                <div class="mb-5">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea id="description" name="description" placeholder="Describe the venue, its features, capacity, and suitability for events..." required
                              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none transition duration-150 ease-in-out text-sm min-h-[100px]"></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-5">
                    <div>
                        <label for="price" class="block text-sm font-medium text-gray-700 mb-1">Price per Hour</label>
                        <div class="relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <span class="text-gray-500 sm:text-sm">₱</span>
                            </div>
                            <input type="number" id="price" name="price" value="" placeholder="e.g., 5000.00" min="0.01" step="0.01" required
                                   class="w-full pl-7 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none transition duration-150 ease-in-out text-sm">
                        </div>
                    </div>
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Initial Status</label>
                        <select id="status" name="status" required
                                class="w-full px-3 py-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none transition duration-150 ease-in-out text-sm">
                            <option value="open">Open (Available for Booking)</option>
                            <option value="closed">Closed (Not Available)</option>
                        </select>
                    </div>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Venue Image</label>
                    <div class="flex items-center">
                        <label class="file-input-button" for="image">
                             <i class="fas fa-upload"></i> Choose Image...
                        </label>
                        <input type="file" id="image" name="image" class="sr-only" accept="image/jpeg,image/png,image/gif" required>
                        <span id="fileName" class="ml-3 text-sm text-gray-600 truncate">No file chosen</span>
                     </div>
                    <div class="mt-3">
                        <img id="imagePreview" src="#" alt="Image Preview" class="hidden w-full max-w-sm h-auto object-contain rounded border bg-gray-50 p-1"/>
                    </div>
                     <p class="text-xs text-gray-500 mt-1">Required. Max 5MB. JPG, PNG, or GIF format.</p>
                </div>

                <div class="mt-8 pt-5 border-t border-gray-200">
                    <button type="submit"
                            class="w-full flex justify-center items-center bg-orange-500 hover:bg-orange-600 text-white font-bold py-2.5 px-4 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 transition duration-150 ease-in-out">
                        <i class="fas fa-plus-circle mr-2"></i> Add Venue
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirmation Modal for Deletion -->
    <div id="confirmationModal" class="modal">
        <div class="modal-content">
            <h3 class="text-lg font-bold text-gray-800">Confirm Deletion</h3>
            <p class="text-gray-700">Are you sure you want to delete "<span id="venueToDeleteName" class="font-semibold"></span>"? This action cannot be undone.</p>
            <div class="button-group">
                <button id="cancelDeleteBtn" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded-md transition duration-150 ease-in-out">
                    Cancel
                </button>
                <form id="deleteVenueForm" method="POST" action="<?= htmlspecialchars($deleteVenueEndpoint) ?>" class="inline-block">
                    <input type="hidden" name="venue_id" id="deleteVenueId">
                    <button type="submit" class="bg-red-500 hover:bg-red-600 text-white font-bold py-2 px-4 rounded-md shadow-sm transition duration-150 ease-in-out">
                        Yes, Delete
                    </button>
                </form>
            </div>
        </div>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebar-toggle');
            const sidebarOverlay = document.getElementById('sidebar-overlay');
            const mainContent = document.getElementById('main-content');

            // Modal elements
            const addVenueModal = document.getElementById('addVenueModal');
            const addVenueForm = document.getElementById('addVenueForm');
            const modalCloseButton = addVenueModal.querySelector('.fa-times'); // Get the close icon

            // Form elements within the modal
            const imageInput = addVenueForm.querySelector('#image');
            const imagePreview = addVenueForm.querySelector('#imagePreview');
            const fileNameSpan = addVenueForm.querySelector('#fileName');
            const titleInput = addVenueForm.querySelector('#title');
            const descriptionInput = addVenueForm.querySelector('#description');
            const priceInput = addVenueForm.querySelector('#price');
            const statusSelect = addVenueForm.querySelector('#status');

            // Loading overlay elements (from add_venue.php)
            const loadingOverlay = document.getElementById('loading-overlay');

            // Confirmation Modal elements
            const confirmationModal = document.getElementById('confirmationModal');
            const venueToDeleteNameSpan = document.getElementById('venueToDeleteName');
            const deleteVenueIdInput = document.getElementById('deleteVenueId');
            const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
            const deleteVenueForm = document.getElementById('deleteVenueForm');


            // --- Sidebar Toggle Logic ---
            function toggleSidebar() {
                sidebar.classList.toggle('-translate-x-full');
                sidebarOverlay.classList.toggle('hidden');
            }

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', toggleSidebar); // Close sidebar when overlay is clicked
            }

            // --- Notification Badge Update ---
            const pendingReservationsCount = <?php echo json_encode($pending_reservations_count); ?>;
            const badge = document.getElementById('client-notification-count-badge');
            if (badge) {
                if (pendingReservationsCount > 0) {
                    badge.textContent = pendingReservationsCount;
                    badge.style.display = 'inline-block';
                } else {
                    badge.style.display = 'none';
                }
            }

            // --- Add Venue Modal Functions ---
            window.openAddVenueModal = function() {
                addVenueModal.classList.add('show'); // Add 'show' class for animation
                resetAddVenueForm(); // Reset form when opening
            };

            window.closeAddVenueModal = function() {
                addVenueModal.classList.remove('show'); // Remove 'show' class to hide and animate out
            };

            if (modalCloseButton) {
                modalCloseButton.addEventListener('click', closeAddVenueModal);
            }

            // Close modal if clicking outside the content
            addVenueModal.addEventListener('click', function(event) {
                if (event.target === addVenueModal) {
                    closeAddVenueModal();
                }
            });

            // --- Form Reset Function ---
            function resetAddVenueForm() {
                addVenueForm.reset(); // Resets all form fields
                fileNameSpan.textContent = 'No file chosen';
                imagePreview.src = '#';
                imagePreview.classList.add('hidden');
                statusSelect.value = 'open'; // Ensure status defaults to 'open'
            }

            // --- Image Preview Logic (adapted from add_venue.php) ---
            if (imageInput && imagePreview && fileNameSpan) {
                imageInput.addEventListener('change', function(event) {
                    const file = event.target.files[0];

                    if (file) {
                        // Display filename
                        fileNameSpan.textContent = file.name;

                        // Check if it's an image before creating preview
                        if (file.type.startsWith('image/')) {
                            const reader = new FileReader();

                            reader.onload = function(e) {
                                imagePreview.src = e.target.result;
                                imagePreview.classList.remove('hidden'); // Show preview
                            }
                            reader.readAsDataURL(file);
                        } else {
                            // If not an image file, clear preview and potentially show an error
                            imagePreview.src = '#';
                            imagePreview.classList.add('hidden'); // Hide preview
                            // Optional: Maybe add a message indicating it's not a valid image type
                            // fileNameSpan.textContent = 'Invalid file type'; // Or keep the name
                        }
                    } else {
                        // No file selected
                        imagePreview.src = '#';
                        imagePreview.classList.add('hidden'); // Hide preview
                        fileNameSpan.textContent = 'No file chosen'; // Reset filename display
                    }
                });
            }

            // --- Loading Overlay JavaScript (from add_venue.php) ---
            // Show loading overlay when the form is submitted
            if (addVenueForm && loadingOverlay) {
                addVenueForm.addEventListener('submit', function() {
                    loadingOverlay.classList.add('visible');
                });
            }

            // --- Venue Deletion Confirmation Logic ---
            window.confirmDelete = function(venueId, venueTitle) {
                venueToDeleteNameSpan.textContent = venueTitle;
                deleteVenueIdInput.value = venueId;
                confirmationModal.classList.add('show');
            };

            cancelDeleteBtn.addEventListener('click', function() {
                confirmationModal.classList.remove('show');
            });

            // Close confirmation modal if clicking outside the content
            confirmationModal.addEventListener('click', function(event) {
                if (event.target === confirmationModal) {
                    confirmationModal.classList.remove('show');
                }
            });

            // Show loading overlay when delete form is submitted
            if (deleteVenueForm && loadingOverlay) {
                deleteVenueForm.addEventListener('submit', function() {
                    loadingOverlay.classList.add('visible');
                });
            }
        });

        // Hide loading overlay when the page has fully loaded (including after form submission/redirect)
        window.addEventListener('load', function() {
            const loadingOverlay = document.getElementById('loading-overlay');
            if (loadingOverlay) {
                loadingOverlay.classList.remove('visible');
                // Optional: Remove the element from the DOM after transition
                loadingOverlay.addEventListener('transitionend', function() {
                     // Check if the overlay is actually hidden before removing
                    if (!loadingOverlay.classList.contains('visible')) {
                         loadingOverlay.remove();
                    }
                });
            }
        });
    </script>

</body>
</html>