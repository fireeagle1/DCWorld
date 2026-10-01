<?php
session_start(); // Start the session

// Check if the user is already logged in
if (isset($_SESSION['userID'])) {
    header("Location: dashboard.php");
    exit; // Ensure no further code is executed
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <link rel="icon" href="https://assets.dcworld.uk/images/faviconnew.ico" type="image/x-icon">
    <title>The Dash - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cabin+Sketch:wght@400;700&display=swap');
        /* Custom Tailwind Configuration for Background */
        body {
            background: linear-gradient(to bottom right, #1e3a8a, #3b82f6);
        }
        .logo-text {
            font-family: 'Cabin Sketch', cursive;
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen">
    <div class="bg-white rounded-lg shadow-lg p-8 max-w-md w-full">
        <!-- Logo Section as Text -->
        <div class="flex justify-center mb-6">
            <h1 class="text-4xl text-gray-800 font-bold logo-text">The Dash
            
            </h1>
       
        </div>
         

        <!-- Login Form -->
        <form action="authenticate.php" method="post" class="space-y-4">
            <!-- Email -->
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input type="email" id="email" name="email" placeholder="Enter your email" 
                       class="w-full mt-1 px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <!-- Password -->
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" 
                       class="w-full mt-1 px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <!-- Submit Button -->
            <button type="submit" 
                    class="w-full py-2 px-4 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                Sign In
            </button>
        </form>
        <!-- Footer Links -->
        <div class="mt-6 text-center text-sm text-gray-500">
                    <div class="flex justify-center mb-6">
            <h1 class="text-4xl text-gray-800 font-bold logo-text">
            <?php if ($_SERVER['HTTP_HOST'] === 'dev.tyche.dcworld.uk'): ?>
        <div class="">
            DEV ENVIRONMENT
        </div>
    <?php endif; ?>
            </h1>
            
            <p>
                <a href="https://dcworld.uk" target="_blank" class="text-blue-500 hover:underline">DC World 🌍</a> Product Engineered by 
                <a href="https://ckenterprises.co.uk" target="_blank" class="text-blue-500 hover:underline">CK Enterprises UK</a>
            </p>
        </div>
    </div>
</body>
</html>
