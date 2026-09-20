<?php
$db = new PDO('sqlite:shop.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create inventory table
$db->exec("CREATE TABLE IF NOT EXISTS inventory (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_name TEXT NOT NULL,
    quantity INTEGER NOT NULL,
    buying_price REAL NOT NULL,
    selling_price REAL NOT NULL
)");

// Create sales log table
$db->exec("CREATE TABLE IF NOT EXISTS sales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id INTEGER,
    quantity_sold INTEGER,
    total_sales REAL,
    total_profit REAL,
    sale_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(item_id) REFERENCES inventory(id)
)");

echo "Database and tables created successfully! <a href='index.php'>Go to Shop Dashboard</a>";
?>