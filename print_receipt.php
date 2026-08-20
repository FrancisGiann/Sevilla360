<?php
session_start();
require_once 'config/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}

$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
if ($booking_id <= 0) die("Invalid booking ID.");

// Fetch booking data
$stmt = $conn->prepare("
    SELECT b.*, c.first_name, c.last_name, c.email, c.phone, c.user_id as owner_id,
           v.name AS venue_name, v.category AS venue_category
    FROM bookings b
    JOIN customers c ON b.customer_id = c.id
    JOIN venues v ON b.venue_id = v.id
    WHERE b.id = ?
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) die("Booking not found.");
$booking = $res->fetch_assoc();

// Auth Guard: Only Admin/Staff OR the Booking Owner can view this
$role = $_SESSION['role'] ?? '';
$is_admin = ($role === 'admin' || $role === 'staff');
$is_owner = ($booking['owner_id'] == $_SESSION['user_id']);

if (!$is_admin && !$is_owner) {
    die("Unauthorized access to this receipt.");
}

if (in_array($booking['booking_status'], ['Pending', 'Cancelled']) || $booking['payment_scheme'] === 'To Be Arranged') {
    die("Receipt is not available for pending or cancelled bookings.");
}

// Fetch Business Info
$biz = [
    'biz_name' => 'Sevilla360',
    'biz_tagline' => 'LUXURY RESORT & EVENTS',
    'biz_policies' => '',
    'biz_email' => '',
    'biz_phone' => '',
    'biz_address' => ''
];
$biz_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'biz_%'");
if ($biz_res) {
    while ($row = $biz_res->fetch_assoc()) {
        $biz[$row['setting_key']] = $row['setting_value'];
    }
}

