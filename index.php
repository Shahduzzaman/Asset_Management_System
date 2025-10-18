<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Product Management</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f6fa;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }
        h1 {
            margin-bottom: 30px;
            color: #2f3640;
        }
        .btn-container {
            display: flex;
            gap: 20px;
        }
        a.button {
            text-decoration: none;
            background-color: #0984e3;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 16px;
            transition: background 0.3s;
        }
        a.button:hover {
            background-color: #74b9ff;
        }
    </style>
</head>
<body>
    <h1>Product Management Portal</h1>
    <div class="btn-container">
        <a href="purchase_product.php" class="button">Purchased Product</a>
        <a href="product_list.php" class="button">View Product</a>
        <a href="create_user.php" class="button">Create User</a>

    </div>
</body>
</html>
