<?php
// Enable strict error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start session to preserve active date across tab navigation & page reloads
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = 'localhost';
$dbname = 'shop'; 
$username = 'root';
$password = '';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Helper function to check if 'unit' column exists in inventory table
function hasUnitColumn($db) {
    try {
        $res = $db->query("SHOW COLUMNS FROM inventory LIKE 'unit'");
        return $res->fetch() !== false;
    } catch (Exception $e) {
        return false;
    }
}

// Auto-migration: Ensure category column exists in inventory table
function setupCategoryColumn($db) {
    try {
        $res = $db->query("SHOW COLUMNS FROM inventory LIKE 'category'");
        if ($res->fetch() === false) {
            $db->exec("ALTER TABLE inventory ADD COLUMN category VARCHAR(100) DEFAULT 'General' AFTER item_name");
        }
    } catch (Exception $e) {
        // Silently continue
    }
}

// Helper function to ensure sales table has manual item and receipt tracking columns
function setupSalesTableUpdates($db) {
    try {
        $db->exec("ALTER TABLE sales MODIFY item_id INT NULL");
        
        $res = $db->query("SHOW COLUMNS FROM sales LIKE 'custom_item_name'");
        if ($res->fetch() === false) {
            $db->exec("ALTER TABLE sales ADD COLUMN custom_item_name VARCHAR(255) NULL AFTER item_id");
        }

        $resReceipt = $db->query("SHOW COLUMNS FROM sales LIKE 'receipt_no'");
        if ($resReceipt->fetch() === false) {
            $db->exec("ALTER TABLE sales ADD COLUMN receipt_no VARCHAR(50) NULL AFTER id");
        }
    } catch (Exception $e) {
        // Silently continue
    }
}

// Helper function to ensure wall_notes table exists
function setupWallNotesTable($db) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS wall_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NULL,
            content TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $e) {
        // Silently continue
    }
}

// Helper function to ensure expenses table exists
function setupExpensesTable($db) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(100) DEFAULT 'General',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            expense_date DATE NOT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $e) {
        // Silently continue
    }
}

// Helper function to ensure purchases table exists
function setupPurchasesTable($db) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS purchases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_no VARCHAR(50) NULL,
            supplier_name VARCHAR(255) NULL,
            item_name VARCHAR(255) NOT NULL,
            quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
            unit VARCHAR(50) DEFAULT 'Piece',
            unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            purchase_date DATE NOT NULL,
            payment_status VARCHAR(20) DEFAULT 'Paid',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $e) {
        // Silently continue
    }
}

// Helper function for schema upgrades to purchases table
function setupPurchasesTableUpdates($db) {
    try {
        $resStatus = $db->query("SHOW COLUMNS FROM purchases LIKE 'payment_status'");
        if ($resStatus->fetch() === false) {
            $db->exec("ALTER TABLE purchases ADD COLUMN payment_status VARCHAR(20) DEFAULT 'Paid' AFTER total_cost");
        }
        $resNo = $db->query("SHOW COLUMNS FROM purchases LIKE 'purchase_no'");
        if ($resNo->fetch() === false) {
            $db->exec("ALTER TABLE purchases ADD COLUMN purchase_no VARCHAR(50) NULL AFTER id");
        }
        $resDisc = $db->query("SHOW COLUMNS FROM purchases LIKE 'discount'");
        if ($resDisc->fetch() === false) {
            $db->exec("ALTER TABLE purchases ADD COLUMN discount DECIMAL(10,2) DEFAULT 0.00 AFTER total_cost");
        }
    } catch (Exception $e) {
        // Silently continue
    }
}

$supports_unit = hasUnitColumn($db);
setupCategoryColumn($db);
setupSalesTableUpdates($db);
setupWallNotesTable($db);
setupExpensesTable($db);
setupPurchasesTable($db);
setupPurchasesTableUpdates($db);

// --- STICKY ACTIVE DATE SESSION PERSISTENCE ---
if (isset($_GET['sale_date']) && !empty($_GET['sale_date'])) {
    $_SESSION['active_date'] = $_GET['sale_date'];
} elseif (!isset($_SESSION['active_date']) || empty($_SESSION['active_date'])) {
    $_SESSION['active_date'] = date('Y-m-d');
}
$active_date = $_SESSION['active_date'];

$checkout_success = false;
$message = "";