// Fetch Line Items & Addons
$stmt_li = $conn->prepare("SELECT item_name, amount FROM booking_line_items WHERE booking_id = ?");
$stmt_li->bind_param("i", $booking_id);
$stmt_li->execute();
$line_items = $stmt_li->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch Room Allocations
$stmt_ra = $conn->prepare("
    SELECT v.name as building_name, h.room_type, h.room_number, br.line_total 
    FROM booking_rooms br 
    JOIN venues v ON br.venue_id = v.id 
    JOIN hotel_rooms h ON v.id = h.venue_id 
    WHERE br.booking_id = ?
");
$stmt_ra->bind_param("i", $booking_id);
$stmt_ra->execute();
$room_allocations = $st_ra->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch All Payments
$stmt_pay = $conn->prepare("SELECT transaction_id, payment_method, amount, payment_date FROM payments WHERE booking_id = ? AND status = 'Success' ORDER BY payment_date ASC");
$stmt_pay->bind_param("i", $booking_id);
$stmt_pay->execute();
$payments = $stmt_pay->get_result()->fetch_all(MYSQLI_ASSOC);

// Formatting
$ref_no = $booking['reference_no'];
$customer_name = htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']);
$venue_name = htmlspecialchars($booking['venue_name'] . ' (' . $booking['venue_category'] . ')');
$check_in = date('F j, Y', strtotime($booking['start_date']));
$check_out = date('F j, Y', strtotime($booking['end_date']));
$date_str = ($check_in === $check_out) ? $check_in : "$check_in - $check_out";

$base_amt = floatval($booking['base_amount']);
$addons_amt = floatval($booking['addons_amount']);
$extra_pax = floatval($booking['extra_pax_amount']);
$total_amt = floatval($booking['total_amount']);
$paid_amt = floatval($booking['amount_paid']);
$balance = $total_amt - $paid_amt;

$status = $booking['booking_status'];
if ($status === 'Confirmed' && $paid_amt >= $total_amt && $total_amt > 0) $status_text = 'Fully Paid';
elseif ($status === 'Confirmed' && $paid_amt > 0) $status_text = 'Partially Paid';
elseif ($status === 'Confirmed') $status_text = 'Unpaid';
else $status_text = $status;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo $ref_no; ?></title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #d6a870; /* Premium Gold */
            --dark: #2a2522;
            --gray-light: #f4f4f4;
            --gray-border: #eaeaea;
            --text-main: #333;
            --text-muted: #666;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            margin: 0;
            padding: 40px;
            background: #f9f9f9;
        }

        .receipt-wrapper {
            max-width: 850px;
            margin: 0 auto;
            background: #fff;
            padding: 50px 60px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.05);
            border-top: 6px solid var(--primary);
            position: relative;
        }

        /* Controls */
        .controls {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
        }
        .btn-print {
            background: var(--dark);
            color: #fff;
            border: none;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
        }
        .btn-print:hover { background: var(--primary); }

        /* Header */
        .receipt-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 30px;
            border-bottom: 2px solid var(--gray-border);
            margin-bottom: 40px;
        }
        .brand-section h1 {
            font-family: 'Playfair Display', serif;
            color: var(--primary);
            font-size: 32px;
            margin: 0 0 5px 0;
            letter-spacing: 1px;
        }
        .brand-section p {
            margin: 0;
            font-size: 11px;
            letter-spacing: 2px;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .invoice-title {
            text-align: right;
        }
        .invoice-title h2 {
            margin: 0;
            font-size: 24px;
            color: var(--dark);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .invoice-title p {
            margin: 5px 0 0 0;
            color: var(--text-muted);
            font-size: 14px;
        }

        /* Grid Information */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-bottom: 40px;
        }
        .info-section h3 {
            font-size: 12px;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 1px;
            margin: 0 0 10px 0;
            border-bottom: 1px solid var(--gray-border);
            padding-bottom: 5px;
        }
        .info-section p {
            margin: 5px 0;
            font-size: 14px;
            line-height: 1.5;
        }
        .info-section strong {
            color: var(--dark);
            font-weight: 600;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 5px;
        }
        .status-paid { background: #e6f4ea; color: #1e8e3e; }
        .status-partial { background: #fef7e0; color: #b06000; }
        .status-pending { background: #f1f3f4; color: #5f6368; }
        .status-cancelled { background: #fce8e6; color: #d93025; }

        /* Itemized Table */
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .table th {
            background: var(--gray-light);
            padding: 12px 15px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            border-bottom: 2px solid var(--gray-border);
        }
        .table td {
            padding: 15px;
            border-bottom: 1px solid var(--gray-border);
            font-size: 14px;
            vertical-align: top;
        }
        .table th.right, .table td.right {
            text-align: right;
        }
        
        .item-title {
            font-weight: 600;
            color: var(--dark);
            display: block;
            margin-bottom: 4px;
        }
        .item-desc {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Totals Area */
        .totals-container {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 40px;
        }
        .totals-box {
            width: 350px;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            font-size: 14px;
            border-bottom: 1px solid var(--gray-border);
        }
        .totals-row.grand-total {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
            border-bottom: 2px solid var(--dark);
            padding-top: 15px;
        }
        .totals-row.balance {
            font-size: 16px;
            font-weight: 700;
            color: #d93025;
            border-bottom: none;
        }
        .totals-row.paid {
            color: #1e8e3e;
            font-weight: 500;
        }

        /* Footer */
        .receipt-footer {
            border-top: 1px solid var(--gray-border);
            padding-top: 30px;
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.6;
        }
        .footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 40px;
        }
        .contact-info {
            text-align: right;
        }
        
        /* Print Styles */
        @media print {
            body { 
                background: #fff; 
                padding: 0;
            }
            .receipt-wrapper { 
                box-shadow: none; 
                padding: 0;
                border-top: none;
            }
            .no-print { 
                display: none !important; 
            }
            /* Ensure colors print correctly */
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>
<body>

    <div class="controls no-print">
        <button class="btn-print" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Print / Save as PDF
        </button>
    </div>

    <div class="receipt-wrapper">
        
        <div class="receipt-header">
            <div class="brand-section">
                <h1><?php echo htmlspecialchars($biz['biz_name']); ?></h1>
                <p><?php echo htmlspecialchars($biz['biz_tagline']); ?></p>
            </div>
            <div class="invoice-title">
                <h2>RECEIPT</h2>
                <p>#<?php echo $ref_no; ?></p>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-section">
                <h3>Billed To</h3>
                <p><strong><?php echo $customer_name; ?></strong></p>
                <p><?php echo htmlspecialchars($booking['email']); ?></p>
                <p><?php echo htmlspecialchars($booking['phone'] ?? ''); ?></p>
            </div>
            
            <div class="info-section">
                <h3>Payment Details</h3>
                <p>Date Issued: <strong><?php echo date('F j, Y'); ?></strong></p>
                <p>
                    Status: 
                    <?php
                        $badge_class = 'status-pending';
                        if ($status === 'Confirmed' && $paid_amt >= $total_amt && $total_amt > 0) $badge_class = 'status-paid';
                        elseif ($status === 'Confirmed' && $paid_amt > 0) $badge_class = 'status-partial';
                        elseif ($status === 'Confirmed') $badge_class = 'status-pending';
                        elseif ($status === 'Cancelled') $badge_class = 'status-cancelled';
                    ?>
                    <span class="status-badge <?php echo $badge_class; ?>"><?php echo $status_text; ?></span>
                </p>
            </div>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <span class="item-title"><?php echo $venue_name; ?></span>
                        <span class="item-desc">Dates: <?php echo $date_str; ?> | Guests: <?php echo $booking['guests_count']; ?></span>
                    </td>
                    <td class="right">₱<?php echo number_format($base_amt, 2); ?></td>
                </tr>
                
                <?php if ($extra_pax > 0): ?>
                <tr>
                    <td>
                        <span class="item-title">Extra Pax Charge</span>
                    </td>
                    <td class="right">₱<?php echo number_format($extra_pax, 2); ?></td>
                </tr>
                <?php endif; ?>
                
                <?php foreach ($line_items as $item): ?>
                <tr>
                    <td>
                        <span class="item-title"><?php echo htmlspecialchars($item['item_name']); ?></span>
                    </td>
                    <td class="right">₱<?php echo number_format($item['amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                
                <?php foreach ($room_allocations as $room): ?>
                <tr>
                    <td>
                        <span class="item-title">Hotel Room Add-on: <?php echo htmlspecialchars($room['building_name']); ?> — <?php echo htmlspecialchars($room['room_type']); ?></span>
                        <span class="item-desc">Room Number: <?php echo htmlspecialchars($room['room_number'] ?? 'TBA'); ?></span>
                    </td>
                    <td class="right">₱<?php echo number_format($room['line_total'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals-container">
            <div class="totals-box">
                <div class="totals-row">
                    <span>Subtotal</span>
                    <span>₱<?php echo number_format($base_amt + $extra_pax, 2); ?></span>
                </div>
                
                <?php if ($addons_amt > 0 || count($line_items) > 0): ?>
                <div class="totals-row">
                    <span>Add-ons & Extras</span>
                    <span>₱<?php 
                        $extras_total = $total_amt - ($base_amt + $extra_pax);
                        echo number_format($extras_total, 2); 
                    ?></span>
                </div>
                <?php endif; ?>
                
                <div class="totals-row grand-total">
                    <span>Total Amount</span>
                    <span>₱<?php echo number_format($total_amt, 2); ?></span>
                </div>
                
                <?php if (count($payments) > 0): ?>
                    <?php foreach ($payments as $index => $pay): ?>
                    <div class="totals-row paid" style="align-items: flex-start;">
                        <span style="font-size: 13px;">
                            Payment on <?php echo date('M j, Y', strtotime($pay['payment_date'])); ?> (<?php echo htmlspecialchars($pay['payment_method']); ?>)<br>
                            <small style="color: #888; font-weight: 400; font-size: 11px;">TXN: <?php echo htmlspecialchars($pay['transaction_id']); ?></small>
                        </span>
                        <span style="margin-top: 2px;">- ₱<?php echo number_format($pay['amount'], 2); ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="totals-row paid">
                        <span>Amount Paid</span>
                        <span>- ₱0.00</span>
                    </div>
                <?php endif; ?>
                
                <div class="totals-row balance">
                    <span>Balance Due</span>
                    <span>₱<?php echo number_format(max(0, $balance), 2); ?></span>
                </div>
            </div>
        </div>

        <div class="receipt-footer">
            <div class="footer-grid">
                <div>
                    <strong>Terms & Policies</strong><br>
                    <?php echo nl2br(htmlspecialchars($biz['biz_policies'])); ?>
                </div>
                <div class="contact-info">
                    <strong><?php echo htmlspecialchars($biz['biz_name']); ?></strong><br>
                    <?php echo htmlspecialchars($biz['biz_address']); ?><br>
                    <?php echo htmlspecialchars($biz['biz_phone']); ?><br>
                    <?php echo htmlspecialchars($biz['biz_email']); ?>
                </div>
            </div>
        </div>

    </div>

    <script>
        window.onload = function() {
            // Slight delay to ensure fonts load before printing
            setTimeout(() => {
                window.print();
            }, 500);
        }
    </script>
</body>
</html>