// --- HANDLE SALE RECORD DELETION ---
if (isset($_POST['delete_sale'])) {
    $sale_id = (int)$_POST['sale_id'];
    try {
        $db->beginTransaction();

        $stmt = $db->prepare("SELECT s.*, i.item_name FROM sales s LEFT JOIN inventory i ON s.item_id = i.id WHERE s.id = ? FOR UPDATE");
        $stmt->execute([$sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($sale) {
            if (!empty($sale['item_id'])) {
                $restUpd = $db->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id = ?");
                $restUpd->execute([$sale['quantity_sold'], $sale['item_id']]);
            }

            $delStmt = $db->prepare("DELETE FROM sales WHERE id = ?");
            $delStmt->execute([$sale_id]);

            $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
            $item_display_name = $sale['custom_item_name'] ?? $sale['item_name'] ?? 'Unlisted Item';
            $logStmt->execute(['SALE_DELETE', $sale['item_id'] ?? 0, $item_display_name, "Deleted sale #$sale_id ({$sale['quantity_sold']} units). Inventory stock restored."]);

            $db->commit();
            $message = "SALE_SYNC // Sale entry #$sale_id deleted and stock quantity restored.";
        } else {
            if ($db->inTransaction()) $db->rollBack();
            $message = "ERROR // Sale record not found.";
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $message = "ERROR // Failed to delete sale: " . $e->getMessage();
    }
}

// --- HANDLE SALE RECORD EDITING ---
if (isset($_POST['update_sale'])) {
    $sale_id = (int)$_POST['sale_id'];
    $new_name = trim($_POST['edit_sale_item_name'] ?? '');
    $raw_item_id = $_POST['edit_sale_item_id'] ?? '';
    $new_item_id = ($raw_item_id !== '' && $raw_item_id !== 'null' && $raw_item_id !== '0') ? (int)$raw_item_id : null;
    $new_qty = (float)$_POST['edit_sale_qty'];
    $new_total_sales = (float)$_POST['edit_sale_total'];

    if ($new_qty > 0 && $new_total_sales >= 0 && !empty($new_name)) {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT s.*, i.buying_price, i.quantity as stock_qty, i.item_name FROM sales s LEFT JOIN inventory i ON s.item_id = i.id WHERE s.id = ? FOR UPDATE");
            $stmt->execute([$sale_id]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($sale) {
                $old_qty = (float)$sale['quantity_sold'];
                $old_item_id = !empty($sale['item_id']) ? (int)$sale['item_id'] : null;
                $buying_price = 0;

                if ($old_item_id !== $new_item_id) {
                    if ($old_item_id) {
                        $restUpd = $db->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id = ?");
                        $restUpd->execute([$old_qty, $old_item_id]);
                    }

                    if ($new_item_id) {
                        $newItemStmt = $db->prepare("SELECT quantity, buying_price FROM inventory WHERE id = ? FOR UPDATE");
                        $newItemStmt->execute([$new_item_id]);
                        $newItem = $newItemStmt->fetch(PDO::FETCH_ASSOC);

                        if (!$newItem || $newItem['quantity'] < $new_qty) {
                            throw new Exception("Insufficient stock in selected item. Available: " . ($newItem['quantity'] ?? 0));
                        }

                        $updInv = $db->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id = ?");
                        $updInv->execute([$new_qty, $new_item_id]);
                        $buying_price = (float)$newItem['buying_price'];
                    }
                } else {
                    if ($new_item_id) {
                        $qty_diff = $new_qty - $old_qty;
                        $buying_price = (float)($sale['buying_price'] ?? 0);

                        if ($sale['stock_qty'] < $qty_diff) {
                            throw new Exception("Insufficient stock to increase sale volume. Stock available: " . $sale['stock_qty']);
                        }

                        $updInv = $db->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id = ?");
                        $updInv->execute([$qty_diff, $new_item_id]);
                    }
                }

                if ($new_item_id) {
                    $total_cost = $new_qty * $buying_price;
                    $new_profit = $new_total_sales - $total_cost;
                } else {
                    $unit_cost = $old_qty > 0 ? ($sale['total_sales'] - $sale['total_profit']) / $old_qty : 0;
                    $new_profit = $new_total_sales - ($new_qty * $unit_cost);
                }

                $updSale = $db->prepare("UPDATE sales SET item_id = ?, custom_item_name = ?, quantity_sold = ?, total_sales = ?, total_profit = ? WHERE id = ?");
                $updSale->execute([$new_item_id, $new_name, $new_qty, $new_total_sales, $new_profit, $sale_id]);

                $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
                $logStmt->execute(['SALE_UPDATE', $new_item_id ?? 0, $new_name, "Updated sale #$sale_id: Item -> '$new_name', Qty $old_qty -> $new_qty, Revenue ₱{$sale['total_sales']} -> ₱$new_total_sales"]);

                $db->commit();
                $message = "SALE_SYNC // Sale record #$sale_id successfully updated.";
            } else {
                if ($db->inTransaction()) $db->rollBack();
                $message = "ERROR // Sale record not found.";
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $message = "ERROR // Sale update failed: " . $e->getMessage();
        }
    } else {
        $message = "ERROR // Item name, quantity (>0), and total sales must be valid.";
    }
}

// --- HANDLE SYSTEM RESET ---
if (isset($_POST['reset_system_data'])) {
    try {
        $db->beginTransaction();
        
        $db->exec("TRUNCATE TABLE sales");
        $db->exec("TRUNCATE TABLE expenses");
        $db->exec("TRUNCATE TABLE purchases");
        $db->exec("TRUNCATE TABLE activity_logs");
        
        $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
        $logStmt->execute(['SYSTEM_RESET', 0, 'System', 'System data refreshed. Sales, expenses, purchases, and logs cleared. Inventory preserved.']);
        
        $db->commit();
        $message = "SYSTEM RESET SUCCESS // Sales data, expenses, purchases, and audit logs have been cleared. Inventory matrix remains untouched.";
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $message = "ERROR // Reset failed: " . $e->getMessage();
    }
}

// --- HANDLE PURCHASE TRACKER ACTIONS ---
if (isset($_POST['add_batch_purchase'])) {
    $supplier = trim($_POST['supplier_name'] ?? '');
    $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
    $payment_status = $_POST['payment_status'] ?? 'Paid';
    $notes = trim($_POST['notes'] ?? '');
    $overall_discount = (float)($_POST['discount'] ?? 0);
    $cart_json = $_POST['purchase_cart_json'] ?? '[]';
    $items = json_decode($cart_json, true);

    $purchase_no = 'PO-' . date('Ymd-His') . '-' . mt_rand(1000, 9999);

    if (!empty($items) && is_array($items)) {
        try {
            $db->beginTransaction();
            $inserted_count = 0;
            $items_total = 0;

            foreach ($items as $item) {
                $items_total += (float)($item['total'] ?? 0);
            }

            $stmt = $db->prepare("INSERT INTO purchases (purchase_no, supplier_name, item_name, quantity, unit, unit_cost, total_cost, discount, purchase_date, payment_status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $num_items = count($items);
            foreach ($items as $idx => $item) {
                $itemName = trim($item['name'] ?? '');
                $rawQty = (float)($item['qty'] ?? 1.000);
                $qty = $rawQty > 0 ? $rawQty : 1.000;
                $unit = $item['unit'] ?? 'Piece';
                $unitCost = (float)($item['cost'] ?? 0);
                $rawTotalCost = (float)($item['total'] ?? 0);

                if ($rawTotalCost <= 0 && $unitCost > 0) {
                    $rawTotalCost = $qty * $unitCost;
                } elseif ($unitCost <= 0 && $rawTotalCost > 0 && $qty > 0) {
                    $unitCost = $rawTotalCost / $qty;
                }

                $item_discount = 0;
                if ($overall_discount > 0 && $items_total > 0) {
                    if ($idx === $num_items - 1) {
                        $item_discount = $overall_discount - array_sum(array_column($items, 'applied_discount_tmp'));
                    } else {
                        $item_discount = round(($rawTotalCost / $items_total) * $overall_discount, 2);
                        $items[$idx]['applied_discount_tmp'] = $item_discount;
                    }
                }

                $netTotalCost = max(0, $rawTotalCost - $item_discount);

                if (!empty($itemName)) {
                    $stmt->execute([$purchase_no, $supplier, $itemName, $qty, $unit, $unitCost, $netTotalCost, $item_discount, $purchase_date, $payment_status, $notes]);
                    $inserted_count++;
                }
            }

            if ($inserted_count > 0) {
                $final_net_order = max(0, $items_total - $overall_discount);
                $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
                $supplier_display = !empty($supplier) ? " from $supplier" : "";
                $disc_str = $overall_discount > 0 ? " (Disc: -₱" . number_format($overall_discount, 2) . ")" : "";
                $logStmt->execute(['PURCHASE_ADD', 0, 'Supplier Order', "Order $purchase_no: $inserted_count item(s)$supplier_display Net Total ₱" . number_format($final_net_order, 2) . "$disc_str ($payment_status)"]);

                $db->commit();
                $message = "PURCHASE_SYNC // Purchase order ($purchase_no) recorded successfully. Status: $payment_status.";
            } else {
                if ($db->inTransaction()) $db->rollBack();
                $message = "ERROR // No valid item found for purchase entry.";
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $message = "ERROR // Failed to save purchase order: " . $e->getMessage();
        }
    } else {
        $message = "ERROR // Purchase details are incomplete. Please fill out the form.";
    }
}

// --- HANDLE EDIT PURCHASE ENTRY ---
if (isset($_POST['update_purchase'])) {
    $pur_id = (int)$_POST['edit_purchase_id'];
    $supplier = trim($_POST['edit_supplier_name'] ?? '');
    $item = trim($_POST['edit_item_name'] ?? '');
    $rawQty = (float)($_POST['edit_quantity'] ?? 1);
    $qty = $rawQty > 0 ? $rawQty : 1.000;
    $unit = $_POST['edit_unit'] ?? 'Piece';
    $unit_cost = (float)($_POST['edit_unit_cost'] ?? 0);
    $discount = (float)($_POST['edit_discount'] ?? 0);
    $total_cost = (float)($_POST['edit_total_cost'] ?? 0);
    $purchase_date = $_POST['edit_purchase_date'] ?? date('Y-m-d');
    $payment_status = $_POST['edit_payment_status'] ?? 'Paid';
    $notes = trim($_POST['edit_notes'] ?? '');

    if ($total_cost <= 0 && $unit_cost > 0) {
        $total_cost = max(0, ($qty * $unit_cost) - $discount);
    }

    if ($pur_id > 0 && !empty($item)) {
        try {
            $stmt = $db->prepare("UPDATE purchases SET supplier_name = ?, item_name = ?, quantity = ?, unit = ?, unit_cost = ?, total_cost = ?, discount = ?, purchase_date = ?, payment_status = ?, notes = ? WHERE id = ?");
            $stmt->execute([$supplier, $item, $qty, $unit, $unit_cost, $total_cost, $discount, $purchase_date, $payment_status, $notes, $pur_id]);

            $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
            $logStmt->execute(['PURCHASE_UPDATE', 0, $item, "Updated purchase #$pur_id: '$item' total ₱" . number_format($total_cost, 2) . " (Discount: ₱$discount, Status: $payment_status)"]);

            $message = "PURCHASE_SYNC // Purchase record #$pur_id updated successfully.";
        } catch (Exception $e) {
            $message = "ERROR // Failed to update purchase: " . $e->getMessage();
        }
    } else {
        $message = "ERROR // Valid item name is required.";
    }
}

// --- HANDLE PURCHASE PAYMENT STATUS TOGGLE ---
if (isset($_POST['toggle_purchase_status'])) {
    $pur_id = (int)$_POST['purchase_id'];
    $new_status = $_POST['new_status'] ?? 'Paid';
    try {
        $stmt = $db->prepare("UPDATE purchases SET payment_status = ? WHERE id = ?");
        $stmt->execute([$new_status, $pur_id]);

        $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
        $logStmt->execute(['PURCHASE_STATUS_UPDATE', 0, "Purchase #$pur_id", "Updated payment status to '$new_status'."]);

        $message = "PURCHASE_SYNC // Payment status for record #$pur_id updated to $new_status.";
    } catch (Exception $e) {
        $message = "ERROR // Failed to update payment status: " . $e->getMessage();
    }
}

if (isset($_POST['delete_purchase'])) {
    $pur_id = (int)$_POST['purchase_id'];
    try {
        $stmt = $db->prepare("DELETE FROM purchases WHERE id = ?");
        $stmt->execute([$pur_id]);
        $message = "PURCHASE_SYNC // Purchase entry deleted.";
    } catch (Exception $e) {
        $message = "ERROR // Failed to delete purchase: " . $e->getMessage();
    }
}

// --- HANDLE BUSINESS EXPENSE ACTIONS ---
if (isset($_POST['add_expense'])) {
    $title = trim($_POST['expense_title'] ?? '');
    $category = $_POST['expense_category'] ?? 'General';
    $amount = (float)($_POST['expense_amount'] ?? 0);
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
    $notes = trim($_POST['expense_notes'] ?? '');

    if (!empty($title) && $amount > 0) {
        try {
            $stmt = $db->prepare("INSERT INTO expenses (title, category, amount, expense_date, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$title, $category, $amount, $expense_date, $notes]);

            $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
            $logStmt->execute(['EXPENSE_ADD', 0, $title, "Logged expense: ₱" . number_format($amount, 2) . " ($category) on $expense_date"]);

            $message = "EXPENSE_SYNC // Expense record saved.";
        } catch (Exception $e) {
            $message = "ERROR // Failed to save expense: " . $e->getMessage();
        }
    } else {
        $message = "ERROR // Expense title and valid amount greater than 0 are required.";
    }
}

// --- HANDLE EDIT EXPENSE ENTRY ---
if (isset($_POST['update_expense'])) {
    $exp_id = (int)$_POST['edit_expense_id'];
    $title = trim($_POST['edit_expense_title'] ?? '');
    $category = $_POST['edit_expense_category'] ?? 'General';
    $amount = (float)($_POST['edit_expense_amount'] ?? 0);
    $expense_date = $_POST['edit_expense_date'] ?? date('Y-m-d');
    $notes = trim($_POST['edit_expense_notes'] ?? '');

    if ($exp_id > 0 && !empty($title) && $amount > 0) {
        try {
            $stmt = $db->prepare("UPDATE expenses SET title = ?, category = ?, amount = ?, expense_date = ?, notes = ? WHERE id = ?");
            $stmt->execute([$title, $category, $amount, $expense_date, $notes, $exp_id]);

            $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
            $logStmt->execute(['EXPENSE_UPDATE', 0, $title, "Updated expense #$exp_id: '$title' - ₱" . number_format($amount, 2) . " ($category)"]);

            $message = "EXPENSE_SYNC // Expense entry #$exp_id updated successfully.";
        } catch (Exception $e) {
            $message = "ERROR // Failed to update expense: " . $e->getMessage();
        }
    } else {
        $message = "ERROR // Valid expense title and amount (>0) are required.";
    }
}

if (isset($_POST['delete_expense'])) {
    $exp_id = (int)$_POST['expense_id'];
    try {
        $stmt = $db->prepare("DELETE FROM expenses WHERE id = ?");
        $stmt->execute([$exp_id]);
        $message = "EXPENSE_SYNC // Expense entry deleted.";
    } catch (Exception $e) {
        $message = "ERROR // Failed to delete expense: " . $e->getMessage();
    }
}

// --- HANDLE WALL NOTES ACTIONS ---
if (isset($_POST['add_wall_note'])) {
    $note_title = trim($_POST['note_title'] ?? 'Quick Note');
    $note_content = trim($_POST['note_content'] ?? '');
    
    if (!empty($note_content)) {
        try {
            $stmt = $db->prepare("INSERT INTO wall_notes (title, content) VALUES (?, ?)");
            $stmt->execute([$note_title, $note_content]);
            $message = "WALL_SYNC // New sticky note pinned to the wall.";
        } catch (Exception $e) {
            $message = "ERROR // Failed to pin note: " . $e->getMessage();
        }
    } else {
        $message = "ERROR // Note content cannot be empty.";
    }
}

if (isset($_POST['delete_wall_note'])) {
    $note_id = (int)$_POST['note_id'];
    try {
        $stmt = $db->prepare("DELETE FROM wall_notes WHERE id = ?");
        $stmt->execute([$note_id]);
        $message = "WALL_SYNC // Note removed from the wall.";
    } catch (Exception $e) {
        $message = "ERROR // Failed to delete note: " . $e->getMessage();
    }
}

// --- HANDLE CUSTOM DATE RANGE SPREADSHEET EXPORT (.CSV) ---
if (isset($_GET['export_range']) && $_GET['export_range'] === 'true') {
    $start_export = $_GET['start_date'] ?? date('Y-m-01');
    $end_export = $_GET['end_date'] ?? date('Y-m-d');
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=store_sales_' . $start_export . '_to_' . $end_export . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Row ID', 'Receipt No', 'Product Name', 'Quantity Sold', 'Unit', 'Total Revenue (PHP)', 'Net Profit (PHP)', 'Timestamp']);
    
    $stmt = $db->prepare("
        SELECT s.id, s.receipt_no, COALESCE(s.custom_item_name, i.item_name, 'Unlisted Item') AS item_name, s.quantity_sold, COALESCE(i.unit, 'Piece') AS unit, s.total_sales, s.total_profit, s.sale_date 
        FROM sales s 
        LEFT JOIN inventory i ON s.item_id = i.id 
        WHERE DATE(s.sale_date) BETWEEN ? AND ? 
        ORDER BY s.sale_date DESC
    ");
    $stmt->execute([$start_export, $end_export]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['receipt_no'] ?? 'N/A',
            $row['item_name'],
            $row['quantity_sold'],
            $row['unit'] ?? 'Piece',
            $row['total_sales'],
            $row['total_profit'],
            $row['sale_date']
        ]);
    }
    fclose($output);
    exit;
}

// --- HANDLE BULK INVENTORY ACTIONS ---
if (isset($_POST['bulk_inventory_action'])) {
    $selected_ids = $_POST['selected_items'] ?? [];
    $bulk_category = trim($_POST['bulk_category'] ?? '');
    $bulk_action_type = $_POST['bulk_action_type'] ?? 'categorize';

    if (!empty($selected_ids) && is_array($selected_ids)) {
        try {
            $db->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

            if ($bulk_action_type === 'categorize') {
                if (!empty($bulk_category)) {
                    $params = array_merge([$bulk_category], $selected_ids);
                    $stmt = $db->prepare("UPDATE inventory SET category = ? WHERE id IN ($placeholders)");
                    $stmt->execute($params);

                    $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
                    $logStmt->execute(['BULK_UPDATE', 0, 'Batch Products', "Assigned category '$bulk_category' to " . count($selected_ids) . " item(s)."]);

                    $db->commit();
                    $message = "BULK_SYNC // Successfully assigned category '$bulk_category' to " . count($selected_ids) . " selected item(s).";
                } else {
                    if ($db->inTransaction()) $db->rollBack();
                    $message = "ERROR // Please select or enter a target category for bulk update.";
                }
            } elseif ($bulk_action_type === 'delete') {
                $stmt = $db->prepare("DELETE FROM inventory WHERE id IN ($placeholders)");
                $stmt->execute($selected_ids);

                $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
                $logStmt->execute(['BULK_DELETE', 0, 'Batch Products', "Permanently purged " . count($selected_ids) . " product(s) from inventory."]);

                $db->commit();
                $message = "BULK_SYNC // Successfully deleted " . count($selected_ids) . " selected item(s).";
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $message = "TRANSACTION FAILED // Bulk action error: " . $e->getMessage();
        }
    } else {
        $message = "ERROR // No inventory items selected. Please check at least one box.";
    }
}

// Handle Inventory Deletion
if (isset($_POST['delete_item'])) {
    $del_id = (int)$_POST['item_id'];
    
    try {
        $db->beginTransaction();

        $findStmt = $db->prepare("SELECT item_name FROM inventory WHERE id = ? FOR UPDATE");
        $findStmt->execute([$del_id]);
        $itemData = $findStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($itemData) {
            $itemName = $itemData['item_name'];

            $delStmt = $db->prepare("DELETE FROM inventory WHERE id = ?");
            $delStmt->execute([$del_id]);

            $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
            $logStmt->execute(['DELETE', $del_id, $itemName, "Permanently removed product from inventory matrix."]);

            $db->commit();
            $message = "SHEET_SYNC // Product deleted & ledger updated.";
        } else {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $message = "ERROR // Product not found for deletion.";
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $message = "TRANSACTION FAILED // Delete error: " . $e->getMessage();
    }
}

// Handle Inventory Addition
if (isset($_POST['add_item'])) {
    $name = $_POST['item_name'];
    $category = $_POST['category'] ?? 'General';
    $qty = (float)$_POST['quantity']; 
    $unit = $_POST['unit'] ?? 'Piece';
    $buy = (float)$_POST['buying_price'];
    $sell = (float)$_POST['selling_price'];

    try {
        $db->beginTransaction();

        if ($supports_unit) {
            $stmt = $db->prepare("INSERT INTO inventory (item_name, category, quantity, unit, buying_price, selling_price) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $category, $qty, $unit, $buy, $sell]);
        } else {
            $stmt = $db->prepare("INSERT INTO inventory (item_name, category, quantity, buying_price, selling_price) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $category, $qty, $buy, $sell]);
        }
        
        $new_id = $db->lastInsertId();

        $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
        $logStmt->execute(['INSERT', $new_id, $name, "Added product [$category]: $qty $unit, Buy: ₱$buy, Sell: ₱$sell"]);

        $db->commit();
        $message = "SHEET_SYNC // New product registered to inventory.";
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $message = "TRANSACTION FAILED // Add item error: " . $e->getMessage();
    }
}

// Handle Inventory Item Update (Edit)
if (isset($_POST['update_item'])) {
    $edit_id = (int)$_POST['edit_item_id'];
    $name = $_POST['item_name'];
    $category = $_POST['category'] ?? 'General';
    $qty = (float)$_POST['quantity']; 
    $unit = $_POST['unit'] ?? 'Piece';
    $buy = (float)$_POST['buying_price'];
    $sell = (float)$_POST['selling_price'];

    try {
        $db->beginTransaction();

        if ($supports_unit) {
            $stmt = $db->prepare("UPDATE inventory SET item_name = ?, category = ?, quantity = ?, unit = ?, buying_price = ?, selling_price = ? WHERE id = ?");
            $stmt->execute([$name, $category, $qty, $unit, $buy, $sell, $edit_id]);
        } else {
            $stmt = $db->prepare("UPDATE inventory SET item_name = ?, category = ?, quantity = ?, buying_price = ?, selling_price = ? WHERE id = ?");
            $stmt->execute([$name, $category, $qty, $buy, $sell, $edit_id]);
        }

        $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
        $logStmt->execute(['UPDATE', $edit_id, $name, "Updated details [$category]: $qty $unit, Buy: ₱$buy, Sell: ₱$sell"]);

        $db->commit();
        $message = "SHEET_SYNC // Product details updated successfully.";
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $message = "TRANSACTION FAILED // Update error: " . $e->getMessage();
    }
}

// Handle Inventory Restock
if (isset($_POST['restock_item'])) {
    $restock_id = (int)$_POST['restock_item_id'];
    $add_qty = (float)$_POST['add_quantity'];

    if ($add_qty > 0) {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT item_name, quantity, unit FROM inventory WHERE id = ? FOR UPDATE");
            $stmt->execute([$restock_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($item) {
                $new_qty = $item['quantity'] + $add_qty;
                $unit_label = ($supports_unit && isset($item['unit'])) ? $item['unit'] : 'Piece';

                $updStmt = $db->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
                $updStmt->execute([$new_qty, $restock_id]);

                $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
                $logStmt->execute(['RESTOCK', $restock_id, $item['item_name'], "Added +$add_qty $unit_label. Previous stock: {$item['quantity']}, New stock: $new_qty"]);

                $db->commit();
                $message = "RESTOCK_SYNC // Successfully added +$add_qty $unit_label to '{$item['item_name']}'. New stock: $new_qty $unit_label.";
            } else {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $message = "ERROR // Product not found for restock.";
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $message = "TRANSACTION FAILED // Restock error: " . $e->getMessage();
        }
    } else {
        $message = "ERROR // Invalid restock quantity.";
    }
}

// Handle Batch Multi-Item Checkout
if (isset($_POST['process_batch_checkout'])) {
    $cart_json = $_POST['cart_json'] ?? '[]';
    $cart_items = json_decode($cart_json, true);
    $target_date = $_POST['target_date'] ?? $active_date;
    $receipt_no = 'RCP-' . date('Ymd-His') . '-' . mt_rand(1000, 9999);

    if (!empty($cart_items) && is_array($cart_items)) {
        try {
            $db->beginTransaction();
            $processed_count = 0;

            foreach ($cart_items as $cart_item) {
                $is_manual = !empty($cart_item['is_manual']) || (isset($cart_item['id']) && $cart_item['id'] === 'manual');
                $sold_qty = (float)$cart_item['qty'];
                $custom_selling_price = isset($cart_item['price']) ? (float)$cart_item['price'] : null;

                if ($is_manual) {
                    $custom_name = trim($cart_item['name'] ?? 'Unlisted Item');
                    $selling_price = $custom_selling_price ?? 0;
                    $buying_price = (float)($cart_item['cost'] ?? 0);
                    $unit_label = $cart_item['unit'] ?? 'Piece';

                    $total_sales = $sold_qty * $selling_price;
                    $total_profit = $total_sales - ($sold_qty * $buying_price);

                    $saleStmt = $db->prepare("INSERT INTO sales (receipt_no, item_id, custom_item_name, quantity_sold, total_sales, total_profit, sale_date) VALUES (?, NULL, ?, ?, ?, ?, ?)");
                    $saleStmt->execute([$receipt_no, $custom_name, $sold_qty, $total_sales, $total_profit, $target_date . ' ' . date('H:i:s')]);

                    $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
                    $logStmt->execute(['MANUAL_SALE', 0, $custom_name, "Receipt $receipt_no - Manual Entry: $sold_qty $unit_label @ ₱$selling_price"]);

                    $processed_count++;
                } else {
                    $item_id = (int)$cart_item['id'];

                    $itemStmt = $db->prepare("SELECT * FROM inventory WHERE id = ? FOR UPDATE");
                    $itemStmt->execute([$item_id]);
                    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

                    if ($item && $item['quantity'] >= $sold_qty) {
                        $unit_label = ($supports_unit && isset($item['unit'])) ? $item['unit'] : 'Piece';
                        $applied_selling_price = ($custom_selling_price !== null && $custom_selling_price > 0) ? $custom_selling_price : (float)$item['selling_price'];
                        
                        $total_sales = $sold_qty * $applied_selling_price;
                        $total_cost = $sold_qty * (float)$item['buying_price'];
                        $total_profit = $total_sales - $total_cost;

                        $saleStmt = $db->prepare("INSERT INTO sales (receipt_no, item_id, quantity_sold, total_sales, total_profit, sale_date) VALUES (?, ?, ?, ?, ?, ?)");
                        $saleStmt->execute([$receipt_no, $item_id, $sold_qty, $total_sales, $total_profit, $target_date . ' ' . date('H:i:s')]);

                        $new_qty = $item['quantity'] - $sold_qty;
                        $updStmt = $db->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
                        $updStmt->execute([$new_qty, $item_id]);

                        $logStmt = $db->prepare("INSERT INTO activity_logs (action_type, item_id, item_name, details) VALUES (?, ?, ?, ?)");
                        $logStmt->execute(['BATCH_SALE', $item_id, $item['item_name'], "Receipt $receipt_no - Checkout: $sold_qty $unit_label @ ₱$applied_selling_price. Remaining: $new_qty"]);

                        $processed_count++;
                    } else {
                        throw new Exception("Insufficient stock for item: " . ($item['item_name'] ?? "ID $item_id"));
                    }
                }
            }

            $db->commit();
            $message = "CHECKOUT COMPLETE // Receipt generated: $receipt_no";
            $_SESSION['active_date'] = $target_date;
            $active_date = $target_date;
            $checkout_success = true;
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $message = "TRANSACTION FAILED // " . $e->getMessage();
        }
    } else {
        $message = "ERROR // Shopping bag is empty.";
    }
}

// Fetch totals specifically for the selected active date
$totalsStmt = $db->prepare("SELECT SUM(total_sales) as rev, SUM(total_profit) as prof FROM sales WHERE DATE(sale_date) = ?");
$totalsStmt->execute([$active_date]);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

$total_revenue = $totals['rev'] ?? 0;
$total_profit = $totals['prof'] ?? 0;

// Fetch sales list for the selected date
if ($supports_unit) {
    $todaySalesStmt = $db->prepare("
        SELECT s.*, COALESCE(s.custom_item_name, i.item_name, 'Unlisted Item') AS item_name, COALESCE(i.unit, 'Piece') AS unit 
        FROM sales s 
        LEFT JOIN inventory i ON s.item_id = i.id 
        WHERE DATE(s.sale_date) = ? 
        ORDER BY s.id DESC
    ");
} else {
    $todaySalesStmt = $db->prepare("
        SELECT s.*, COALESCE(s.custom_item_name, i.item_name, 'Unlisted Item') AS item_name 
        FROM sales s 
        LEFT JOIN inventory i ON s.item_id = i.id 
        WHERE DATE(s.sale_date) = ? 
        ORDER BY s.id DESC
    ");
}
$todaySalesStmt->execute([$active_date]);
$today_sales_list = $todaySalesStmt->fetchAll(PDO::FETCH_ASSOC);

// --- RANGE ANALYTICS CALCULATIONS ---
$analytics_mode = $_GET['mode'] ?? 'day';
$selected_day = $_GET['day'] ?? $active_date;
$selected_month = $_GET['month'] ?? date('Y-m', strtotime($active_date));
$selected_week_start = $_GET['week_start'] ?? date('Y-m-d', strtotime('monday this week', strtotime($active_date)));

if ($analytics_mode === 'day') {
    $start_date = $selected_day;
    $end_date = $selected_day;
} elseif ($analytics_mode === 'month') {
    $start_date = $selected_month . '-01';
    $end_date = date('Y-m-t', strtotime($start_date));
} else {
    $start_date = $selected_week_start;
    $end_date = date('Y-m-d', strtotime($start_date . ' + 6 days'));
}

$rangeStmt = $db->prepare("SELECT SUM(total_sales) as rev, SUM(total_profit) as prof FROM sales WHERE DATE(sale_date) BETWEEN ? AND ?");
$rangeStmt->execute([$start_date, $end_date]);
$rangeTotals = $rangeStmt->fetch(PDO::FETCH_ASSOC);

$rangeExpStmt = $db->prepare("SELECT SUM(amount) as total_exp FROM expenses WHERE expense_date BETWEEN ? AND ?");
$rangeExpStmt->execute([$start_date, $end_date]);
$range_expenses = $rangeExpStmt->fetch(PDO::FETCH_ASSOC)['total_exp'] ?? 0;

if ($supports_unit) {
    $rangeTransactionsStmt = $db->prepare("
        SELECT s.*, COALESCE(s.custom_item_name, i.item_name, 'Unlisted Item') AS item_name, COALESCE(i.unit, 'Piece') AS unit 
        FROM sales s 
        LEFT JOIN inventory i ON s.item_id = i.id 
        WHERE DATE(s.sale_date) BETWEEN ? AND ? 
        ORDER BY s.sale_date DESC
    ");
} else {
    $rangeTransactionsStmt = $db->prepare("
        SELECT s.*, COALESCE(s.custom_item_name, i.item_name, 'Unlisted Item') AS item_name 
        FROM sales s 
        LEFT JOIN inventory i ON s.item_id = i.id 
        WHERE DATE(s.sale_date) BETWEEN ? AND ? 
        ORDER BY s.sale_date DESC
    ");
}
$rangeTransactionsStmt->execute([$start_date, $end_date]);
$range_transactions = $rangeTransactionsStmt->fetchAll(PDO::FETCH_ASSOC);

$range_revenue = $rangeTotals['rev'] ?? 0;
$range_profit = $rangeTotals['prof'] ?? 0;
$net_operating_profit = $range_profit - $range_expenses;

// --- INCOME STATEMENT CALCULATIONS ---
$inc_mode = $_GET['inc_mode'] ?? 'month';
$inc_day = $_GET['inc_day'] ?? $active_date;
$inc_month = $_GET['inc_month'] ?? date('Y-m', strtotime($active_date));
$inc_week_start = $_GET['inc_week_start'] ?? date('Y-m-d', strtotime('monday this week', strtotime($active_date)));

if ($inc_mode === 'day') {
    $inc_start_date = $inc_day;
    $inc_end_date = $inc_day;
} elseif ($inc_mode === 'month') {
    $inc_start_date = $inc_month . '-01';
    $inc_end_date = date('Y-m-t', strtotime($inc_start_date));
} else {
    $inc_start_date = $inc_week_start;
    $inc_end_date = date('Y-m-d', strtotime($inc_start_date . ' + 6 days'));
}

// 1. Sales Revenue & Gross Profit
$incSalesStmt = $db->prepare("SELECT SUM(total_sales) as rev, SUM(total_profit) as prof FROM sales WHERE DATE(sale_date) BETWEEN ? AND ?");
$incSalesStmt->execute([$inc_start_date, $inc_end_date]);
$incSalesTotals = $incSalesStmt->fetch(PDO::FETCH_ASSOC);
$inc_revenue = (float)($incSalesTotals['rev'] ?? 0);
$inc_sales_profit = (float)($incSalesTotals['prof'] ?? 0);

// 2. Supplier Purchases / COGS Incurred
$incPurStmt = $db->prepare("SELECT SUM(total_cost) as total_pur, SUM(discount) as total_disc FROM purchases WHERE purchase_date BETWEEN ? AND ?");
$incPurStmt->execute([$inc_start_date, $inc_end_date]);
$incPurTotals = $incPurStmt->fetch(PDO::FETCH_ASSOC);
$inc_purchases = (float)($incPurTotals['total_pur'] ?? 0);
$inc_pur_discounts = (float)($incPurTotals['total_disc'] ?? 0);

// 3. Operating Expenses Breakdown
$incExpCatStmt = $db->prepare("SELECT category, SUM(amount) as cat_total FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY category ORDER BY cat_total DESC");
$incExpCatStmt->execute([$inc_start_date, $inc_end_date]);
$inc_expense_categories = $incExpCatStmt->fetchAll(PDO::FETCH_ASSOC);

$inc_expenses = array_sum(array_column($inc_expense_categories, 'cat_total'));

// Net Financial Calculations
$inc_gross_margin = $inc_revenue - $inc_purchases;
$inc_net_profit = $inc_sales_profit - $inc_expenses;
$inc_cashflow = $inc_revenue - $inc_purchases - $inc_expenses;

// Fetch Transaction Lists for Income Statement Tab
$incSalesListStmt = $db->prepare("SELECT s.*, COALESCE(s.custom_item_name, i.item_name, 'Unlisted Item') AS item_name FROM sales s LEFT JOIN inventory i ON s.item_id = i.id WHERE DATE(s.sale_date) BETWEEN ? AND ? ORDER BY s.sale_date DESC LIMIT 50");
$incSalesListStmt->execute([$inc_start_date, $inc_end_date]);
$inc_sales_list = $incSalesListStmt->fetchAll(PDO::FETCH_ASSOC);

$incPurListStmt = $db->prepare("SELECT * FROM purchases WHERE purchase_date BETWEEN ? AND ? ORDER BY purchase_date DESC LIMIT 50");
$incPurListStmt->execute([$inc_start_date, $inc_end_date]);
$inc_purchases_list = $incPurListStmt->fetchAll(PDO::FETCH_ASSOC);

$incExpListStmt = $db->prepare("SELECT * FROM expenses WHERE expense_date BETWEEN ? AND ? ORDER BY expense_date DESC LIMIT 50");
$incExpListStmt->execute([$inc_start_date, $inc_end_date]);
$inc_expenses_list = $incExpListStmt->fetchAll(PDO::FETCH_ASSOC);

// --- PORTFOLIO & BUSINESS GROWTH CALCULATIONS ---
$portfolioValuation = $db->query("
    SELECT 
        COUNT(*) as total_items,
        COALESCE(SUM(quantity), 0) as total_units,
        COALESCE(SUM(quantity * buying_price), 0) as cost_asset_val,
        COALESCE(SUM(quantity * selling_price), 0) as retail_asset_val
    FROM inventory
")->fetch(PDO::FETCH_ASSOC);

$allTimeSales = $db->query("SELECT SUM(total_sales) as rev, SUM(total_profit) as prof FROM sales")->fetch(PDO::FETCH_ASSOC);
$allTimeExpenses = $db->query("SELECT SUM(amount) as total_exp FROM expenses")->fetchColumn() ?: 0;

$all_time_rev = $allTimeSales['rev'] ?? 0;
$all_time_gross_profit = $allTimeSales['prof'] ?? 0;
$all_time_net_profit = $all_time_gross_profit - $allTimeExpenses;

$all_time_margin = $all_time_rev > 0 ? ($all_time_net_profit / $all_time_rev) * 100 : 0;
$inventory_roi = $portfolioValuation['cost_asset_val'] > 0 ? (($portfolioValuation['retail_asset_val'] - $portfolioValuation['cost_asset_val']) / $portfolioValuation['cost_asset_val']) * 100 : 0;

// Monthly Growth Trend
$monthlyTrendList = $db->query("
    SELECT 
        DATE_FORMAT(s.sale_date, '%Y-%m') as month_key,
        SUM(s.total_sales) as month_rev,
        SUM(s.total_profit) as month_gross_profit,
        COALESCE((SELECT SUM(amount) FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = DATE_FORMAT(s.sale_date, '%Y-%m')), 0) as month_expenses
    FROM sales s
    GROUP BY DATE_FORMAT(s.sale_date, '%Y-%m')
    ORDER BY month_key DESC
    LIMIT 12
")->fetchAll(PDO::FETCH_ASSOC);

// Category Portfolio Allocation Breakdown
$categoryAllocation = $db->query("
    SELECT 
        COALESCE(category, 'General') as category_name,
        COUNT(*) as item_count,
        SUM(quantity) as stock_volume,
        SUM(quantity * buying_price) as cost_val,
        SUM(quantity * selling_price) as retail_val
    FROM inventory
    GROUP BY COALESCE(category, 'General')
    ORDER BY cost_val DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Receipts & Logs
$receiptsListStmt = $db->query("
    SELECT receipt_no, MIN(sale_date) as sale_date, SUM(total_sales) as grand_total, COUNT(*) as item_count 
    FROM sales 
    WHERE receipt_no IS NOT NULL AND receipt_no != '' 
    GROUP BY receipt_no 
    ORDER BY sale_date DESC 
    LIMIT 100
");
$receipts_list = $receiptsListStmt->fetchAll(PDO::FETCH_ASSOC);

$log_date = $_GET['log_date'] ?? '';
if (!empty($log_date)) {
    $logStmtFilter = $db->prepare("SELECT * FROM activity_logs WHERE DATE(timestamp) = ? ORDER BY timestamp DESC");
    $logStmtFilter->execute([$log_date]);
    $logs = $logStmtFilter->fetchAll(PDO::FETCH_ASSOC);
} else {
    $logs = $db->query("SELECT * FROM activity_logs ORDER BY timestamp DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
}

$wall_notes = $db->query("SELECT * FROM wall_notes ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$expenses_date_filter = $_GET['exp_date'] ?? '';
if (!empty($expenses_date_filter)) {
    $expStmt = $db->prepare("SELECT * FROM expenses WHERE expense_date = ? ORDER BY id DESC");
    $expStmt->execute([$expenses_date_filter]);
    $expenses_list = $expStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $expenses_list = $db->query("SELECT * FROM expenses ORDER BY expense_date DESC, id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
}

// Purchases Filtering & Summaries
$purchases_date_filter = $_GET['pur_date'] ?? '';
$purchases_status_filter = $_GET['pur_status'] ?? '';

$sqlPur = "SELECT * FROM purchases WHERE 1=1";
$paramsPur = [];

if (!empty($purchases_date_filter)) {
    $sqlPur .= " AND purchase_date = ?";
    $paramsPur[] = $purchases_date_filter;
}

if (!empty($purchases_status_filter)) {
    $sqlPur .= " AND payment_status = ?";
    $paramsPur[] = $purchases_status_filter;
}

$sqlPur .= " ORDER BY purchase_date DESC, id DESC LIMIT 300";
$purStmt = $db->prepare($sqlPur);
$purStmt->execute($paramsPur);
$purchases_list = $purStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Purchases Financial Totals
$purSummary = $db->query("
    SELECT 
        COALESCE(SUM(total_cost), 0) as grand_total,
        COALESCE(SUM(discount), 0) as total_discounts,
        COALESCE(SUM(CASE WHEN payment_status = 'Paid' THEN total_cost ELSE 0 END), 0) as paid_total,
        COALESCE(SUM(CASE WHEN payment_status = 'Unpaid' THEN total_cost ELSE 0 END), 0) as unpaid_total
    FROM purchases
")->fetch(PDO::FETCH_ASSOC);

$inventory = $db->query("SELECT * FROM inventory ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$tab = $_GET['tab'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MANUKAN NG BAYAN - POS Checkout</title>
    <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #f4f6f9;
            --panel-bg: #ffffff;
            --card-bg: #f8fafc;
            --border-color: #d1d9e6;
            --accent-blue: #0066ff;
            --accent-green: #00b33c;
            --accent-red: #ff3333;
            --accent-purple: #8a2be2;
            --accent-amber: #f59e0b;
            --text-main: #2c3e50;
            --text-muted: #7f8c8d;
            --input-bg: #ffffff;
            --table-header-bg: #f1f5f9;
            --table-row-even: #fafbfc;
            --table-row-hover: #f1f5f9;
            --modal-bg: #ffffff;
            --sticky-note-bg: #fff9c4;
            --sticky-note-border: #fbc02d;
            --sticky-note-title: #f57f17;
            --sticky-note-text: #333333;
            --accent-soft-blue: #f0f7ff;
            --accent-soft-green: rgba(0, 179, 60, 0.08);
            --accent-soft-purple: rgba(138, 43, 226, 0.08);
            --accent-soft-red: rgba(255, 51, 51, 0.08);
            --accent-soft-amber: rgba(245, 158, 11, 0.1);
        }

        body.dark-mode {
            --bg-color: #0f172a;
            --panel-bg: #1e293b;
            --card-bg: #334155;
            --border-color: #475569;
            --accent-blue: #38bdf8;
            --accent-green: #4ade80;
            --accent-red: #f87171;
            --accent-purple: #c084fc;
            --accent-amber: #fbbf24;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --input-bg: #0f172a;
            --table-header-bg: #334155;
            --table-row-even: #1e293b;
            --table-row-hover: #334155;
            --modal-bg: #1e293b;
            --sticky-note-bg: #334155;
            --sticky-note-border: #f59e0b;
            --sticky-note-title: #fbbf24;
            --sticky-note-text: #f8fafc;
            --accent-soft-blue: rgba(56, 189, 248, 0.12);
            --accent-soft-green: rgba(74, 222, 128, 0.12);
            --accent-soft-purple: rgba(192, 132, 252, 0.12);
            --accent-soft-red: rgba(248, 113, 113, 0.12);
            --accent-soft-amber: rgba(251, 191, 36, 0.12);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            display: flex;
            min-height: 100vh;
            transition: background-color 0.3s, color 0.3s;
        }

        /* DYNAMIC HOVER-EXPANDABLE SIDEBAR */
        .sidebar {
            width: 65px;
            background: var(--panel-bg);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            padding: 20px 10px;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.03);
            box-sizing: border-box;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1), padding 0.3s ease;
            overflow: hidden;
            white-space: nowrap;
            z-index: 100;
            flex-shrink: 0;
        }

        .sidebar:hover {
            width: 280px;
            padding: 20px;
        }

        .sidebar:not(:hover) .nav-text {
            display: none !important;
        }

        .sidebar:not(:hover) hr {
            opacity: 0;
        }

        .sidebar:not(:hover) .export-box {
            display: none !important;
        }

        .sidebar h2 {
            font-family: 'Share Tech Mono', monospace;
            color: var(--accent-blue);
            font-size: 16px;
            letter-spacing: 2px;
            text-transform: uppercase;
            border-left: 4px solid var(--accent-blue);
            padding-left: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            color: var(--text-muted);
            text-decoration: none;
            font-family: 'Share Tech Mono', monospace;
            padding: 10px 12px;
            margin-bottom: 8px;
            border-radius: 6px;
            border: 1px solid transparent;
            letter-spacing: 1px;
            text-transform: uppercase;
            font-size: 13px;
            transition: background 0.2s, color 0.2s, border-color 0.2s;
            white-space: nowrap;
            gap: 12px;
        }

        .sidebar a:hover, .sidebar a.active {
            color: var(--accent-blue);
            background: var(--accent-soft-blue);
            border-color: rgba(0, 102, 255, 0.2);
        }

        .nav-icon {
            font-size: 16px;
            min-width: 24px;
            text-align: center;
            display: inline-block;
        }

        .theme-toggle-btn {
            background: var(--card-bg);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 10px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-family: 'Share Tech Mono', monospace;
            font-size: 13px;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 15px;
            width: 100%;
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 12px;
            white-space: nowrap;
        }

        .theme-toggle-btn:hover {
            border-color: var(--accent-blue);
            color: var(--accent-blue);
        }

        .export-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            padding: 12px;
            border-radius: 6px;
            margin-top: 15px;
            white-space: normal;
        }

        .export-box label {
            font-size: 11px;
            margin-bottom: 3px;
        }

        .export-box input[type="date"] {
            padding: 6px;
            font-size: 12px;
            margin-bottom: 8px;
        }

        .main-content {
            flex: 1;
            padding: 40px;
            overflow-y: auto;
            max-width: 100%;
            transition: padding 0.3s ease;
        }

        .container {
            background: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
        }

        h1 {
            font-family: 'Share Tech Mono', monospace;
            color: var(--text-main);
            letter-spacing: 2px;
            text-transform: uppercase;
            border-left: 4px solid var(--accent-blue);
            padding-left: 12px;
            margin-top: 0;
            font-size: 22px;
        }

        h2 {
            font-family: 'Share Tech Mono', monospace;
            color: var(--accent-blue);
            font-size: 18px;
            margin-top: 0;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .date-picker-bar {
            display: flex;
            align-items: center;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            gap: 15px;
        }

        .date-picker-bar label {
            margin: 0;
            font-weight: bold;
        }

        .date-picker-bar input[type="date"], .date-picker-bar input[type="month"], .date-picker-bar select {
            margin: 0;
            max-width: 200px;
            font-weight: bold;
            color: var(--accent-blue);
        }

        .metrics {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .card {
            flex: 1;
            min-width: 180px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            padding: 20px;
            border-radius: 8px;
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 3px;
            background: var(--accent-blue);
        }

        .card.profit::before {
            background: var(--accent-green);
        }

        .card.expense::before {
            background: var(--accent-red);
        }

        .card.portfolio::before {
            background: var(--accent-purple);
        }

        .card.amber::before {
            background: var(--accent-amber);
        }

        .card h3 {
            margin: 0 0 8px 0;
            font-size: 12px;
            font-family: 'Share Tech Mono', monospace;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .card p {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
            font-family: 'Share Tech Mono', monospace;
            color: var(--accent-blue);
        }

        .card.profit p {
            color: var(--accent-green);
        }

        .card.expense p {
            color: var(--accent-red);
        }

        .card.portfolio p {
            color: var(--accent-purple);
        }

        .card.amber p {
            color: var(--accent-amber);
        }

        .grid-actions {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .panel-box {
            flex: 1;
            min-width: 300px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            padding: 20px;
            border-radius: 8px;
        }

        label {
            font-size: 12px;
            font-family: 'Share Tech Mono', monospace;
            color: var(--text-muted);
            letter-spacing: 1px;
            display: block;
            margin-bottom: 5px;
            text-transform: uppercase;
        }

        input, select, textarea {
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 10px;
            margin: 0 0 15px 0;
            width: 100%;
            box-sizing: border-box;
            border-radius: 6px;
            font-family: 'Inter', sans-serif;
            transition: 0.2s;
        }

        .search-container {
            position: relative;
            margin-bottom: 15px;
        }

        .suggestions-list {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--panel-bg);
            border: 1px solid var(--accent-blue);
            border-radius: 6px;
            max-height: 180px;
            overflow-y: auto;
            z-index: 999;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            display: none;
        }

        .suggestion-item {
            padding: 10px 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            transition: background-color 0.1s, color 0.1s;
        }

        .suggestion-item:hover, .suggestion-item.active-suggestion {
            background-color: var(--accent-soft-blue);
            color: var(--accent-blue);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.1);
        }

        button {
            background: var(--panel-bg);
            color: var(--accent-blue);
            border: 1px solid var(--accent-blue);
            padding: 10px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-family: 'Share Tech Mono', monospace;
            letter-spacing: 1px;
            font-weight: bold;
            width: 100%;
            text-transform: uppercase;
            transition: 0.2s;
        }

        button:hover {
            background: var(--accent-blue);
            color: #ffffff;
        }

        button.add-bag-btn {
            background: var(--accent-soft-blue);
            color: var(--accent-blue);
            border: 2px solid var(--accent-blue);
        }

        button.add-bag-btn:hover {
            background: var(--accent-blue);
            color: #ffffff;
        }

        button.clear-btn {
            background: transparent;
            color: var(--accent-red);
            border: 1px solid var(--accent-red);
        }

        button.clear-btn:hover {
            background: var(--accent-red);
            color: #ffffff;
        }

        button.sale-btn {
            color: var(--accent-green);
            border-color: var(--accent-green);
            font-size: 15px;
            padding: 14px;
        }

        button.sale-btn:hover {
            background: var(--accent-green);
            color: #ffffff;
        }

        button.edit-btn {
            background: transparent;
            color: var(--accent-blue);
            border: 1px solid var(--accent-blue);
            padding: 4px 8px;
            width: auto;
            font-size: 11px;
        }

        button.edit-btn:hover {
            background: var(--accent-blue);
            color: #ffffff;
        }

        button.delete-btn {
            background: transparent;
            color: var(--accent-red);
            border: 1px solid var(--accent-red);
            padding: 4px 8px;
            width: auto;
            font-size: 11px;
        }

        button.delete-btn:hover {
            background: var(--accent-red);
            color: #ffffff;
        }

        .status-badge {
            font-size: 11px;
            font-family: 'Share Tech Mono', monospace;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: bold;
            display: inline-block;
            text-transform: uppercase;
        }

        .status-badge.paid {
            background: var(--accent-soft-green);
            color: var(--accent-green);
            border: 1px solid var(--accent-green);
        }

        .status-badge.unpaid {
            background: var(--accent-soft-amber);
            color: var(--accent-amber);
            border: 1px solid var(--accent-amber);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 14px;
        }

        th, td {
            border: 1px solid var(--border-color);
            padding: 10px;
            text-align: left;
        }

        th {
            background: var(--table-header-bg);
            font-family: 'Share Tech Mono', monospace;
            color: var(--accent-blue);
            letter-spacing: 1px;
            text-transform: uppercase;
            font-size: 12px;
        }

        tr:nth-child(even) {
            background: var(--table-row-even);
        }

        tr:hover {
            background: var(--table-row-hover);
        }

        .msg {
            background: var(--accent-soft-green);
            border: 1px solid var(--accent-green);
            color: var(--accent-green);
            font-family: 'Share Tech Mono', monospace;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            letter-spacing: 1px;
        }

        .progress-bar-bg {
            background: var(--border-color);
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 6px;
        }

        .progress-bar-fill {
            background: var(--accent-purple);
            height: 100%;
            border-radius: 4px;
        }

        .bulk-action-bar {
            background: var(--accent-soft-purple);
            border: 1px solid var(--accent-purple);
            padding: 12px 18px;
            border-radius: 8px;
            margin-top: 15px;
            display: none;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .bulk-action-bar label {
            margin: 0;
            color: var(--accent-purple);
            font-weight: bold;
        }

        .bulk-action-bar select, .bulk-action-bar input[type="text"] {
            margin: 0;
            max-width: 200px;
            padding: 8px;
            font-size: 12px;
        }

        .bulk-action-bar button {
            width: auto;
            padding: 8px 16px;
            font-size: 12px;
            background: var(--accent-purple);
            color: #ffffff;
            border-color: var(--accent-purple);
        }

        .bulk-action-bar button:hover {
            opacity: 0.9;
            background: var(--accent-purple);
        }

        .bulk-action-bar button.bulk-delete-btn {
            background: transparent;
            color: var(--accent-red);
            border-color: var(--accent-red);
        }

        .bulk-action-bar button.bulk-delete-btn:hover {
            background: var(--accent-red);
            color: #ffffff;
        }

        .notes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }

        .sticky-note {
            background: var(--sticky-note-bg);
            border: 1px solid var(--sticky-note-border);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 180px;
            transition: transform 0.2s;
        }

        .sticky-note:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
        }

        .sticky-note h3 {
            font-family: 'Share Tech Mono', monospace;
            color: var(--sticky-note-title);
            margin: 0 0 8px 0;
            font-size: 16px;
        }

        .sticky-note p {
            margin: 0 0 15px 0;
            font-size: 14px;
            color: var(--sticky-note-text);
            white-space: pre-wrap;
            line-height: 1.4;
            flex: 1;
        }

        .sticky-note .note-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px dashed var(--sticky-note-border);
            padding-top: 10px;
        }

        .sticky-note .timestamp {
            font-size: 11px;
            color: var(--text-muted);
            font-family: 'Share Tech Mono', monospace;
        }

        .pos-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .cart-summary-box {
            background: var(--panel-bg);
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
        }

        .cashier-bar {
            background: var(--accent-soft-blue);
            border: 1px solid var(--border-color);
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .cashier-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            font-family: 'Share Tech Mono', monospace;
        }

        .cashier-row.grand-total {
            font-size: 20px;
            color: var(--accent-blue);
            border-bottom: 1px dashed var(--border-color);
            padding-bottom: 8px;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 999;
            justify-content: center;
            align-items: center;
        }

        .modal-box {
            background: var(--modal-bg);
            padding: 25px;
            border-radius: 10px;
            width: 420px;
            max-width: 90%;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
            color: var(--text-main);
        }

        .thermal-receipt {
            font-family: 'Share Tech Mono', monospace;
            background: #ffffff;
            color: #000000;
            padding: 20px;
            width: 100%;
            max-width: 320px;
            margin: 0 auto;
            box-sizing: border-box;
            border: 1px dashed #999;
        }

        .statement-table {
            font-family: 'Share Tech Mono', monospace;
            font-size: 15px;
        }

        .statement-table td {
            padding: 12px 15px;
        }

        .statement-table tr.header-row td {
            background: var(--card-bg);
            font-weight: bold;
            color: var(--accent-blue);
            font-size: 14px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .statement-table tr.total-row td {
            font-weight: bold;
            font-size: 16px;
            border-top: 2px solid var(--border-color);
            border-bottom: 2px double var(--border-color);
        }

        @media print {
            body * {
                visibility: hidden;
            }
            #receiptModalBox, #receiptModalBox *, #purReceiptModalBox, #purReceiptModalBox * {
                visibility: visible;
            }
            #receiptModalBox, #purReceiptModalBox {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                box-shadow: none;
                border: none;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>

<div class="sidebar">
    <h2><span class="nav-icon">🏪</span><span class="nav-text"> STORE</span></h2>
    <button type="button" class="theme-toggle-btn" id="themeToggleBtn" onclick="toggleTheme()">
        <span class="nav-icon" id="themeIcon">🌙</span><span class="nav-text" id="themeText"> Dark Mode</span>
    </button>
    <a href="?tab=dashboard&sale_date=<?= urlencode($active_date) ?>" class="<?= $tab === 'dashboard' ? 'active' : '' ?>">
        <span class="nav-icon">📊</span><span class="nav-text"> Daily POS & Cart</span>
    </a>
    <a href="?tab=income_statement&sale_date=<?= urlencode($active_date) ?>" class="<?= $tab === 'income_statement' ? 'active' : '' ?>">
        <span class="nav-icon">📑</span><span class="nav-text"> Income Statement</span>
    </a>
    <a href="?tab=portfolio&sale_date=<?= urlencode($active_date) ?>" class="<?= $tab === 'portfolio' ? 'active' : '' ?>">
        <span class="nav-icon">📈</span><span class="nav-text"> Portfolio & Growth</span>
    </a>
    <a href="?tab=analytics&sale_date=<?= urlencode($active_date) ?>" class="<?= $tab === 'analytics' ? 'active' : '' ?>">
        <span class="nav-icon">📅</span><span class="nav-text"> Range Analytics</span>
    </a>
    <a href="?tab=purchases&sale_date=<?= urlencode($active_date) ?>" class="<?= $tab === 'purchases' ? 'active' : '' ?>">
        <span class="nav-icon">🛒</span><span class="nav-text"> Purchase Tracker</span>
    </a>
    <a href="?tab=expenses&sale_date=<?= urlencode($active_date) ?>" class="<?= $tab === 'expenses' ? 'active' : '' ?>">
        <span class="nav-icon">💸</span><span class="nav-text"> Business Expenses</span>
    </a>
    <a href="?tab=receipts&sale_date=<?= urlencode($active_date) ?>" class="<?= $tab === 'receipts' ? 'active' : '' ?>">
        <span class="nav-icon">🧾</span><span class="nav-text"> Customer Receipts</span>
    </a>
    <a href="?tab=inventory&sale_date=<?= urlencode($active_date) ?>" class="<?= $tab === 'inventory' ? 'active' : '' ?>">
        <span class="nav-icon">📦</span><span class="nav-text"> Store Inventory</span>
    </a>
    <a href="?tab=wall_notes&sale_date=<?= urlencode($active_date) ?>" class="<?= $tab === 'wall_notes' ? 'active' : '' ?>">
        <span class="nav-icon">📌</span><span class="nav-text"> Notes on the Wall</span>
    </a>
    <a href="?tab=logs&sale_date=<?= urlencode($active_date) ?>" class="<?= $tab === 'logs' ? 'active' : '' ?>">
        <span class="nav-icon">📋</span><span class="nav-text"> Audit Ledger</span>
    </a>
    <a href="?tab=settings&sale_date=<?= urlencode($active_date) ?>" class="<?= $tab === 'settings' ? 'active' : '' ?>">
        <span class="nav-icon">⚙️</span><span class="nav-text"> System Settings</span>
    </a>
    
    <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 15px 0;">
    
    <div class="export-box">
        <h3 style="font-family:'Share Tech Mono'; font-size:12px; color:var(--accent-green); margin:0 0 8px 0;">📥 EXPORT SPREADSHEET</h3>
        <form method="GET">
            <input type="hidden" name="export_range" value="true">
            <label>From Date:</label>
            <input type="date" name="start_date" value="<?= date('Y-m-01') ?>" required>
            <label>To Date:</label>
            <input type="date" name="end_date" value="<?= date('Y-m-d') ?>" required>
            <button type="submit" style="font-size: 11px; padding: 8px; color: var(--accent-green); border-color: var(--accent-green);">Download CSV</button>
        </form>
    </div>
</div>

<div class="main-content">
    <div class="container">
        <?php if($message): ?><div class="msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>
            <h1>Multi-Item POS & Cashier Counter</h1>
            <br>

            <form method="GET" class="date-picker-bar">
                <input type="hidden" name="tab" value="dashboard">
                <label for="sale_date">Select Active Sheet Date:</label>
                <input type="date" id="sale_date" name="sale_date" value="<?= htmlspecialchars($active_date) ?>" onchange="this.form.submit()">
                <span style="font-size: 13px; color: var(--text-muted); font-family: 'Share Tech Mono', monospace;">(Viewing records for: <strong><?= date('F d, Y', strtotime($active_date)) ?></strong>)</span>
            </form>

            <div class="pos-grid">
                <!-- ITEM SELECTOR PANEL -->
                <div class="panel-box">
                    <h2>1. Select Product to Add</h2>
                    <br>
                    
                    <label>Search Product (Type to Suggest):</label>
                    <div class="search-container">
                        <input type="text" id="assetSearch" placeholder="e.g., Whole Chicken, Beer, Cooking Oil..." autocomplete="off" oninput="showSuggestions(this.value)" onkeydown="handleSearchKeydown(event)">
                        <div id="suggestionsList" class="suggestions-list"></div>
                    </div>

                    <label>Target Product Item:</label>
                    <select id="assetSelect" onchange="updateWeightLabel(this)">
                        <option value="">-- Select Product --</option>
                        <option value="manual" style="font-weight: bold; color: var(--accent-blue);">+ Enter Item Manually (Unlisted Product)</option>
                        <?php foreach($inventory as $inv): ?>
                            <?php $u = ($supports_unit && isset($inv['unit'])) ? $inv['unit'] : 'Piece'; ?>
                            <option value="<?= $inv['id'] ?>" data-unit="<?= $u ?>" data-price="<?= $inv['selling_price'] ?>" data-cost="<?= $inv['buying_price'] ?>" data-stock="<?= $inv['quantity'] ?>" data-name="<?= htmlspecialchars($inv['item_name']) ?>"><?= htmlspecialchars($inv['item_name']) ?> [₱<?= number_format($inv['selling_price'], 2) ?>/<?= $u ?> | Stock: <?= $inv['quantity'] ?> <?= $u ?>]</option>
                        <?php endforeach; ?>
                    </select>

                    <div id="customPriceBox" style="display: none; background: var(--panel-bg); padding: 12px; border-radius: 8px; margin-bottom: 15px; border: 1px solid var(--accent-blue);">
                        <label style="color: var(--accent-blue); font-weight: bold; margin-bottom: 5px;">🏷️ Selling Price (₱):</label>
                        <input type="number" step="0.01" id="customPriceInput" placeholder="0.00" style="margin-bottom: 0;">
                    </div>

                    <div id="manualItemBox" style="display: none; background: var(--panel-bg); padding: 12px; border-radius: 8px; margin-bottom: 15px; border: 1px solid var(--accent-blue);">
                        <label style="color: var(--accent-blue); font-weight: bold; margin-bottom: 8px;">✏️ Custom / Unlisted Product Details:</label>
                        
                        <label style="font-size: 11px;">Item Name / Description:</label>
                        <input type="text" id="manualNameInput" placeholder="e.g. Custom Chicken Cut, Special Drink..." style="margin-bottom: 8px;">

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px;">
                            <div>
                                <label style="font-size: 10px;">Selling Price (₱):</label>
                                <input type="number" step="0.01" id="manualPriceInput" placeholder="0.00" style="margin-bottom: 0;">
                            </div>
                            <div>
                                <label style="font-size: 10px;">Cost Price (₱ - Optional):</label>
                                <input type="number" step="0.01" id="manualCostInput" placeholder="0.00" value="0.00" style="margin-bottom: 0;">
                            </div>
                        </div>

                        <label style="font-size: 11px;">Unit of Measurement:</label>
                        <select id="manualUnitInput" style="margin-bottom: 0;" onchange="updateManualUnitLabel(this.value)">
                            <option value="Piece">Piece (pc)</option>
                            <option value="Kilo (kg)">Kilo (kg)</option>
                            <option value="Pack">Pack / Sachet</option>
                            <option value="Bottle">Bottle</option>
                            <option value="Liter">Liter (L)</option>
                            <option value="Case">Case / Box</option>
                        </select>
                    </div>

                    <div id="weightHelper" style="display: none; background: var(--panel-bg); padding: 12px; border-radius: 8px; margin-bottom: 15px; border: 1px solid var(--accent-blue);">
                        <label style="color: var(--accent-blue); font-weight: bold; margin-bottom: 8px;">⚖️ Weight & Price Converter:</label>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-bottom: 10px;">
                            <div>
                                <label style="font-size: 10px;">Kilos (kg):</label>
                                <input type="number" step="0.001" id="kgInput" placeholder="e.g. 1.7" oninput="calcFromKg(this.value)" style="margin-bottom: 0; padding: 6px;">
                            </div>
                            <div>
                                <label style="font-size: 10px;">Grams (g):</label>
                                <input type="number" id="gramsInput" placeholder="e.g. 500" oninput="calcFromGrams(this.value)" style="margin-bottom: 0; padding: 6px;">
                            </div>
                            <div>
                                <label style="font-size: 10px;">Target (₱):</label>
                                <input type="number" id="amountInput" placeholder="e.g. 100" oninput="calcFromAmount(this.value)" style="margin-bottom: 0; padding: 6px;">
                            </div>
                        </div>

                        <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                            <button type="button" onclick="setExactWeight(0.25)" style="padding: 4px 8px; font-size: 10px; width: auto;">250g</button>
                            <button type="button" onclick="setExactWeight(0.50)" style="padding: 4px 8px; font-size: 10px; width: auto;">500g</button>
                            <button type="button" onclick="setExactWeight(0.75)" style="padding: 4px 8px; font-size: 10px; width: auto;">750g</button>
                            <button type="button" onclick="setExactWeight(1.00)" style="padding: 4px 8px; font-size: 10px; width: auto;">1 kg</button>
                            <button type="button" onclick="resetWeight()" style="border-color:var(--accent-red); color:var(--accent-red); padding: 4px 8px; font-size: 10px; width: auto;">Clear</button>
                        </div>
                    </div>

                    <label id="weightLabelDisplay">Quantity / Weight:</label>
                    <input type="number" step="0.001" id="qtyInput" value="" min="0.001" placeholder="Enter quantity / weight">

                    <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                        <button type="button" class="add-bag-btn" onclick="addToBag()" style="margin-bottom: 0; flex: 2;">🛍️ Add Item to Bag</button>
                        <button type="button" class="clear-btn" onclick="clearProductInputs()" style="margin-bottom: 0; flex: 1;">🧹 Clear</button>
                    </div>
                </div>

                <!-- SHOPPING BAG / CART & CASHIER PANEL -->
                <div class="panel-box">
                    <h2>2. Customer Shopping Bag & Checkout</h2>
                    <br>
                    <form method="POST" onsubmit="return validateAndConfirmCheckout()">
                        <input type="hidden" name="process_batch_checkout" value="1">
                        <input type="hidden" name="target_date" value="<?= htmlspecialchars($active_date) ?>">
                        <input type="hidden" name="cart_json" id="cartJsonInput" value="[]">

                        <div class="cart-summary-box">
                            <table style="margin-top:0;" id="cartTable">
                                <thead>
                                    <tr>
                                        <th>Item Name</th>
                                        <th>Qty</th>
                                        <th>Price</th>
                                        <th>Subtotal</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="cartTableBody">
                                    <tr>
                                        <td colspan="5" style="text-align:center; color: var(--text-muted);">Shopping bag is empty. Select products on the left.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="cashier-bar">
                            <div class="cashier-row grand-total">
                                <span>Grand Total:</span>
                                <strong>₱<span id="displayGrandTotal">0.00</span></strong>
                            </div>

                            <div style="margin-top: 10px;">
                                <label style="color: var(--accent-green); font-weight: bold;">💵 Cash Tendered (₱):</label>
                                <input type="number" step="0.01" id="cashInput" placeholder="e.g. 500, 1000" oninput="calculateCashierChange()" style="border-color: var(--accent-green); font-weight: bold; margin-bottom: 8px;">
                            </div>

                            <div class="cashier-row" style="font-size: 16px; margin-top: 5px;">
                                <span>Change Due:</span>
                                <strong id="changeContainer" style="color: var(--accent-green);">₱<span id="displayChange">0.00</span></strong>
                            </div>
                        </div>

                        <button type="submit" class="sale-btn" style="margin-top: 15px;">Complete & Process Checkout</button>
                    </form>
                </div>
            </div>

            <h2 style="margin-top: 35px;">Recorded Sales for <?= date('F d, Y', strtotime($active_date)) ?></h2>
            <table>
                <tr>
                    <th>Row ID</th>
                    <th>Receipt No</th>
                    <th>Product Name</th>
                    <th>Quantity Sold</th>
                    <th>Total Revenue</th>
                    <th>Net Profit</th>
                    <th>Timestamp</th>
                    <th>Actions</th>
                </tr>
                <?php if(count($today_sales_list) > 0): ?>
                    <?php foreach($today_sales_list as $ts): ?>
                    <?php $u = ($supports_unit && isset($ts['unit'])) ? $ts['unit'] : 'Piece'; ?>
                    <tr>
                        <td>#<?= $ts['id'] ?></td>
                        <td><a href="javascript:void(0)" onclick="openReceiptModalByNo('<?= $ts['receipt_no'] ?>')" style="color: var(--accent-blue); font-weight: bold;"><?= $ts['receipt_no'] ?? 'N/A' ?></a></td>
                        <td><?= htmlspecialchars($ts['item_name']) ?></td>
                        <td><?= $ts['quantity_sold'] ?> <?= $u ?></td>
                        <td>₱<?= number_format($ts['total_sales'], 2) ?></td>
                        <td style="color: var(--accent-green); font-weight: bold;">₱<?= number_format($ts['total_profit'], 2) ?></td>
                        <td><?= $ts['sale_date'] ?></td>
                        <td style="display: flex; gap: 5px;">
                            <button type="button" class="edit-btn" onclick="openEditSaleModal(<?= $ts['id'] ?>, <?= $ts['item_id'] ? $ts['item_id'] : 'null' ?>, '<?= addslashes(htmlspecialchars($ts['item_name'])) ?>', <?= $ts['quantity_sold'] ?>, <?= $ts['total_sales'] ?>, '<?= $u ?>')">✏️ Edit</button>
                            <form method="POST" onsubmit="return confirm('⚠️ Are you sure you want to delete this sale entry (#<?= $ts['id'] ?>)? Inventory stock will be automatically restored.');" style="margin:0;">
                                <input type="hidden" name="delete_sale" value="1">
                                <input type="hidden" name="sale_id" value="<?= $ts['id'] ?>">
                                <button type="submit" class="delete-btn">🗑️ Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--text-muted);">No sales recorded for <?= date('F d, Y', strtotime($active_date)) ?>.</td>
                    </tr>
                <?php endif; ?>
            </table>

            <!-- POPUP EDIT RECORDED SALE MODAL -->
            <div id="editSaleModal" class="modal-overlay">
                <div class="modal-box">
                    <h2>✏️ Edit Recorded Sale Entry</h2>
                    <br>
                    <form method="POST">
                        <input type="hidden" name="update_sale" value="1">
                        <input type="hidden" name="sale_id" id="edit_sale_id">
                        <input type="hidden" name="edit_sale_item_id" id="edit_sale_item_id">

                        <label>Product Name (Type to Search Inventory):</label>
                        <div class="search-container">
                            <input type="text" name="edit_sale_item_name" id="edit_sale_item_name" required placeholder="Type product name..." autocomplete="off" oninput="showEditSaleSuggestions(this.value)">
                            <div id="editSaleSuggestionsList" class="suggestions-list"></div>
                        </div>

                        <label id="editSaleQtyLabel">Quantity Sold:</label>
                        <input type="number" step="0.001" name="edit_sale_qty" id="edit_sale_qty" required placeholder="0.000">

                        <label>Total Revenue (₱):</label>
                        <input type="number" step="0.01" name="edit_sale_total" id="edit_sale_total" required placeholder="0.00">

                        <div style="display: flex; gap: 10px; margin-top: 10px;">
                            <button type="submit" style="background: var(--accent-green); color: #ffffff; border-color: var(--accent-green);">Save Sale Changes</button>
                            <button type="button" onclick="closeEditSaleModal()" style="border-color: var(--accent-red); color: var(--accent-red);">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            let cart = [];
            let activeSuggestionIndex = -1;

            try {
                const savedCart = localStorage.getItem('pos_shopping_cart');
                if (savedCart) {
                    cart = JSON.parse(savedCart);
                }
            } catch (e) {
                cart = [];
            }

            <?php if (!empty($checkout_success)): ?>
                localStorage.removeItem('pos_shopping_cart');
                cart = [];
            <?php endif; ?>

            const inventoryData = [
                <?php foreach($inventory as $inv): ?>
                { id: "<?= $inv['id'] ?>", name: "<?= addslashes($inv['item_name']) ?>", stock: parseFloat("<?= $inv['quantity'] ?>"), unit: "<?= ($supports_unit && isset($inv['unit'])) ? $inv['unit'] : 'Piece' ?>", price: parseFloat("<?= $inv['selling_price'] ?>"), cost: parseFloat("<?= $inv['buying_price'] ?>") },
                <?php endforeach; ?>
            ];

            function clearProductInputs() {
                document.getElementById('assetSearch').value = '';
                document.getElementById('suggestionsList').style.display = 'none';
                activeSuggestionIndex = -1;
                
                const selectElem = document.getElementById('assetSelect');
                selectElem.value = '';
                
                document.getElementById('customPriceInput').value = '';
                document.getElementById('customPriceBox').style.display = 'none';
                
                document.getElementById('manualNameInput').value = '';
                document.getElementById('manualPriceInput').value = '';
                document.getElementById('manualCostInput').value = '0.00';
                document.getElementById('manualUnitInput').value = 'Piece';
                document.getElementById('manualItemBox').style.display = 'none';
                
                document.getElementById('kgInput').value = '';
                document.getElementById('gramsInput').value = '';
                document.getElementById('amountInput').value = '';
                document.getElementById('weightHelper').style.display = 'none';
                
                document.getElementById('weightLabelDisplay').innerText = 'Quantity / Weight:';
                document.getElementById('qtyInput').value = '';
            }

            function addToBag() {
                const selectElem = document.getElementById('assetSelect');
                if (!selectElem.value) {
                    alert("Please select a product first.");
                    return;
                }

                if (selectElem.value === 'manual') {
                    const manualName = document.getElementById('manualNameInput').value.trim();
                    const manualPrice = parseFloat(document.getElementById('manualPriceInput').value || 0);
                    const manualCost = parseFloat(document.getElementById('manualCostInput').value || 0);
                    const manualUnit = document.getElementById('manualUnitInput').value || 'Piece';
                    const qty = parseFloat(document.getElementById('qtyInput').value || 0);

                    if (!manualName) {
                        alert("Please enter the custom item name.");
                        return;
                    }
                    if (manualPrice <= 0) {
                        alert("Please enter a valid selling price greater than 0.");
                        return;
                    }
                    if (qty <= 0) {
                        alert("Please enter a quantity/weight greater than zero.");
                        return;
                    }

                    const existingIndex = cart.findIndex(i => i.is_manual && i.name.toLowerCase() === manualName.toLowerCase() && i.price === manualPrice && i.unit === manualUnit);

                    if (existingIndex >= 0) {
                        cart[existingIndex].qty += qty;
                        cart[existingIndex].subtotal = cart[existingIndex].qty * manualPrice;
                    } else {
                        cart.push({
                            id: 'manual',
                            is_manual: true,
                            name: manualName + " (Manual)",
                            unit: manualUnit,
                            price: manualPrice,
                            cost: manualCost,
                            qty: qty,
                            subtotal: qty * manualPrice
                        });
                    }

                    renderCart();
                    clearProductInputs();
                    return;
                }

                const itemId = parseInt(selectElem.value);
                const selectedOption = selectElem.options[selectElem.selectedIndex];
                const baseName = selectedOption.getAttribute('data-name');
                const unit = selectedOption.getAttribute('data-unit') || 'Piece';
                const defaultPrice = parseFloat(selectedOption.getAttribute('data-price') || 0);
                const cost = parseFloat(selectedOption.getAttribute('data-cost') || 0);
                const stock = parseFloat(selectedOption.getAttribute('data-stock') || 0);
                const qty = parseFloat(document.getElementById('qtyInput').value || 0);

                const customPrice = parseFloat(document.getElementById('customPriceInput').value || 0);
                const activePrice = customPrice > 0 ? customPrice : defaultPrice;

                if (qty <= 0) {
                    alert("Please enter a quantity/weight greater than zero.");
                    return;
                }

                if (activePrice <= 0) {
                    alert("Selling price must be greater than zero.");
                    return;
                }

                let totalCartQtyForProduct = cart.filter(i => !i.is_manual && i.id === itemId).reduce((sum, item) => sum + item.qty, 0);

                if ((totalCartQtyForProduct + qty) > stock) {
                    alert(`⚠️ Stock limit reached! Only ${stock} ${unit} available in inventory.`);
                    return;
                }

                const existingIndex = cart.findIndex(i => !i.is_manual && i.id === itemId && i.price === activePrice);
                const nameLabel = activePrice !== defaultPrice ? `${baseName} (Discounted)` : baseName;

                if (existingIndex >= 0) {
                    cart[existingIndex].qty += qty;
                    cart[existingIndex].subtotal = cart[existingIndex].qty * activePrice;
                } else {
                    cart.push({
                        id: itemId,
                        is_manual: false,
                        name: nameLabel,
                        unit: unit,
                        price: activePrice,
                        cost: cost,
                        qty: qty,
                        subtotal: qty * activePrice
                    });
                }

                renderCart();
                clearProductInputs();
            }

            function removeFromBag(index) {
                cart.splice(index, 1);
                renderCart();
            }

            function renderCart() {
                const tbody = document.getElementById('cartTableBody');
                if (!tbody) return;
                tbody.innerHTML = '';

                localStorage.setItem('pos_shopping_cart', JSON.stringify(cart));

                if (cart.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color: var(--text-muted);">Shopping bag is empty. Select products on the left.</td></tr>`;
                    document.getElementById('displayGrandTotal').innerText = '0.00';
                    document.getElementById('cartJsonInput').value = '[]';
                    calculateCashierChange();
                    return;
                }

                let grandTotal = 0;

                cart.forEach((item, idx) => {
                    grandTotal += item.subtotal;
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td><strong>${item.name}</strong></td>
                        <td>${item.qty} ${item.unit}</td>
                        <td>₱${item.price.toFixed(2)}</td>
                        <td><strong>₱${item.subtotal.toFixed(2)}</strong></td>
                        <td><button type="button" class="delete-btn" onclick="removeFromBag(${idx})">X</button></td>
                    `;
                    tbody.appendChild(tr);
                });

                document.getElementById('displayGrandTotal').innerText = grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                document.getElementById('cartJsonInput').value = JSON.stringify(cart);
                calculateCashierChange();
            }

            function calculateCashierChange() {
                let grandTotal = cart.reduce((sum, item) => sum + item.subtotal, 0);
                let cashTendered = parseFloat(document.getElementById('cashInput').value || 0);
                let change = cashTendered - grandTotal;

                const changeContainer = document.getElementById('changeContainer');

                if (cashTendered > 0) {
                    if (change < 0) {
                        changeContainer.style.color = 'var(--accent-red)';
                        document.getElementById('displayChange').innerText = `(${Math.abs(change).toFixed(2)} SHORT)`;
                    } else {
                        changeContainer.style.color = 'var(--accent-green)';
                        document.getElementById('displayChange').innerText = change.toFixed(2);
                    }
                } else {
                    changeContainer.style.color = 'var(--accent-green)';
                    document.getElementById('displayChange').innerText = '0.00';
                }
            }

            function validateAndConfirmCheckout() {
                if (cart.length === 0) {
                    alert("⚠️ Shopping bag is empty. Please add items before checking out.");
                    return false;
                }
                return true;
            }

            function openEditSaleModal(id, itemId, itemName, qty, totalSales, unit) {
                document.getElementById('edit_sale_id').value = id;
                document.getElementById('edit_sale_item_id').value = itemId || '';
                document.getElementById('edit_sale_item_name').value = itemName;
                document.getElementById('edit_sale_qty').value = qty;
                document.getElementById('edit_sale_total').value = totalSales;
                document.getElementById('editSaleQtyLabel').innerText = `Quantity Sold (${unit}):`;
                document.getElementById('editSaleSuggestionsList').style.display = 'none';
                document.getElementById('editSaleModal').style.display = 'flex';
            }

            function closeEditSaleModal() {
                document.getElementById('editSaleModal').style.display = 'none';
            }

            function showEditSaleSuggestions(val) {
                const listContainer = document.getElementById('editSaleSuggestionsList');
                listContainer.innerHTML = '';

                if (!val.trim()) {
                    listContainer.style.display = 'none';
                    return;
                }

                const matches = inventoryData.filter(item => item.name.toLowerCase().includes(val.toLowerCase()));

                if (matches.length > 0) {
                    listContainer.style.display = 'block';
                    matches.forEach((item) => {
                        const div = document.createElement('div');
                        div.className = 'suggestion-item';
                        div.innerHTML = `${item.name} <span style="color: var(--text-muted); font-size: 12px;">[₱${item.price}/${item.unit} | Stock: ${item.stock} ${item.unit}]</span>`;
                        div.onclick = function() {
                            selectEditSaleItem(item);
                        };
                        listContainer.appendChild(div);
                    });
                } else {
                    listContainer.style.display = 'block';
                }

                const manualDiv = document.createElement('div');
                manualDiv.className = 'suggestion-item';
                manualDiv.style.color = 'var(--accent-blue)';
                manualDiv.style.fontWeight = 'bold';
                manualDiv.innerHTML = `➕ Set as Custom Unlisted Item: "${val}"`;
                manualDiv.onclick = function() {
                    selectEditSaleManual(val);
                };
                listContainer.appendChild(manualDiv);
            }

            function selectEditSaleItem(item) {
                document.getElementById('edit_sale_item_name').value = item.name;
                document.getElementById('edit_sale_item_id').value = item.id;
                document.getElementById('editSaleQtyLabel').innerText = `Quantity Sold (${item.unit}):`;
                document.getElementById('editSaleSuggestionsList').style.display = 'none';
            }

            function selectEditSaleManual(val) {
                document.getElementById('edit_sale_item_name').value = val;
                document.getElementById('edit_sale_item_id').value = '';
                document.getElementById('editSaleSuggestionsList').style.display = 'none';
            }

            function calcFromKg(kg) {
                document.getElementById('gramsInput').value = '';
                document.getElementById('amountInput').value = '';
                if (kg > 0) {
                    document.getElementById('qtyInput').value = parseFloat(kg).toFixed(3);
                } else {
                    document.getElementById('qtyInput').value = "";
                }
            }

            function calcFromGrams(grams) {
                document.getElementById('kgInput').value = '';
                document.getElementById('amountInput').value = '';
                if (grams > 0) {
                    const kg = grams / 1000;
                    document.getElementById('qtyInput').value = kg.toFixed(3);
                } else {
                    document.getElementById('qtyInput').value = "";
                }
            }

            function calcFromAmount(amount) {
                document.getElementById('kgInput').value = '';
                document.getElementById('gramsInput').value = '';
                const selectElem = document.getElementById('assetSelect');
                let price = 0;

                if (selectElem.value === 'manual') {
                    price = parseFloat(document.getElementById('manualPriceInput').value || 0);
                } else if (selectElem.selectedIndex >= 0) {
                    const selectedOption = selectElem.options[selectElem.selectedIndex];
                    const customPriceInput = document.getElementById('customPriceInput');
                    price = (customPriceInput && parseFloat(customPriceInput.value) > 0) ? parseFloat(customPriceInput.value) : parseFloat(selectedOption.getAttribute('data-price') || 0);
                }

                if (price > 0 && amount > 0) {
                    const kg = amount / price;
                    document.getElementById('qtyInput').value = kg.toFixed(3);
                }
            }

            function setExactWeight(val) { 
                document.getElementById('qtyInput').value = parseFloat(val).toFixed(3); 
                document.getElementById('kgInput').value = '';
                document.getElementById('gramsInput').value = '';
                document.getElementById('amountInput').value = '';
            }

            function resetWeight() { 
                document.getElementById('qtyInput').value = ""; 
                document.getElementById('kgInput').value = '';
                document.getElementById('gramsInput').value = '';
                document.getElementById('amountInput').value = '';
            }

            function showSuggestions(val) {
                const listContainer = document.getElementById('suggestionsList');
                listContainer.innerHTML = '';
                activeSuggestionIndex = -1;
                
                if (!val.trim()) {
                    listContainer.style.display = 'none';
                    return;
                }

                const matches = inventoryData.filter(item => item.name.toLowerCase().includes(val.toLowerCase()));

                if (matches.length > 0) {
                    listContainer.style.display = 'block';
                    matches.forEach((item, index) => {
                        const div = document.createElement('div');
                        div.className = 'suggestion-item';
                        div.dataset.index = index;
                        div.innerHTML = `${item.name} <span style="color: var(--text-muted); font-size: 12px;">[₱${item.price}/${item.unit} | Stock: ${item.stock} ${item.unit}]</span>`;
                        
                        div.onclick = function() {
                            selectItemFromSuggestion(item);
                        };
                        div.onmouseenter = function() {
                            highlightSuggestion(index);
                        };
                        listContainer.appendChild(div);
                    });
                } else {
                    listContainer.style.display = 'block';
                }

                const manualIndex = matches.length;
                const manualDiv = document.createElement('div');
                manualDiv.className = 'suggestion-item';
                manualDiv.dataset.index = manualIndex;
                manualDiv.style.color = 'var(--accent-blue)';
                manualDiv.style.fontWeight = 'bold';
                manualDiv.innerHTML = `➕ Type "${val}" as Custom Manual Item`;
                
                manualDiv.onclick = function() {
                    selectManualFromSuggestion(val);
                };
                manualDiv.onmouseenter = function() {
                    highlightSuggestion(manualIndex);
                };
                listContainer.appendChild(manualDiv);
            }

            function highlightSuggestion(index) {
                const items = document.querySelectorAll('.suggestion-item');
                items.forEach((item, idx) => {
                    if (idx === index) {
                        item.classList.add('active-suggestion');
                        item.scrollIntoView({ block: 'nearest' });
                    } else {
                        item.classList.remove('active-suggestion');
                    }
                });
                activeSuggestionIndex = index;
            }

            function selectItemFromSuggestion(item) {
                document.getElementById('assetSearch').value = item.name;
                document.getElementById('assetSelect').value = item.id;
                updateWeightLabelById(item.id);
                document.getElementById('suggestionsList').style.display = 'none';
                activeSuggestionIndex = -1;
            }

            function selectManualFromSuggestion(val) {
                document.getElementById('assetSearch').value = '';
                document.getElementById('assetSelect').value = 'manual';
                document.getElementById('manualNameInput').value = val;
                updateWeightLabel(document.getElementById('assetSelect'));
                document.getElementById('suggestionsList').style.display = 'none';
                activeSuggestionIndex = -1;
            }

            function handleSearchKeydown(e) {
                const listContainer = document.getElementById('suggestionsList');
                if (listContainer.style.display === 'none') return;

                const items = listContainer.querySelectorAll('.suggestion-item');
                if (items.length === 0) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeSuggestionIndex = (activeSuggestionIndex + 1) % items.length;
                    highlightSuggestion(activeSuggestionIndex);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeSuggestionIndex = (activeSuggestionIndex - 1 + items.length) % items.length;
                    highlightSuggestion(activeSuggestionIndex);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (activeSuggestionIndex >= 0 && activeSuggestionIndex < items.length) {
                        items[activeSuggestionIndex].click();
                    } else if (items.length > 0) {
                        items[0].click();
                    }
                } else if (e.key === 'Escape') {
                    listContainer.style.display = 'none';
                    activeSuggestionIndex = -1;
                }
            }

            function updateWeightLabel(selectElem) {
                const manualBox = document.getElementById('manualItemBox');
                const customPriceBox = document.getElementById('customPriceBox');
                const customPriceInput = document.getElementById('customPriceInput');
                const helper = document.getElementById('weightHelper');

                if (selectElem.value === 'manual') {
                    manualBox.style.display = 'block';
                    customPriceBox.style.display = 'none';
                    const unit = document.getElementById('manualUnitInput').value || 'Piece';
                    document.getElementById('weightLabelDisplay').innerText = `Quantity / Weight (${unit}):`;
                    if (unit.toLowerCase().includes('kilo')) {
                        helper.style.display = 'block';
                    } else {
                        helper.style.display = 'none';
                    }
                    return;
                } else {
                    manualBox.style.display = 'none';
                }

                if (selectElem.value) {
                    const selectedOption = selectElem.options[selectElem.selectedIndex];
                    const defaultPrice = parseFloat(selectedOption.getAttribute('data-price') || 0);
                    const unit = selectedOption.getAttribute('data-unit') || 'Piece';

                    customPriceInput.value = defaultPrice.toFixed(2);
                    customPriceBox.style.display = 'block';

                    document.getElementById('weightLabelDisplay').innerText = `Quantity / Weight (${unit}):`;
                    if (unit && unit.toLowerCase().includes('kilo')) {
                        helper.style.display = 'block';
                    } else {
                        helper.style.display = 'none';
                    }
                } else {
                    customPriceBox.style.display = 'none';
                    helper.style.display = 'none';
                }
            }

            function updateManualUnitLabel(unitVal) {
                const helper = document.getElementById('weightHelper');
                document.getElementById('weightLabelDisplay').innerText = `Quantity / Weight (${unitVal}):`;
                if (unitVal && unitVal.toLowerCase().includes('kilo')) {
                    helper.style.display = 'block';
                } else {
                    helper.style.display = 'none';
                }
            }

            function updateWeightLabelById(id) {
                const selectElem = document.getElementById('assetSelect');
                selectElem.value = id;
                updateWeightLabel(selectElem);
            }

            document.addEventListener('click', function(e) {
                if (!e.target.closest('.search-container')) {
                    document.getElementById('suggestionsList').style.display = 'none';
                    const editList = document.getElementById('editSaleSuggestionsList');
                    if (editList) editList.style.display = 'none';
                }
            });

            document.addEventListener('DOMContentLoaded', function() {
                renderCart();
            });
            </script>

        <?php elseif ($tab === 'income_statement'): ?>
            <h1>📑 Unified Income Statement (Profit & Loss)</h1>
            <br>

            <form method="GET" class="date-picker-bar" style="flex-wrap: wrap;">
                <input type="hidden" name="tab" value="income_statement">
                
                <div style="display: flex; align-items: center; gap: 10px;">
                    <label>Statement Period:</label>
                    <select name="inc_mode" onchange="this.form.submit()" style="margin: 0; width: 140px;">
                        <option value="day" <?= $inc_mode === 'day' ? 'selected' : '' ?>>Daily</option>
                        <option value="week" <?= $inc_mode === 'week' ? 'selected' : '' ?>>Weekly</option>
                        <option value="month" <?= $inc_mode === 'month' ? 'selected' : '' ?>>Monthly</option>
                    </select>
                </div>

                <?php if ($inc_mode === 'day'): ?>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label>Select Date:</label>
                        <input type="date" name="inc_day" value="<?= htmlspecialchars($inc_day) ?>" onchange="this.form.submit()">
                    </div>
                <?php elseif ($inc_mode === 'month'): ?>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label>Select Month:</label>
                        <input type="month" name="inc_month" value="<?= htmlspecialchars($inc_month) ?>" onchange="this.form.submit()">
                    </div>
                <?php else: ?>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label>Week Starting (Mon):</label>
                        <input type="date" name="inc_week_start" value="<?= htmlspecialchars($inc_week_start) ?>" onchange="this.form.submit()">
                    </div>
                <?php endif; ?>

                <span style="font-size: 13px; color: var(--text-muted); font-family: 'Share Tech Mono', monospace;">
                    (Period: <strong><?= date('M d, Y', strtotime($inc_start_date)) ?></strong> to <strong><?= date('M d, Y', strtotime($inc_end_date)) ?></strong>)
                </span>
            </form>

            <div class="metrics">
                <div class="card">
                    <h3>1. Gross Sales Revenue</h3>
                    <p>₱<?= number_format($inc_revenue, 2) ?></p>
                    <span style="font-size: 11px; color: var(--text-muted); font-family: 'Share Tech Mono';">Total Customer Checkout Volume</span>
                </div>
                <div class="card portfolio">
                    <h3>2. Supplier Purchases Cost</h3>
                    <p>₱<?= number_format($inc_purchases, 2) ?></p>
                    <span style="font-size: 11px; color: var(--text-muted); font-family: 'Share Tech Mono';">Stock Orders (Disc: ₱<?= number_format($inc_pur_discounts, 2) ?>)</span>
                </div>
                <div class="card expense">
                    <h3>3. Operating Expenses</h3>
                    <p>₱<?= number_format($inc_expenses, 2) ?></p>
                    <span style="font-size: 11px; color: var(--text-muted); font-family: 'Share Tech Mono';">Rent, Utilities, Staff, Supplies</span>
                </div>
                <div class="card <?= $inc_net_profit >= 0 ? 'profit' : 'expense' ?>">
                    <h3>4. Net Operating Income</h3>
                    <p>₱<?= number_format($inc_net_profit, 2) ?></p>
                    <span style="font-size: 11px; color: var(--text-muted); font-family: 'Share Tech Mono';">Sales Gross Profit minus Expenses</span>
                </div>
            </div>

            <!-- OFFICIAL INCOME STATEMENT FINANCIAL TABLE -->
            <div class="panel-box" style="margin-bottom: 30px;">
                <h2>📊 Financial Statement Ledger</h2>
                <br>
                <table class="statement-table">
                    <tbody>
                        <!-- REVENUE SECTION -->
                        <tr class="header-row">
                            <td colspan="2">1. OPERATING REVENUE / SALES</td>
                        </tr>
                        <tr>
                            <td style="padding-left: 30px;">Gross POS Sales Revenue</td>
                            <td style="text-align: right; color: var(--accent-blue); font-weight: bold;">₱<?= number_format($inc_revenue, 2) ?></td>
                        </tr>

                        <!-- COST OF GOODS / PURCHASES SECTION -->
                        <tr class="header-row">
                            <td colspan="2">2. COST OF GOODS & SUPPLIER PURCHASES</td>
                        </tr>
                        <tr>
                            <td style="padding-left: 30px;">Gross Supplier Order Invoices</td>
                            <td style="text-align: right;">₱<?= number_format($inc_purchases + $inc_pur_discounts, 2) ?></td>
                        </tr>
                        <?php if ($inc_pur_discounts > 0): ?>
                        <tr>
                            <td style="padding-left: 30px; color: var(--accent-green);">Less: Supplier Rebates & Discounts</td>
                            <td style="text-align: right; color: var(--accent-green);">- ₱<?= number_format($inc_pur_discounts, 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr style="background: var(--card-bg);">
                            <td style="padding-left: 30px; font-weight: bold;">Net Supplier Stock Outlay</td>
                            <td style="text-align: right; font-weight: bold; color: var(--accent-purple);">₱<?= number_format($inc_purchases, 2) ?></td>
                        </tr>

                        <!-- GROSS MARGIN / PROFIT -->
                        <tr class="total-row">
                            <td>GROSS MARGIN (Sales Revenue - Supplier Purchases)</td>
                            <td style="text-align: right; color: <?= $inc_gross_margin >= 0 ? 'var(--accent-green)' : 'var(--accent-red)' ?>;">₱<?= number_format($inc_gross_margin, 2) ?></td>
                        </tr>
                        <tr class="total-row">
                            <td>REALIZED SALES GROSS PROFIT (Revenue - Items Sold Cost)</td>
                            <td style="text-align: right; color: var(--accent-green);">₱<?= number_format($inc_sales_profit, 2) ?></td>
                        </tr>

                        <!-- OPERATING EXPENSES SECTION -->
                        <tr class="header-row">
                            <td colspan="2">3. OPERATING EXPENSES BREAKDOWN</td>
                        </tr>
                        <?php if (count($inc_expense_categories) > 0): ?>
                            <?php foreach ($inc_expense_categories as $expCat): ?>
                            <tr>
                                <td style="padding-left: 30px;"><?= htmlspecialchars($expCat['category']) ?> Expenses</td>
                                <td style="text-align: right; color: var(--accent-red);">₱<?= number_format($expCat['cat_total'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td style="padding-left: 30px; color: var(--text-muted); font-style: italic;">No operating expenses recorded for this period.</td>
                                <td style="text-align: right; color: var(--text-muted);">₱0.00</td>
                            </tr>
                        <?php endif; ?>
                        <tr style="background: var(--card-bg);">
                            <td style="padding-left: 30px; font-weight: bold;">Total Operating Expenses</td>
                            <td style="text-align: right; font-weight: bold; color: var(--accent-red);">₱<?= number_format($inc_expenses, 2) ?></td>
                        </tr>

                        <!-- NET OPERATING PROFIT -->
                        <tr class="total-row" style="font-size: 18px; background: var(--accent-soft-green);">
                            <td>NET OPERATING PROFIT (Realized Profit - Expenses)</td>
                            <td style="text-align: right; color: <?= $inc_net_profit >= 0 ? 'var(--accent-green)' : 'var(--accent-red)' ?>;">₱<?= number_format($inc_net_profit, 2) ?></td>
                        </tr>
                        <tr class="total-row" style="font-size: 15px;">
                            <td>NET CASHFLOW (Sales Revenue - Purchases - Expenses)</td>
                            <td style="text-align: right; color: <?= $inc_cashflow >= 0 ? 'var(--accent-blue)' : 'var(--accent-red)' ?>;">₱<?= number_format($inc_cashflow, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- DETAILED ALL-IN-ONE RECONCILIATION TABLES -->
            <h2>📋 Period Breakdown & Activity</h2>
            
            <div class="grid-actions" style="margin-top: 15px;">
                <!-- PURCHASES CORNER -->
                <div class="panel-box">
                    <h3 style="font-family:'Share Tech Mono'; color:var(--accent-purple);">🛒 Supplier Purchases (<?= count($inc_purchases_list) ?>)</h3>
                    <table style="font-size: 12px; margin-top: 10px;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Item</th>
                                <th>Supplier</th>
                                <th>Net Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($inc_purchases_list) > 0): ?>
                                <?php foreach ($inc_purchases_list as $ip): ?>
                                <tr>
                                    <td><?= $ip['purchase_date'] ?></td>
                                    <td><strong><?= htmlspecialchars($ip['item_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($ip['supplier_name'] ?: 'N/A') ?></td>
                                    <td style="color:var(--accent-purple); font-weight:bold;">₱<?= number_format($ip['total_cost'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align:center; color:var(--text-muted);">No purchases logged.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- EXPENSES CORNER -->
                <div class="panel-box">
                    <h3 style="font-family:'Share Tech Mono'; color:var(--accent-red);">💸 Operating Expenses (<?= count($inc_expenses_list) ?>)</h3>
                    <table style="font-size: 12px; margin-top: 10px;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Title</th>
                                <th>Category</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($inc_expenses_list) > 0): ?>
                                <?php foreach ($inc_expenses_list as $ie): ?>
                                <tr>
                                    <td><?= $ie['expense_date'] ?></td>
                                    <td><strong><?= htmlspecialchars($ie['title']) ?></strong></td>
                                    <td><?= htmlspecialchars($ie['category']) ?></td>
                                    <td style="color:var(--accent-red); font-weight:bold;">₱<?= number_format($ie['amount'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align:center; color:var(--text-muted);">No expenses logged.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($tab === 'portfolio'): ?>
            <h1>📈 Business Portfolio & Progress Overview</h1>
            <br>

            <div class="metrics">
                <div class="card portfolio">
                    <h3>Inventory Asset Value (Cost)</h3>
                    <p>₱<?= number_format($portfolioValuation['cost_asset_val'], 2) ?></p>
                    <span style="font-size: 11px; color: var(--text-muted); font-family: 'Share Tech Mono';">Capital tied in <?= number_format($portfolioValuation['total_units'], 2) ?> stock units</span>
                </div>
                <div class="card">
                    <h3>Potential Retail Value</h3>
                    <p>₱<?= number_format($portfolioValuation['retail_asset_val'], 2) ?></p>
                    <span style="font-size: 11px; color: var(--text-muted); font-family: 'Share Tech Mono';">Expected Gross Sales Value</span>
                </div>
                <div class="card profit">
                    <h3>All-Time Net Operating Profit</h3>
                    <p>₱<?= number_format($all_time_net_profit, 2) ?></p>
                    <span style="font-size: 11px; color: var(--text-muted); font-family: 'Share Tech Mono';">Gross Profit minus Expenses</span>
                </div>
                <div class="card profit">
                    <h3>Overall Profit Margin</h3>
                    <p><?= number_format($all_time_margin, 1) ?>%</p>
                    <span style="font-size: 11px; color: var(--text-muted); font-family: 'Share Tech Mono';">Efficiency Across All Sales</span>
                </div>
            </div>

            <div class="grid-actions" style="margin-bottom: 25px;">
                <div class="panel-box">
                    <h2>📊 Stock ROI & Margin Potential</h2>
                    <br>
                    <div style="font-family: 'Share Tech Mono'; font-size: 14px;">
                        <div style="display:flex; justify-content:space-between; margin-bottom: 8px;">
                            <span>Potential Inventory Gross Profit:</span>
                            <strong style="color: var(--accent-green);">₱<?= number_format($portfolioValuation['retail_asset_val'] - $portfolioValuation['cost_asset_val'], 2) ?></strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom: 8px;">
                            <span>Expected ROI on Stock Capital:</span>
                            <strong style="color: var(--accent-purple);"><?= number_format($inventory_roi, 1) ?>%</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between;">
                            <span>Registered Inventory Titles:</span>
                            <strong><?= $portfolioValuation['total_items'] ?> Products</strong>
                        </div>
                    </div>
                </div>

                <div class="panel-box">
                    <h2>💼 All-Time Financial Breakdown</h2>
                    <br>
                    <div style="font-family: 'Share Tech Mono'; font-size: 14px;">
                        <div style="display:flex; justify-content:space-between; margin-bottom: 8px;">
                            <span>Total Cumulative Sales Revenue:</span>
                            <strong style="color: var(--accent-blue);">₱<?= number_format($all_time_rev, 2) ?></strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom: 8px;">
                            <span>Total Cumulative Operating Expenses:</span>
                            <strong style="color: var(--accent-red);">₱<?= number_format($allTimeExpenses, 2) ?></strong>
                        </div>
                        <div style="display:flex; justify-content:space-between;">
                            <span>Expense-to-Revenue Ratio:</span>
                            <strong><?= $all_time_rev > 0 ? number_format(($allTimeExpenses / $all_time_rev) * 100, 1) : 0 ?>%</strong>
                        </div>
                    </div>
                </div>
            </div>

            <h2>🗓️ Month-by-Month Growth & Progress</h2>
            <table>
                <thead>
                    <tr>
                        <th>Month / Year</th>
                        <th>Sales Revenue</th>
                        <th>Sales Gross Profit</th>
                        <th>Operating Expenses</th>
                        <th>Net Operating Income</th>
                        <th>Net Margin %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($monthlyTrendList) > 0): ?>
                        <?php foreach ($monthlyTrendList as $m): ?>
                            <?php 
                            $m_net = $m['month_gross_profit'] - $m['month_expenses']; 
                            $m_margin = $m['month_rev'] > 0 ? ($m_net / $m['month_rev']) * 100 : 0;
                            ?>
                            <tr>
                                <td><strong><?= date('F Y', strtotime($m['month_key'] . '-01')) ?></strong></td>
                                <td>₱<?= number_format($m['month_rev'], 2) ?></td>
                                <td style="color: var(--accent-blue);">₱<?= number_format($m['month_gross_profit'], 2) ?></td>
                                <td style="color: var(--accent-red);">₱<?= number_format($m['month_expenses'], 2) ?></td>
                                <td style="color: <?= $m_net >= 0 ? 'var(--accent-green)' : 'var(--accent-red)' ?>; font-weight: bold;">
                                    ₱<?= number_format($m_net, 2) ?>
                                </td>
                                <td>
                                    <span style="font-family: 'Share Tech Mono'; font-weight: bold; color: <?= $m_margin >= 0 ? 'var(--accent-green)' : 'var(--accent-red)' ?>;">
                                        <?= number_format($m_margin, 1) ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted);">No sales or expense metrics recorded yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top: 35px;">📦 Stock Portfolio Allocation by Category</h2>
            <table>
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Product Titles</th>
                        <th>Total Stock Volume</th>
                        <th>Invested Capital (Cost)</th>
                        <th>Potential Sales Value</th>
                        <th>Portfolio Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($categoryAllocation) > 0): ?>
                        <?php foreach ($categoryAllocation as $cat): ?>
                            <?php 
                            $share = $portfolioValuation['cost_asset_val'] > 0 ? ($cat['cost_val'] / $portfolioValuation['cost_asset_val']) * 100 : 0;
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($cat['category_name']) ?></strong></td>
                                <td><?= $cat['item_count'] ?> item(s)</td>
                                <td><?= number_format($cat['stock_volume'], 2) ?> units</td>
                                <td>₱<?= number_format($cat['cost_val'], 2) ?></td>
                                <td>₱<?= number_format($cat['retail_val'], 2) ?></td>
                                <td style="width: 180px;">
                                    <div style="display:flex; justify-content:space-between; font-size:11px; font-family:'Share Tech Mono';">
                                        <span>Share:</span>
                                        <span><?= number_format($share, 1) ?>%</span>
                                    </div>
                                    <div class="progress-bar-bg">
                                        <div class="progress-bar-fill" style="width: <?= min(100, max(0, $share)) ?>%;"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted);">No inventory assets found in the system.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

        <?php elseif ($tab === 'analytics'): ?>
            <h1>Range Analytics (Daily / Weekly / Monthly)</h1>
            <br>

            <form method="GET" class="date-picker-bar" style="flex-wrap: wrap;">
                <input type="hidden" name="tab" value="analytics">
                
                <div style="display: flex; align-items: center; gap: 10px;">
                    <label>View Mode:</label>
                    <select name="mode" onchange="this.form.submit()" style="margin: 0; width: 140px;">
                        <option value="day" <?= $analytics_mode === 'day' ? 'selected' : '' ?>>Daily</option>
                        <option value="week" <?= $analytics_mode === 'week' ? 'selected' : '' ?>>Weekly</option>
                        <option value="month" <?= $analytics_mode === 'month' ? 'selected' : '' ?>>Monthly</option>
                    </select>
                </div>

                <?php if ($analytics_mode === 'day'): ?>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label>Select Date:</label>
                        <input type="date" name="day" value="<?= htmlspecialchars($selected_day) ?>" onchange="this.form.submit()">
                    </div>
                <?php elseif ($analytics_mode === 'month'): ?>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label>Select Month:</label>
                        <input type="month" name="month" value="<?= htmlspecialchars($selected_month) ?>" onchange="this.form.submit()">
                    </div>
                <?php else: ?>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label>Week Starting (Mon):</label>
                        <input type="date" name="week_start" value="<?= htmlspecialchars($selected_week_start) ?>" onchange="this.form.submit()">
                    </div>
                <?php endif; ?>
            </form>

            <div class="metrics">
                <div class="card">
                    <h3>Gross Revenue</h3>
                    <p>₱<?= number_format($range_revenue, 2) ?></p>
                </div>
                <div class="card profit">
                    <h3>Sales Gross Profit</h3>
                    <p>₱<?= number_format($range_profit, 2) ?></p>
                </div>
                <div class="card expense">
                    <h3>Operating Expenses</h3>
                    <p>₱<?= number_format($range_expenses, 2) ?></p>
                </div>
                <div class="card <?= $net_operating_profit >= 0 ? 'profit' : 'expense' ?>">
                    <h3>Net Operating Income</h3>
                    <p>₱<?= number_format($net_operating_profit, 2) ?></p>
                </div>
            </div>

            <h2 style="margin-top: 35px;">Transactions Summary List</h2>
            <table>
                <tr>
                    <th>Row ID</th>
                    <th>Receipt No</th>
                    <th>Product Name</th>
                    <th>Quantity Sold</th>
                    <th>Total Revenue</th>
                    <th>Net Profit</th>
                    <th>Timestamp</th>
                </tr>
                <?php if(count($range_transactions) > 0): ?>
                    <?php foreach($range_transactions as $rt): ?>
                    <tr>
                        <td>#<?= $rt['id'] ?></td>
                        <td><a href="javascript:void(0)" onclick="openReceiptModalByNo('<?= $rt['receipt_no'] ?>')" style="color: var(--accent-blue); font-weight: bold;"><?= $rt['receipt_no'] ?? 'N/A' ?></a></td>
                        <td><?= htmlspecialchars($rt['item_name']) ?></td>
                        <td><?= $rt['quantity_sold'] ?> <?= ($supports_unit && isset($rt['unit'])) ? $rt['unit'] : 'Piece' ?></td>
                        <td>₱<?= number_format($rt['total_sales'], 2) ?></td>
                        <td style="color: var(--accent-green); font-weight: bold;">₱<?= number_format($rt['total_profit'], 2) ?></td>
                        <td><?= $rt['sale_date'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted);">No sales recorded within this timeframe.</td>
                    </tr>
                <?php endif; ?>
            </table>

        <?php elseif ($tab === 'purchases'): ?>
            <h1>🛒 Supplier Purchases & Order Tracker</h1>
            <br>

            <div class="metrics">
                <div class="card portfolio">
                    <h3>Total Net Supplier Purchases</h3>
                    <p>₱<?= number_format($purSummary['grand_total'], 2) ?></p>
                    <span style="font-size: 11px; color: var(--text-muted); font-family: 'Share Tech Mono';">All-Time Cumulative Order Cost</span>
                </div>
                <div class="card profit">
                    <h3>Total Supplier Discounts</h3>
                    <p>₱<?= number_format($purSummary['total_discounts'], 2) ?></p>
                    <span style="font-size: 11px; color: var(--text-muted); font-family: 'Share Tech Mono';">Savings from Supplier Rebates</span>
                </div>
                <div class="card profit">
                    <h3>Total Paid Purchases</h3>
                    <p>₱<?= number_format($purSummary['paid_total'], 2) ?></p>
                    <span style="font-size: 11px; color: var(--text-muted); font-family: 'Share Tech Mono';">Cleared Supplier Payments</span>
                </div>
                <div class="card amber">
                    <h3>Total Unpaid Payables</h3>
                    <p>₱<?= number_format($purSummary['unpaid_total'], 2) ?></p>
                    <span style="font-size: 11px; color: var(--text-muted); font-family: 'Share Tech Mono';">Outstanding Pending Deliveries</span>
                </div>
            </div>

            <div class="grid-actions">
                <!-- SINGLE DIRECT PURCHASE ENTRY PANEL -->
                <div class="panel-box" style="max-width: 600px;">
                    <h2>Purchase Order Form</h2>
                    <br>
                    
                    <form method="POST" onsubmit="return prepareSinglePurchaseSubmit(event)">
                        <input type="hidden" name="add_batch_purchase" value="1">
                        <input type="hidden" name="purchase_cart_json" id="purchaseCartJson" value="[]">
                        <input type="hidden" name="discount" value="0.00">

                        <!-- Line 1: Supplier / Vendor Name -->
                        <label>Supplier / Vendor Name (Optional):</label>
                        <input type="text" name="supplier_name" id="purSupplier" placeholder="e.g. UNIMART, San Miguel Corp">

                        <!-- Line 2: Purchase / Delivery Date & Payment Status -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div>
                                <label>Purchase / Delivery Date:</label>
                                <input type="date" name="purchase_date" id="purDate" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div>
                                <label>Payment Status:</label>
                                <select name="payment_status" id="purStatus" style="font-weight: bold; color: var(--accent-blue);">
                                    <option value="Paid">✅ Paid</option>
                                    <option value="Unpaid">⚠️ Unpaid (Pending Payable)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Line 3: Item / Product Name -->
                        <label>Item / Product Name:</label>
                        <input type="text" id="purItemName" placeholder="e.g. Cups, Whole Dressed Chicken" required>

                        <!-- Line 4: Unit Cost & Total Cost -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div>
                                <label>Unit Cost (₱):</label>
                                <input type="number" step="0.01" id="purItemUnitCost" placeholder="0.00" oninput="calcPurItemTotal('unit')">
                            </div>
                            <div>
                                <label>Total Cost (₱):</label>
                                <input type="number" step="0.01" id="purItemTotalCost" placeholder="0.00" oninput="calcPurItemTotal('total')">
                            </div>
                        </div>

                        <!-- Line 5: Notes -->
                        <label>Notes / PO / Delivery Receipt # (Optional):</label>
                        <input type="text" name="notes" id="purNotes" placeholder="e.g. Cups, DR #8821">

                        <button type="submit" style="margin-top: 10px; background: var(--accent-purple); color: #ffffff; border-color: var(--accent-purple);">🛒 Save Purchase Order</button>
                    </form>
                </div>
            </div>

            <div class="panel-box" style="margin-top: 30px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
                    <h2 style="margin: 0;">Purchases History Ledger</h2>
                    
                    <form method="GET" style="display: flex; gap: 8px; margin: 0; align-items: center; flex-wrap: wrap;">
                        <input type="hidden" name="tab" value="purchases">
                        
                        <label style="margin: 0;">Status:</label>
                        <select name="pur_status" onchange="this.form.submit()" style="margin:0; padding:6px; width: auto;">
                            <option value="">All Statuses</option>
                            <option value="Paid" <?= $purchases_status_filter === 'Paid' ? 'selected' : '' ?>>✅ Paid</option>
                            <option value="Unpaid" <?= $purchases_status_filter === 'Unpaid' ? 'selected' : '' ?>>⚠️ Unpaid</option>
                        </select>

                        <label style="margin: 0;">Date:</label>
                        <input type="date" name="pur_date" value="<?= htmlspecialchars($purchases_date_filter) ?>" onchange="this.form.submit()" style="margin: 0; padding: 6px; width: auto;">
                        
                        <?php if (!empty($purchases_date_filter) || !empty($purchases_status_filter)): ?>
                            <a href="?tab=purchases&sale_date=<?= urlencode($active_date) ?>" style="font-size: 11px; text-decoration: none; color: var(--accent-red); padding: 6px 10px; border: 1px solid var(--accent-red); border-radius: 4px;">Clear Filters</a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php $total_pur_displayed = array_sum(array_column($purchases_list, 'total_cost')); ?>
                <div style="background: var(--panel-bg); border: 1px solid var(--border-color); padding: 12px; border-radius: 6px; margin-bottom: 15px; font-family: 'Share Tech Mono', monospace; display: flex; justify-content: space-between;">
                    <span>Listed Purchases Cost:</span>
                    <strong style="color: var(--accent-purple);">₱<?= number_format($total_pur_displayed, 2) ?></strong>
                </div>

                <div style="overflow-x: auto;">
                    <table style="margin-top: 0;">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Date</th>
                                <th>Supplier</th>
                                <th>Product / Item Description</th>
                                <th>Qty / Unit</th>
                                <th>Unit Cost</th>
                                <th>Discount</th>
                                <th>Total Cost</th>
                                <th>Payment Status</th>
                                <th>Notes / DR</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($purchases_list) > 0): ?>
                                <?php foreach ($purchases_list as $pur): ?>
                                    <?php 
                                    $st = $pur['payment_status'] ?? 'Paid'; 
                                    $disc = (float)($pur['discount'] ?? 0);
                                    ?>
                                    <tr>
                                        <td><a href="javascript:void(0)" onclick="openPurchaseReceiptModalByNo('<?= $pur['purchase_no'] ?>', <?= $pur['id'] ?>)" style="color: var(--accent-blue); font-weight: bold;"><?= htmlspecialchars($pur['purchase_no'] ?: 'N/A') ?></a></td>
                                        <td><?= $pur['purchase_date'] ?></td>
                                        <td><strong><?= htmlspecialchars($pur['supplier_name'] ?: 'N/A') ?></strong></td>
                                        <td><?= htmlspecialchars($pur['item_name']) ?></td>
                                        <td><?= $pur['quantity'] ?> <?= htmlspecialchars($pur['unit']) ?></td>
                                        <td>₱<?= number_format($pur['unit_cost'], 2) ?></td>
                                        <td style="color: var(--accent-green); font-weight: bold;"><?= $disc > 0 ? '-₱' . number_format($disc, 2) : '-' ?></td>
                                        <td style="color: var(--accent-purple); font-weight: bold;">₱<?= number_format($pur['total_cost'], 2) ?></td>
                                        <td>
                                            <span class="status-badge <?= strtolower($st) ?>"><?= $st === 'Paid' ? '✅ Paid' : '⚠️ Unpaid' ?></span>
                                        </td>
                                        <td style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($pur['notes'] ?? '-') ?></td>
                                        <td style="display: flex; gap: 4px;">
                                            <button type="button" class="edit-btn" style="border-color: var(--accent-purple); color: var(--accent-purple); font-size: 10px; padding: 2px 6px;" onclick="openPurchaseReceiptModalByNo('<?= $pur['purchase_no'] ?>', <?= $pur['id'] ?>)">🧾 Receipt</button>
                                            <button type="button" class="edit-btn" style="font-size: 10px; padding: 2px 6px;" onclick="openEditPurchaseModal(<?= $pur['id'] ?>, '<?= addslashes(htmlspecialchars($pur['supplier_name'] ?? '')) ?>', '<?= addslashes(htmlspecialchars($pur['item_name'])) ?>', <?= $pur['quantity'] ?>, '<?= $pur['unit'] ?>', <?= $pur['unit_cost'] ?>, <?= $disc ?>, <?= $pur['total_cost'] ?>, '<?= $pur['purchase_date'] ?>', '<?= $st ?>', '<?= addslashes(htmlspecialchars($pur['notes'] ?? '')) ?>')">✏️ Edit</button>
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="toggle_purchase_status" value="1">
                                                <input type="hidden" name="purchase_id" value="<?= $pur['id'] ?>">
                                                <input type="hidden" name="new_status" value="<?= $st === 'Paid' ? 'Unpaid' : 'Paid' ?>">
                                                <button type="submit" class="edit-btn" style="font-size: 10px; padding: 2px 6px;"><?= $st === 'Paid' ? 'Mark Unpaid' : 'Mark Paid' ?></button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('Delete this purchase record?');" style="margin:0;">
                                                <input type="hidden" name="delete_purchase" value="1">
                                                <input type="hidden" name="purchase_id" value="<?= $pur['id'] ?>">
                                                <button type="submit" class="delete-btn" style="padding: 2px 6px; font-size: 10px;">X</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" style="text-align: center; color: var(--text-muted);">No supplier purchases logged yet matching the current filters.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- POPUP EDIT PURCHASE MODAL -->
            <div id="editPurchaseModal" class="modal-overlay">
                <div class="modal-box">
                    <h2>✏️ Edit Supplier Purchase Record</h2>
                    <br>
                    <form method="POST">
                        <input type="hidden" name="update_purchase" value="1">
                        <input type="hidden" name="edit_purchase_id" id="edit_pur_id">

                        <label>Supplier / Vendor Name:</label>
                        <input type="text" name="edit_supplier_name" id="edit_pur_supplier" placeholder="e.g. San Miguel Corp">

                        <label>Product / Item Description:</label>
                        <input type="text" name="edit_item_name" id="edit_pur_item" required placeholder="e.g. Whole Chicken">

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div>
                                <label>Quantity (Optional - Default: 1):</label>
                                <input type="number" step="0.001" name="edit_quantity" id="edit_pur_qty" placeholder="1 (Optional)" oninput="calcEditPurTotal()">
                            </div>
                            <div>
                                <label>Unit:</label>
                                <select name="edit_unit" id="edit_pur_unit">
                                    <option value="Piece">Piece (pc)</option>
                                    <option value="Kilo (kg)">Kilo (kg)</option>
                                    <option value="Case">Case / Box</option>
                                    <option value="Pack">Pack / Sachet</option>
                                    <option value="Bottle">Bottle</option>
                                    <option value="Liter">Liter (L)</option>
                                </select>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px;">
                            <div>
                                <label>Unit Cost (₱):</label>
                                <input type="number" step="0.01" name="edit_unit_cost" id="edit_pur_unit_cost" oninput="calcEditPurTotal()">
                            </div>
                            <div>
                                <label>Discount (₱):</label>
                                <input type="number" step="0.01" name="edit_discount" id="edit_pur_discount" placeholder="0.00" oninput="calcEditPurTotal()">
                            </div>
                            <div>
                                <label>Net Cost (₱):</label>
                                <input type="number" step="0.01" name="edit_total_cost" id="edit_pur_total_cost" required>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div>
                                <label>Purchase Date:</label>
                                <input type="date" name="edit_purchase_date" id="edit_pur_date" required>
                            </div>
                            <div>
                                <label>Payment Status:</label>
                                <select name="edit_payment_status" id="edit_pur_status">
                                    <option value="Paid">✅ Paid</option>
                                    <option value="Unpaid">⚠️ Unpaid</option>
                                </select>
                            </div>
                        </div>

                        <label>Notes / References:</label>
                        <textarea name="edit_notes" id="edit_pur_notes" rows="2"></textarea>

                        <div style="display: flex; gap: 10px; margin-top: 10px;">
                            <button type="submit" style="background: var(--accent-green); color: #ffffff; border-color: var(--accent-green);">Save Purchase Changes</button>
                            <button type="button" onclick="closeEditPurchaseModal()" style="border-color: var(--accent-red); color: var(--accent-red);">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            function calcPurItemTotal(source) {
                const unitCostInput = document.getElementById('purItemUnitCost');
                const totalCostInput = document.getElementById('purItemTotalCost');

                if (source === 'unit') {
                    const unitCost = parseFloat(unitCostInput.value || 0);
                    totalCostInput.value = unitCost > 0 ? unitCost.toFixed(2) : '';
                } else if (source === 'total') {
                    const totalCost = parseFloat(totalCostInput.value || 0);
                    unitCostInput.value = totalCost > 0 ? totalCost.toFixed(2) : '';
                }
            }

            function prepareSinglePurchaseSubmit(e) {
                const name = document.getElementById('purItemName').value.trim();
                let cost = parseFloat(document.getElementById('purItemUnitCost').value || 0);
                let total = parseFloat(document.getElementById('purItemTotalCost').value || 0);

                if (total <= 0 && cost > 0) total = cost;
                if (cost <= 0 && total > 0) cost = total;

                if (!name) {
                    alert("Please enter the product item name.");
                    e.preventDefault();
                    return false;
                }
                if (total <= 0 && cost <= 0) {
                    alert("Please enter a valid cost amount greater than 0.");
                    e.preventDefault();
                    return false;
                }

                const singleItemCart = [{
                    name: name,
                    qty: 1,
                    unit: 'Piece',
                    cost: cost,
                    total: total
                }];

                document.getElementById('purchaseCartJson').value = JSON.stringify(singleItemCart);
                return true;
            }

            function openEditPurchaseModal(id, supplier, name, qty, unit, unitCost, discount, totalCost, date, status, notes) {
                document.getElementById('edit_pur_id').value = id;
                document.getElementById('edit_pur_supplier').value = supplier;
                document.getElementById('edit_pur_item').value = name;
                document.getElementById('edit_pur_qty').value = qty;
                document.getElementById('edit_pur_unit').value = unit;
                document.getElementById('edit_pur_unit_cost').value = unitCost;
                document.getElementById('edit_pur_discount').value = discount;
                document.getElementById('edit_pur_total_cost').value = totalCost;
                document.getElementById('edit_pur_date').value = date;
                document.getElementById('edit_pur_status').value = status;
                document.getElementById('edit_pur_notes').value = notes;
                document.getElementById('editPurchaseModal').style.display = 'flex';
            }

            function closeEditPurchaseModal() {
                document.getElementById('editPurchaseModal').style.display = 'none';
            }

            function calcEditPurTotal() {
                const rawQty = document.getElementById('edit_pur_qty').value.trim();
                const qty = rawQty === '' ? 1 : parseFloat(rawQty);
                const unitCost = parseFloat(document.getElementById('edit_pur_unit_cost').value || 0);
                const discount = parseFloat(document.getElementById('edit_pur_discount').value || 0);
                if (unitCost > 0) {
                    const net = Math.max(0, (qty * unitCost) - discount);
                    document.getElementById('edit_pur_total_cost').value = net.toFixed(2);
                }
            }
            </script>

        <?php elseif ($tab === 'expenses'): ?>
            <h1>💸 Business Expenses Tracker</h1>
            <br>

            <div class="grid-actions">
                <div class="panel-box" style="max-width: 450px;">
                    <h2>Record Business Expense</h2>
                    <br>
                    <form method="POST">
                        <input type="hidden" name="add_expense" value="1">
                        
                        <label>Expense Title / Description:</label>
                        <input type="text" name="expense_title" required placeholder="e.g. Electric Bill, Staff Allowance, Plastic Bags">

                        <label>Category:</label>
                        <select name="expense_category" required>
                            <option value="Utilities">Utilities (Electricity, Water, Internet)</option>
                            <option value="Salaries">Staff Salaries / Allowances</option>
                            <option value="Packaging">Packaging / Supplies</option>
                            <option value="Rent">Store Rent</option>
                            <option value="Transport">Transport / Delivery Fees</option>
                            <option value="Maintenance">Maintenance & Repairs</option>
                            <option value="General">General / Miscellaneous</option>
                        </select>

                        <label>Amount (₱):</label>
                        <input type="number" step="0.01" name="expense_amount" required placeholder="0.00">

                        <label>Date Incurred:</label>
                        <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required>

                        <label>Notes / Receipt Reference (Optional):</label>
                        <textarea name="expense_notes" rows="2" placeholder="e.g., Paid to Electric Coop, Official Receipt #1234"></textarea>

                        <button type="submit" style="background: var(--accent-red); color: #ffffff; border-color: var(--accent-red);">💸 Record Expense</button>
                    </form>
                </div>

                <div class="panel-box">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
                        <h2 style="margin: 0;">Expense Ledger</h2>
                        <form method="GET" style="display: flex; gap: 8px; margin: 0; align-items: center;">
                            <input type="hidden" name="tab" value="expenses">
                            <input type="date" name="exp_date" value="<?= htmlspecialchars($expenses_date_filter) ?>" onchange="this.form.submit()" style="margin: 0; padding: 6px;">
                            <?php if (!empty($expenses_date_filter)): ?>
                                <a href="?tab=expenses&sale_date=<?= urlencode($active_date) ?>" style="font-size: 11px; text-decoration: none; color: var(--accent-red); padding: 6px 10px; border: 1px solid var(--accent-red); border-radius: 4px;">Clear Filter</a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php $total_exp_displayed = array_sum(array_column($expenses_list, 'amount')); ?>
                    <div style="background: var(--panel-bg); border: 1px solid var(--border-color); padding: 12px; border-radius: 6px; margin-bottom: 15px; font-family: 'Share Tech Mono', monospace; display: flex; justify-content: space-between;">
                        <span>Total Listed Expenses:</span>
                        <strong style="color: var(--accent-red);">₱<?= number_format($total_exp_displayed, 2) ?></strong>
                    </div>

                    <div style="overflow-x: auto;">
                        <table style="margin-top: 0;">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Amount</th>
                                    <th>Notes</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($expenses_list) > 0): ?>
                                    <?php foreach ($expenses_list as $exp): ?>
                                        <tr>
                                            <td><?= $exp['expense_date'] ?></td>
                                            <td><strong><?= htmlspecialchars($exp['title']) ?></strong></td>
                                            <td><span style="font-size: 11px; font-family: 'Share Tech Mono'; background: var(--accent-soft-blue); padding: 2px 6px; border-radius: 4px; border: 1px solid var(--accent-blue);"><?= htmlspecialchars($exp['category']) ?></span></td>
                                            <td style="color: var(--accent-red); font-weight: bold;">₱<?= number_format($exp['amount'], 2) ?></td>
                                            <td style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($exp['notes'] ?? '-') ?></td>
                                            <td style="display: flex; gap: 4px;">
                                                <button type="button" class="edit-btn" onclick="openEditExpenseModal(<?= $exp['id'] ?>, '<?= addslashes(htmlspecialchars($exp['title'])) ?>', '<?= addslashes(htmlspecialchars($exp['category'])) ?>', <?= $exp['amount'] ?>, '<?= $exp['expense_date'] ?>', '<?= addslashes(htmlspecialchars($exp['notes'] ?? '')) ?>')">✏️ Edit</button>
                                                <form method="POST" onsubmit="return confirm('Delete this expense entry?');" style="margin:0;">
                                                    <input type="hidden" name="delete_expense" value="1">
                                                    <input type="hidden" name="expense_id" value="<?= $exp['id'] ?>">
                                                    <button type="submit" class="delete-btn">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: var(--text-muted);">No business expenses logged yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- POPUP EDIT EXPENSE MODAL -->
            <div id="editExpenseModal" class="modal-overlay">
                <div class="modal-box">
                    <h2>✏️ Edit Business Expense Record</h2>
                    <br>
                    <form method="POST">
                        <input type="hidden" name="update_expense" value="1">
                        <input type="hidden" name="edit_expense_id" id="edit_exp_id">

                        <label>Expense Title / Description:</label>
                        <input type="text" name="edit_expense_title" id="edit_exp_title" required placeholder="e.g. Electric Bill">

                        <label>Category:</label>
                        <select name="edit_expense_category" id="edit_exp_category" required>
                            <option value="Utilities">Utilities (Electricity, Water, Internet)</option>
                            <option value="Salaries">Staff Salaries / Allowances</option>
                            <option value="Packaging">Packaging / Supplies</option>
                            <option value="Rent">Store Rent</option>
                            <option value="Transport">Transport / Delivery Fees</option>
                            <option value="Maintenance">Maintenance & Repairs</option>
                            <option value="General">General / Miscellaneous</option>
                        </select>

                        <label>Amount (₱):</label>
                        <input type="number" step="0.01" name="edit_expense_amount" id="edit_exp_amount" required placeholder="0.00">

                        <label>Date Incurred:</label>
                        <input type="date" name="edit_expense_date" id="edit_exp_date" required>

                        <label>Notes / Receipt Reference (Optional):</label>
                        <textarea name="edit_expense_notes" id="edit_exp_notes" rows="2"></textarea>

                        <div style="display: flex; gap: 10px; margin-top: 10px;">
                            <button type="submit" style="background: var(--accent-green); color: #ffffff; border-color: var(--accent-green);">Save Expense Changes</button>
                            <button type="button" onclick="closeEditExpenseModal()" style="border-color: var(--accent-red); color: var(--accent-red);">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            function openEditExpenseModal(id, title, category, amount, date, notes) {
                document.getElementById('edit_exp_id').value = id;
                document.getElementById('edit_exp_title').value = title;
                document.getElementById('edit_exp_category').value = category;
                document.getElementById('edit_exp_amount').value = amount;
                document.getElementById('edit_exp_date').value = date;
                document.getElementById('edit_exp_notes').value = notes;
                document.getElementById('editExpenseModal').style.display = 'flex';
            }

            function closeEditExpenseModal() {
                document.getElementById('editExpenseModal').style.display = 'none';
            }
            </script>

        <?php elseif ($tab === 'receipts'): ?>
            <h1>Customer Receipts Archive</h1>
            <br>
            
            <div style="margin-bottom: 20px;">
                <input type="text" id="receiptSearch" placeholder="🔍 Search receipts by Receipt No or Date..." onkeyup="filterReceiptsTable()" style="max-width: 350px; padding: 10px;">
            </div>

            <table id="receiptsTable">
                <thead>
                    <tr>
                        <th>Receipt Number</th>
                        <th>Timestamp / Date</th>
                        <th>Total Items</th>
                        <th>Grand Total (₱)</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($receipts_list) > 0): ?>
                        <?php foreach($receipts_list as $rc): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($rc['receipt_no']) ?></strong></td>
                            <td><?= $rc['sale_date'] ?></td>
                            <td><?= $rc['item_count'] ?> item(s)</td>
                            <td style="color: var(--accent-green); font-weight: bold;">₱<?= number_format($rc['grand_total'], 2) ?></td>
                            <td>
                                <button type="button" class="edit-btn" onclick="openReceiptModalByNo('<?= $rc['receipt_no'] ?>')">🧾 View / Print Receipt</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted);">No customer receipts found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <script>
            function filterReceiptsTable() {
                const val = document.getElementById('receiptSearch').value.toLowerCase();
                const rows = document.querySelectorAll('#receiptsTable tbody tr');
                rows.forEach(r => {
                    r.style.display = r.innerText.toLowerCase().includes(val) ? '' : 'none';
                });
            }
            </script>

        <?php elseif ($tab === 'inventory'): ?>
            <h1>Store Inventory Matrix</h1>
            <br>
            <div class="grid-actions">
                <div class="panel-box">
                    <h2>Add Product & Unit Pricing</h2>
                    <br>
                    <form method="POST">
                        <input type="hidden" name="add_item" value="1">
                        
                        <label>Product Name & Description:</label>
                        <input type="text" name="item_name" required placeholder="e.g., Red Horse Beer, Cooking Oil (1L), Whole Chicken">
                        
                        <label>Product Category:</label>
                        <select name="category" required>
                            <option value="Chicken">🍗 Chicken</option>
                            <option value="Spices">🧂 Spices & Seasoning</option>
                            <option value="Drinks">🥤 Drinks & Beverages</option>
                            <option value="Pork">🥩 Pork</option>
                            <option value="Beef">🥩 Beef</option>
                            <option value="Grocery">🛒 Grocery / General</option>
                            <option value="Frozen Products">🥶 Frozen Products</option>
                        </select>

                        <?php if ($supports_unit): ?>
                        <label>Unit of Measurement:</label>
                        <select name="unit" required>
                            <option value="Piece">Piece (pc)</option>
                            <option value="Case">Case / Box</option>
                            <option value="Liter">Liter (L)</option>
                            <option value="Pack">Pack / Sachet</option>
                            <option value="Bottle">Bottle</option>
                            <option value="Kilo (kg)">Kilo (kg)</option>
                        </select>
                        <?php endif; ?>

                        <label>Initial Stock Quantity / Volume:</label>
                        <input type="number" step="0.001" name="quantity" required placeholder="0.00">

                        <label>Buying Cost Price (₱ per Unit):</label>
                        <input type="number" step="0.01" name="buying_price" required placeholder="0.00">

                        <label>Selling Price (₱ per Unit):</label>
                        <input type="number" step="0.01" name="selling_price" required placeholder="0.00">

                        <button type="submit">Register Product Stock</button>
                    </form>
                </div>
            </div>

            <div style="margin-top: 35px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <h2 style="margin: 0;">Current Stock List</h2>
                    
                    <select id="invCategoryFilter" onchange="filterStockTable()" style="margin: 0; padding: 8px 12px; width: 220px; font-weight: bold; color: var(--accent-blue);">
                        <option value="">📂 All Categories</option>
                        <option value="Chicken">🍗 Chicken</option>
                        <option value="Spices">🧂 Spices & Seasoning</option>
                        <option value="Drinks">🥤 Drinks & Beverages</option>
                        <option value="Pork">🥩 Pork</option>
                        <option value="Beef">🥩 Beef</option>
                        <option value="Grocery">🛒 Grocery / General</option>
                        <option value="Frozen Products">🥶 Frozen Products</option>
                        <?php
                        $dbCategories = array_filter(array_unique(array_column($inventory, 'category')));
                        $presetCategories = ['Chicken', 'Spices', 'Drinks', 'Pork', 'Beef', 'Grocery', 'Frozen Products'];
                        foreach ($dbCategories as $dbc) {
                            if (!in_array($dbc, $presetCategories)) {
                                echo '<option value="' . htmlspecialchars($dbc) . '">' . htmlspecialchars($dbc) . '</option>';
                            }
                        }
                        ?>
                    </select>

                    <button type="button" onclick="toggleBulkToolbar()" style="width: auto; padding: 8px 12px; margin: 0; font-size: 14px;" title="Toggle Bulk Actions Toolbar">⚙️</button>
                </div>

                <div style="max-width: 300px; width: 100%;">
                    <input type="text" id="invTableSearch" placeholder="🔍 Search stock list..." onkeyup="filterStockTable()" style="margin-bottom: 0; padding: 8px 12px;">
                </div>
            </div>

            <form method="POST" id="bulkInventoryForm" onsubmit="return validateBulkForm()">
                <input type="hidden" name="bulk_inventory_action" value="1">
                <input type="hidden" name="bulk_action_type" id="bulkActionType" value="categorize">

                <div class="bulk-action-bar" id="bulkActionBar">
                    <label>⚙️ Bulk Action for Selected (<span id="selectedCountDisplay">0</span>):</label>
                    
                    <select name="bulk_category" id="bulkCategorySelect">
                        <option value="">-- Assign Category --</option>
                        <option value="Chicken">🍗 Chicken</option>
                        <option value="Spices">🧂 Spices & Seasoning</option>
                        <option value="Drinks">🥤 Drinks & Beverages</option>
                        <option value="Pork">🥩 Pork</option>
                        <option value="Beef">🥩 Beef</option>
                        <option value="Grocery">🛒 Grocery / General</option>
                        <option value="Frozen Products">🥶 Frozen Products</option>
                    </select>

                    <button type="submit" onclick="document.getElementById('bulkActionType').value='categorize'">🏷️ Update Category</button>
                    <button type="submit" class="bulk-delete-btn" onclick="document.getElementById('bulkActionType').value='delete'; return confirm('⚠️ Are you sure you want to BULK DELETE all selected items?');">🗑️ Delete Selected</button>
                </div>

                <table id="inventoryTable">
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;">
                                <input type="checkbox" id="selectAllMaster" onclick="toggleSelectAll(this)" style="margin:0; width:auto; cursor:pointer;">
                            </th>
                            <th>ID_CODE</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Stock Levels</th>
                            <th>Cost Price (Per Unit)</th>
                            <th>Selling Price (Per Unit)</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($inventory as $row): ?>
                    <?php 
                    $u = ($supports_unit && isset($row['unit'])) ? $row['unit'] : 'Piece'; 
                    $cat = $row['category'] ?? 'General';
                    ?>
                    <tr>
                        <td style="text-align: center;">
                            <input type="checkbox" name="selected_items[]" value="<?= $row['id'] ?>" class="item-checkbox" onclick="updateSelectedCount()" style="margin:0; width:auto; cursor:pointer;">
                        </td>
                        <td>#<?= str_pad($row['id'], 3, '0', STR_PAD_LEFT) ?></td>
                        <td><strong><?= htmlspecialchars($row['item_name']) ?></strong></td>
                        <td>
                            <span style="font-size: 11px; font-family: 'Share Tech Mono'; background: var(--accent-soft-purple); padding: 3px 8px; border-radius: 4px; border: 1px solid var(--accent-purple); color: var(--accent-purple); font-weight: bold; display: inline-block; white-space: nowrap;">
                                <?= htmlspecialchars($cat) ?>
                            </span>
                        </td>
                        <td><?= $row['quantity'] ?> <?= $u ?></td>
                        <td>₱<?= number_format($row['buying_price'], 2) ?> / <?= $u ?></td>
                        <td>₱<?= number_format($row['selling_price'], 2) ?> / <?= $u ?></td>
                        <td style="display: flex; gap: 5px;">
                            <button type="button" class="edit-btn" style="border-color: var(--accent-green); color: var(--accent-green);" onclick="openRestockModal(<?= $row['id'] ?>, '<?= addslashes(htmlspecialchars($row['item_name'])) ?>', '<?= $u ?>')">➕ Restock</button>
                            <button type="button" class="edit-btn" onclick="openEditModal(<?= $row['id'] ?>, '<?= addslashes(htmlspecialchars($row['item_name'])) ?>', '<?= addslashes(htmlspecialchars($cat)) ?>', '<?= $u ?>', <?= $row['quantity'] ?>, <?= $row['buying_price'] ?>, <?= $row['selling_price'] ?>)">✏️ Edit</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <!-- POPUP RESTOCK MODAL -->
            <div id="restockModal" class="modal-overlay">
                <div class="modal-box">
                    <h2>➕ Restock / Stock In</h2>
                    <br>
                    <form method="POST">
                        <input type="hidden" name="restock_item" value="1">
                        <input type="hidden" name="restock_item_id" id="restock_id">

                        <label>Product Name:</label>
                        <input type="text" id="restock_name" readonly style="background: var(--card-bg); font-weight: bold;">

                        <div id="kiloConverterBox" style="display: none; background: var(--accent-soft-blue); padding: 12px; border-radius: 6px; border: 1px solid var(--accent-blue); margin-bottom: 15px;">
                            <label style="color: var(--accent-blue); font-weight: bold; margin-bottom: 5px;">🐓 Piece-to-Kilo Restock Calculator:</label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                <div>
                                    <label style="font-size: 10px;">Number of Pcs:</label>
                                    <input type="number" id="pcsCount" placeholder="e.g. 20" oninput="calcRestockKg()" style="margin-bottom:0; padding:6px;">
                                </div>
                                <div>
                                    <label style="font-size: 10px;">Avg Kg / Pc:</label>
                                    <input type="number" step="0.001" id="avgKgPerPc" placeholder="e.g. 1.30" oninput="calcRestockKg()" style="margin-bottom:0; padding:6px;">
                                </div>
                            </div>
                        </div>

                        <label id="restockQtyLabel">Quantity / Weight to Add:</label>
                        <input type="number" step="0.001" name="add_quantity" id="restock_qty" required placeholder="0.000">

                        <div style="display: flex; gap: 10px; margin-top: 10px;">
                            <button type="submit" style="background: var(--accent-green); color: #ffffff; border-color: var(--accent-green);">Add to Current Stock</button>
                            <button type="button" onclick="closeRestockModal()" style="border-color: var(--accent-red); color: var(--accent-red);">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- POPUP EDIT MODAL -->
            <div id="editModal" class="modal-overlay">
                <div class="modal-box">
                    <h2>✏️ Edit Product Details</h2>
                    <br>
                    <form method="POST">
                        <input type="hidden" name="update_item" value="1">
                        <input type="hidden" name="edit_item_id" id="edit_id">

                        <label>Product Name & Description:</label>
                        <input type="text" name="item_name" id="edit_name" required>

                        <label>Product Category:</label>
                        <select name="category" id="edit_category" required>
                            <option value="Chicken">🍗 Chicken</option>
                            <option value="Spices">🧂 Spices & Seasoning</option>
                            <option value="Drinks">🥤 Drinks & Beverages</option>
                            <option value="Pork">🥩 Pork</option>
                            <option value="Beef">🥩 Beef</option>
                            <option value="Grocery">🛒 Grocery / General</option>
                            <option value="Frozen Products">🥶 Frozen Products</option>
                        </select>

                        <?php if ($supports_unit): ?>
                        <label>Unit of Measurement:</label>
                        <select name="unit" id="edit_unit" required>
                            <option value="Piece">Piece (pc)</option>
                            <option value="Case">Case / Box</option>
                            <option value="Liter">Liter (L)</option>
                            <option value="Pack">Pack / Sachet</option>
                            <option value="Bottle">Bottle</option>
                            <option value="Kilo (kg)">Kilo (kg)</option>
                        </select>
                        <?php endif; ?>

                        <label>Stock Quantity / Volume:</label>
                        <input type="number" step="0.001" name="quantity" id="edit_qty" required>

                        <label>Buying Cost Price (₱ per Unit):</label>
                        <input type="number" step="0.01" name="buying_price" id="edit_buy" required>

                        <label>Selling Price (₱ per Unit):</label>
                        <input type="number" step="0.01" name="selling_price" id="edit_sell" required>

                        <div style="display: flex; gap: 10px; margin-top: 10px;">
                            <button type="submit" style="background: var(--accent-green); color: #ffffff; border-color: var(--accent-green);">Save Changes</button>
                            <button type="button" onclick="closeEditModal()" style="border-color: var(--accent-red); color: var(--accent-red);">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            function toggleBulkToolbar() {
                const bar = document.getElementById('bulkActionBar');
                if (bar.style.display === 'none' || bar.style.display === '') {
                    bar.style.display = 'flex';
                } else {
                    bar.style.display = 'none';
                }
            }

            function toggleSelectAll(master) {
                const checkboxes = document.querySelectorAll('.item-checkbox');
                checkboxes.forEach(cb => {
                    if (cb.closest('tr').style.display !== 'none') {
                        cb.checked = master.checked;
                    }
                });
                updateSelectedCount();
            }

            function updateSelectedCount() {
                const checked = document.querySelectorAll('.item-checkbox:checked');
                document.getElementById('selectedCountDisplay').innerText = checked.length;
            }

            function validateBulkForm() {
                const checked = document.querySelectorAll('.item-checkbox:checked');
                const action = document.getElementById('bulkActionType').value;
                const category = document.getElementById('bulkCategorySelect').value;

                if (checked.length === 0) {
                    alert('⚠️ Please select at least one product using the checkboxes.');
                    return false;
                }

                if (action === 'categorize' && !category) {
                    alert('⚠️ Please choose a target category from the dropdown.');
                    return false;
                }

                return true;
            }

            function filterStockTable() {
                const searchVal = document.getElementById('invTableSearch').value.toLowerCase();
                const catVal = document.getElementById('invCategoryFilter').value.toLowerCase();
                const tableRows = document.querySelectorAll('#inventoryTable tbody tr');

                tableRows.forEach(row => {
                    const rowText = row.innerText.toLowerCase();
                    const catCellText = row.cells[3] ? row.cells[3].innerText.toLowerCase() : '';

                    const matchesSearch = rowText.includes(searchVal);
                    const matchesCategory = (catVal === '') || catCellText.includes(catVal);

                    if (matchesSearch && matchesCategory) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                        const cb = row.querySelector('.item-checkbox');
                        if (cb) cb.checked = false;
                    }
                });
                updateSelectedCount();
            }

            function openRestockModal(id, name, unit) {
                document.getElementById('restock_id').value = id;
                document.getElementById('restock_name').value = name;
                document.getElementById('restock_qty').value = '';
                document.getElementById('pcsCount').value = '';
                document.getElementById('avgKgPerPc').value = '';
                document.getElementById('restockQtyLabel').innerText = `Quantity / Weight to Add (${unit}):`;
                document.getElementById('kiloConverterBox').style.display = (unit && unit.toLowerCase().includes('kilo')) ? 'block' : 'none';
                document.getElementById('restockModal').style.display = 'flex';
            }

            function closeRestockModal() {
                document.getElementById('restockModal').style.display = 'none';
            }

            function calcRestockKg() {
                const pcs = parseFloat(document.getElementById('pcsCount').value || 0);
                const avg = parseFloat(document.getElementById('avgKgPerPc').value || 0);
                if (pcs > 0 && avg > 0) {
                    document.getElementById('restock_qty').value = (pcs * avg).toFixed(3);
                }
            }

            function openEditModal(id, name, category, unit, qty, buy, sell) {
                document.getElementById('edit_id').value = id;
                document.getElementById('edit_name').value = name;
                if(document.getElementById('edit_category')) {
                    document.getElementById('edit_category').value = category;
                }
                if(document.getElementById('edit_unit')) {
                    document.getElementById('edit_unit').value = unit;
                }
                document.getElementById('edit_qty').value = qty;
                document.getElementById('edit_buy').value = buy;
                document.getElementById('edit_sell').value = sell;
                document.getElementById('editModal').style.display = 'flex';
            }

            function closeEditModal() {
                document.getElementById('editModal').style.display = 'none';
            }
            </script>

        <?php elseif ($tab === 'wall_notes'): ?>
            <h1>📌 Notes on the Wall (Memo Board)</h1>
            <br>
            
            <div class="panel-box" style="max-width: 600px; margin-bottom: 30px;">
                <h2>Pin a New Sticky Note</h2>
                <br>
                <form method="POST">
                    <input type="hidden" name="add_wall_note" value="1">
                    
                    <label>Note Title / Subject (Optional):</label>
                    <input type="text" name="note_title" placeholder="e.g., Supplier Delivery Schedule, Reminder...">
                    
                    <label>Note Content / Message:</label>
                    <textarea name="note_content" rows="4" required placeholder="Write down any quick store notes, announcements, or reminders here..."></textarea>
                    
                    <button type="submit" style="background: var(--accent-blue); color: #ffffff; border-color: var(--accent-blue);">📌 Pin to the Wall</button>
                </form>
            </div>

            <h2>Pinned Notes Board</h2>
            
            <?php if (count($wall_notes) > 0): ?>
                <div class="notes-grid">
                    <?php foreach($wall_notes as $note): ?>
                    <div class="sticky-note">
                        <div>
                            <?php if (!empty($note['title'])): ?>
                                <h3><?= htmlspecialchars($note['title']) ?></h3>
                            <?php endif; ?>
                            <p><?= htmlspecialchars($note['content']) ?></p>
                        </div>
                        <div class="note-footer">
                            <span class="timestamp"><?= date('M d, Y h:i A', strtotime($note['created_at'])) ?></span>
                            <form method="POST" onsubmit="return confirm('Remove this note from the wall?');" style="margin:0;">
                                <input type="hidden" name="delete_wall_note" value="1">
                                <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                <button type="submit" class="delete-btn" style="padding: 2px 6px; font-size: 10px;">Discard</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color: var(--text-muted); font-style: italic; margin-top: 15px;">The wall is currently empty. Use the form above to pin your first sticky note!</p>
            <?php endif; ?>

        <?php elseif ($tab === 'logs'): ?>
            <h1>System Audit Trail</h1>
            <br>

            <div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
                <form method="GET" style="display: flex; align-items: center; gap: 10px; margin: 0;">
                    <input type="hidden" name="tab" value="logs">
                    <input type="hidden" name="sale_date" value="<?= htmlspecialchars($active_date) ?>">
                    <label style="margin:0;">Filter Date:</label>
                    <input type="date" name="log_date" value="<?= htmlspecialchars($log_date) ?>" onchange="this.form.submit()" style="margin: 0; max-width: 180px;">
                    <?php if(!empty($log_date)): ?>
                        <a href="?tab=logs&sale_date=<?= urlencode($active_date) ?>" style="font-family:'Share Tech Mono'; font-size: 11px; color: var(--accent-red); text-decoration: none; padding: 10px 14px; border: 1px solid var(--accent-red); border-radius: 6px; background: var(--panel-bg);">Clear Date</a>
                    <?php endif; ?>
                </form>

                <div style="flex: 1; min-width: 250px;">
                    <input type="text" id="logTableSearch" placeholder="🔍 Search logs by action, product, details, or date..." onkeyup="filterLogTable()" style="margin-bottom: 0; padding: 10px;">
                </div>
            </div>

            <table id="logsTable">
                <thead>
                    <tr>
                        <th>Log ID</th>
                        <th>Action Type</th>
                        <th>Item ID</th>
                        <th>Product Name</th>
                        <th>Details & Metrics</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($logs) > 0): ?>
                        <?php foreach($logs as $log): ?>
                        <tr>
                            <td>#<?= $log['log_id'] ?></td>
                            <td style="color: var(--accent-blue); font-weight: bold;"><?= htmlspecialchars($log['action_type']) ?></td>
                            <td>#<?= $log['item_id'] ?></td>
                            <td><?= htmlspecialchars($log['item_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($log['log_details'] ?? $log['details'] ?? '') ?></td>
                            <td><?= $log['timestamp'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted);">No activity logs found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <script>
            function filterLogTable() {
                const searchVal = document.getElementById('logTableSearch').value.toLowerCase();
                const tableRows = document.querySelectorAll('#logsTable tbody tr');

                tableRows.forEach(row => {
                    const rowText = row.innerText.toLowerCase();
                    row.style.display = rowText.includes(searchVal) ? '' : 'none';
                });
            }
            </script>

        <?php elseif ($tab === 'settings'): ?>
            <h1>System Settings & Data Control</h1>
            <br>

            <div class="panel-box" style="border-color: var(--accent-red); background: var(--card-bg); max-width: 700px;">
                <h2 style="color: var(--accent-red);">⚠️ Danger Zone: Refresh & Reset System Data</h2>
                <br>
                <p style="font-size: 14px; line-height: 1.6; color: var(--text-main);">
                    This option will wipe out and restart all <strong>Sales Transactions</strong>, <strong>Expense Records</strong>, <strong>Supplier Purchases</strong>, customer receipts history, and <strong>System Audit Logs</strong>. Use this if you want to clear out testing data or reset operations for a new period.
                </p>
                <div style="background: var(--accent-soft-green); border: 1px solid var(--accent-green); padding: 12px; border-radius: 6px; margin: 20px 0;">
                    <strong style="color: var(--accent-green); font-family: 'Share Tech Mono', monospace; font-size: 14px;">🛡️ GUARANTEED INVENTORY SAFETY:</strong>
                    <p style="margin: 5px 0 0 0; font-size: 13px; color: var(--text-main);">
                        Your <strong>Store Inventory</strong> matrix, stock levels, unit types, and buying/selling prices will <strong>never ever</strong> be deleted or modified by this reset. All your products remain fully intact.
                    </p>
                </div>
                <br>
                <form method="POST" onsubmit="return confirm('⚠️ FINAL WARNING: Are you absolute sure you want to clear all sales, expenses, purchases, and logs? Your inventory data is safe and will NOT be touched.');">
                    <input type="hidden" name="reset_system_data" value="1">
                    <button type="submit" style="background: var(--accent-red); color: #ffffff; border-color: var(--accent-red); font-size: 14px; padding: 14px;">🔄 Reset System Data (Keep Inventory Intact)</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- GLOBAL UNIVERSAL CUSTOMER RECEIPT MODAL -->
<div id="globalReceiptModal" class="modal-overlay">
    <div class="modal-box" id="receiptModalBox" style="width: 360px;">
        <div class="thermal-receipt" id="receiptContentContainer">
            <h3 style="text-align:center; margin:0 0 5px 0;">MANUKAN NG BAYAN</h3>
            <p style="text-align:center; font-size: 11px; margin:0 0 10px 0;">Official Customer Receipt</p>
            <div id="receiptDynamicBody">Loading receipt...</div>
        </div>
        <div style="display: flex; gap: 10px; margin-top: 15px;" class="no-print">
            <button type="button" onclick="window.print()" style="background: var(--accent-green); color: #ffffff; border-color: var(--accent-green);">🖨️ Print Receipt</button>
            <button type="button" onclick="closeGlobalReceiptModal()" style="border-color: var(--accent-red); color: var(--accent-red);">Close</button>
        </div>
    </div>
</div>

<!-- GLOBAL SUPPLIER PURCHASE ORDER / RECEIPT MODAL -->
<div id="globalPurchaseReceiptModal" class="modal-overlay">
    <div class="modal-box" id="purReceiptModalBox" style="width: 380px;">
        <div class="thermal-receipt" id="purReceiptContentContainer">
            <h3 style="text-align:center; margin:0 0 3px 0;">MANUKAN NG BAYAN</h3>
            <p style="text-align:center; font-size: 11px; margin:0 0 10px 0; color: #555;">Supplier Order Voucher & Delivery Receipt</p>
            <div id="purReceiptDynamicBody">Loading purchase order receipt...</div>
        </div>
        <div style="display: flex; gap: 10px; margin-top: 15px;" class="no-print">
            <button type="button" onclick="window.print()" style="background: var(--accent-purple); color: #ffffff; border-color: var(--accent-purple);">🖨️ Print Voucher</button>
            <button type="button" onclick="closeGlobalPurchaseReceiptModal()" style="border-color: var(--accent-red); color: var(--accent-red);">Close</button>
        </div>
    </div>
</div>

<script>
function toggleTheme() {
    const isDark = document.body.classList.toggle('dark-mode');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    updateThemeButtonText();
}

function updateThemeButtonText() {
    const icon = document.getElementById('themeIcon');
    const text = document.getElementById('themeText');
    if (icon && text) {
        const isDark = document.body.classList.contains('dark-mode');
        icon.innerText = isDark ? '☀️' : '🌙';
        text.innerText = isDark ? ' Light Mode' : ' Dark Mode';
    }
}

(function() {
    if (localStorage.getItem('theme') === 'dark') {
        document.body.classList.add('dark-mode');
    }
    document.addEventListener('DOMContentLoaded', updateThemeButtonText);
})();

const allSalesData = [
    <?php
    $allSalesForJS = $db->query("
        SELECT s.id, s.receipt_no, COALESCE(s.custom_item_name, i.item_name, 'Unlisted Item') AS item_name, s.quantity_sold, COALESCE(i.unit, 'Piece') AS unit, s.total_sales, s.sale_date 
        FROM sales s 
        LEFT JOIN inventory i ON s.item_id = i.id 
        WHERE s.receipt_no IS NOT NULL
        ORDER BY s.sale_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach($allSalesForJS as $as) {
        echo '{id: ' . $as['id'] . ', receipt_no: "' . $as['receipt_no'] . '", name: "' . addslashes($as['item_name']) . '", qty: ' . $as['quantity_sold'] . ', unit: "' . ($as['unit'] ?? 'Piece') . '", total: ' . $as['total_sales'] . ', date: "' . $as['sale_date'] . '"},' . "\n";
    }
    ?>
];

const allPurchasesData = [
    <?php
    $allPurchasesForJS = $db->query("
        SELECT id, purchase_no, supplier_name, item_name, quantity, unit, unit_cost, total_cost, discount, purchase_date, payment_status, notes 
        FROM purchases 
        ORDER BY purchase_date DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach($allPurchasesForJS as $ap) {
        echo '{id: ' . $ap['id'] . ', purchase_no: "' . addslashes($ap['purchase_no'] ?? '') . '", supplier: "' . addslashes($ap['supplier_name'] ?? 'N/A') . '", item: "' . addslashes($ap['item_name']) . '", qty: ' . $ap['quantity'] . ', unit: "' . addslashes($ap['unit'] ?? 'Piece') . '", cost: ' . $ap['unit_cost'] . ', discount: ' . (float)($ap['discount'] ?? 0) . ', total: ' . $ap['total_cost'] . ', date: "' . $ap['purchase_date'] . '", status: "' . addslashes($ap['payment_status'] ?? 'Paid') . '", notes: "' . addslashes($ap['notes'] ?? '') . '"},' . "\n";
    }
    ?>
];

function openReceiptModalByNo(receiptNo) {
    const items = allSalesData.filter(s => s.receipt_no === receiptNo);
    const container = document.getElementById('receiptDynamicBody');

    if (items.length === 0) {
        container.innerHTML = `<p style="text-align:center; color:red;">Receipt not found.</p>`;
        document.getElementById('globalReceiptModal').style.display = 'flex';
        return;
    }

    const saleDate = items[0].date;
    let grandTotal = 0;

    let html = `
        <hr style="border-top: 1px dashed #000; margin: 5px 0;">
        <p style="font-size: 11px; margin: 3px 0;"><strong>Receipt No:</strong> ${receiptNo}</p>
        <p style="font-size: 11px; margin: 3px 0 10px 0;"><strong>Date/Time:</strong> ${saleDate}</p>
        <hr style="border-top: 1px dashed #000; margin: 5px 0;">
        <table style="width:100%; font-size: 11px; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 1px dashed #000;">
                    <th style="text-align:left; padding: 4px 0; background:none; color:#000;">Item</th>
                    <th style="text-align:center; padding: 4px 0; background:none; color:#000;">Qty</th>
                    <th style="text-align:right; padding: 4px 0; background:none; color:#000;">Total</th>
                </tr>
            </thead>
            <tbody>
    `;

    items.forEach(i => {
        grandTotal += i.total;
        html += `
            <tr>
                <td style="padding: 4px 0; border:none;">${i.name}</td>
                <td style="text-align:center; padding: 4px 0; border:none;">${i.qty} ${i.unit}</td>
                <td style="text-align:right; padding: 4px 0; border:none;">₱${i.total.toFixed(2)}</td>
            </tr>
        `;
    });

    html += `
            </tbody>
        </table>
        <hr style="border-top: 1px dashed #000; margin: 8px 0;">
        <div style="display:flex; justify-content:space-between; font-size: 13px; font-weight:bold;">
            <span>TOTAL:</span>
            <span>₱${grandTotal.toFixed(2)}</span>
        </div>
        <hr style="border-top: 1px dashed #000; margin: 8px 0;">
        <p style="text-align:center; font-size: 10px; margin: 10px 0 0 0;">Thank you for your purchase!</p>
    `;

    container.innerHTML = html;
    document.getElementById('globalReceiptModal').style.display = 'flex';
}

function openPurchaseReceiptModalByNo(purchaseNo, purId) {
    let items = [];
    if (purchaseNo && purchaseNo !== 'N/A' && purchaseNo !== '') {
        items = allPurchasesData.filter(p => p.purchase_no === purchaseNo);
    }
    if (items.length === 0 && purId) {
        items = allPurchasesData.filter(p => p.id === purId);
    }

    const container = document.getElementById('purReceiptDynamicBody');

    if (items.length === 0) {
        container.innerHTML = `<p style="text-align:center; color:red;">Purchase voucher not found.</p>`;
        document.getElementById('globalPurchaseReceiptModal').style.display = 'flex';
        return;
    }

    const firstItem = items[0];
    const poNumber = firstItem.purchase_no || ('PO-SINGLE-' + firstItem.id);
    const supplier = firstItem.supplier || 'Unspecified Supplier';
    const purDate = firstItem.date;
    const status = firstItem.status;
    const notes = firstItem.notes || '-';
    
    let subtotal = 0;
    let totalDiscount = 0;

    let html = `
        <hr style="border-top: 1px dashed #000; margin: 5px 0;">
        <p style="font-size: 11px; margin: 3px 0;"><strong>PO Number:</strong> ${poNumber}</p>
        <p style="font-size: 11px; margin: 3px 0;"><strong>Supplier:</strong> ${supplier}</p>
        <p style="font-size: 11px; margin: 3px 0;"><strong>Date:</strong> ${purDate}</p>
        <p style="font-size: 11px; margin: 3px 0 10px 0;"><strong>Status:</strong> <span style="font-weight:bold; color:${status==='Paid'?'#00b33c':'#f59e0b'};">${status.toUpperCase()}</span></p>
        <hr style="border-top: 1px dashed #000; margin: 5px 0;">
        <table style="width:100%; font-size: 11px; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 1px dashed #000;">
                    <th style="text-align:left; padding: 4px 0; background:none; color:#000;">Item</th>
                    <th style="text-align:center; padding: 4px 0; background:none; color:#000;">Qty</th>
                    <th style="text-align:right; padding: 4px 0; background:none; color:#000;">Cost</th>
                    <th style="text-align:right; padding: 4px 0; background:none; color:#000;">Total</th>
                </tr>
            </thead>
            <tbody>
    `;

    items.forEach(i => {
        let lineGross = (i.qty * i.cost);
        subtotal += lineGross;
        totalDiscount += i.discount;

        html += `
            <tr>
                <td style="padding: 4px 0; border:none;">${i.item}</td>
                <td style="text-align:center; padding: 4px 0; border:none;">${i.qty} ${i.unit}</td>
                <td style="text-align:right; padding: 4px 0; border:none;">₱${i.cost.toFixed(2)}</td>
                <td style="text-align:right; padding: 4px 0; border:none;">₱${lineGross.toFixed(2)}</td>
            </tr>
        `;
    });

    let netTotal = Math.max(0, subtotal - totalDiscount);

    html += `
            </tbody>
        </table>
        <hr style="border-top: 1px dashed #000; margin: 8px 0;">
        <div style="display:flex; justify-content:space-between; font-size: 11px; margin-bottom: 3px;">
            <span>Subtotal:</span>
            <span>₱${subtotal.toFixed(2)}</span>
        </div>
    `;

    if (totalDiscount > 0) {
        html += `
            <div style="display:flex; justify-content:space-between; font-size: 11px; color:#00b33c; font-weight:bold; margin-bottom: 3px;">
                <span>Supplier Discount:</span>
                <span>-₱${totalDiscount.toFixed(2)}</span>
            </div>
        `;
    }

    html += `
        <div style="display:flex; justify-content:space-between; font-size: 13px; font-weight:bold;">
            <span>NET ORDER COST:</span>
            <span>₱${netTotal.toFixed(2)}</span>
        </div>
        <hr style="border-top: 1px dashed #000; margin: 8px 0;">
        <p style="font-size: 10px; margin: 5px 0 0 0; color: #444;"><strong>Notes / DR Reference:</strong> ${notes}</p>
        <p style="text-align:center; font-size: 10px; margin: 12px 0 0 0;">Received & Verified Stock Entry</p>
    `;

    container.innerHTML = html;
    document.getElementById('globalPurchaseReceiptModal').style.display = 'flex';
}

function closeGlobalReceiptModal() {
    document.getElementById('globalReceiptModal').style.display = 'none';
}

function closeGlobalPurchaseReceiptModal() {
    document.getElementById('globalPurchaseReceiptModal').style.display = 'none';
}

window.onclick = function(event) {
    const editModal = document.getElementById('editModal');
    const restockModal = document.getElementById('restockModal');
    const receiptModal = document.getElementById('globalReceiptModal');
    const purReceiptModal = document.getElementById('globalPurchaseReceiptModal');
    const editSaleModal = document.getElementById('editSaleModal');
    const editPurchaseModal = document.getElementById('editPurchaseModal');
    const editExpenseModal = document.getElementById('editExpenseModal');

    if (event.target === editModal) closeEditModal();
    if (event.target === restockModal) closeRestockModal();
    if (event.target === receiptModal) closeGlobalReceiptModal();
    if (event.target === purReceiptModal) closeGlobalPurchaseReceiptModal();
    if (event.target === editSaleModal) closeEditSaleModal();
    if (event.target === editPurchaseModal) closeEditPurchaseModal();
    if (event.target === editExpenseModal) closeEditExpenseModal();
};
</script>

</body>
</html>
