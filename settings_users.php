<?php
declare(strict_types=1);

session_start();

require 'config.php';
require 'auth.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validation

    if ($name === '') {
        $error = 'Please enter a name.';
    }

    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    }

    elseif (strlen($password) < 8) {
        $error = 'Passwords must be at least 8 characters.';
    }

    elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    }

    if ($error === '') {

        // Check email doesn't already exist

        $stmt = $link->prepare("
            SELECT UserID
            FROM DC_Users
            WHERE Email = ?
            LIMIT 1
        ");

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {

            $error = 'That email address already exists.';

        } else {

            $stmt->close();

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $defaultAvatar = '';

            $insert = $link->prepare("
                INSERT INTO DC_Users
                (
                    Name,
                    Email,
                    Password,
                    IMGURL
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            $insert->bind_param(
                "ssss",
                $name,
                $email,
                $passwordHash,
                $defaultAvatar
            );

            if ($insert->execute()) {

                $message = 'User created successfully.';

                $_POST = [];

            } else {

                $error = 'Unable to create user.';
            }

            $insert->close();
        }
    }
}

// Load existing users

$users = [];

$result = $link->query("
    SELECT
        UserID,
        Name,
        Email,
        IMGURL
    FROM DC_Users
    ORDER BY Name
");

while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

$page_title = "Users";

include 'header.php';
?>

<div class="container py-5">

    <div class="row">

        <div class="col-lg-6">

            <div class="card shadow-sm">

                <div class="card-header">
                    <h3 class="mb-0">Add User</h3>
                </div>

                <div class="card-body">

                    <?php if ($message): ?>

                        <div class="alert alert-success">
                            <?= htmlspecialchars($message) ?>
                        </div>

                    <?php endif; ?>

                    <?php if ($error): ?>

                        <div class="alert alert-danger">
                            <?= htmlspecialchars($error) ?>
                        </div>

                    <?php endif; ?>

                    <form method="post">

                        <div class="mb-3">

                            <label class="form-label">
                                Name
                            </label>

                            <input
                                class="form-control"
                                name="name"
                                required
                                value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                            >

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Email
                            </label>

                            <input
                                type="email"
                                class="form-control"
                                name="email"
                                required
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                            >

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Password
                            </label>

                            <input
                                type="password"
                                class="form-control"
                                name="password"
                                required
                            >

                        </div>

                        <div class="mb-4">

                            <label class="form-label">
                                Confirm Password
                            </label>

                            <input
                                type="password"
                                class="form-control"
                                name="confirm_password"
                                required
                            >

                        </div>

                        <button
                            class="btn btn-dark w-100"
                            type="submit"
                        >
                            Create User
                        </button>

                    </form>

                </div>

            </div>

        </div>

        <div class="col-lg-6">

            <div class="card shadow-sm">

                <div class="card-header">
                    <h3 class="mb-0">Current Users</h3>
                </div>

                <div class="list-group list-group-flush">

                    <?php foreach ($users as $user): ?>

                        <div class="list-group-item d-flex align-items-center">

                            <?php if (!empty($user['IMGURL'])): ?>

                                <img
                                    src="<?= htmlspecialchars($user['IMGURL']) ?>"
                                    class="rounded-circle me-3"
                                    width="50"
                                    height="50"
                                    style="object-fit:cover;"
                                >

                            <?php else: ?>

                                <div
                                    class="rounded-circle bg-dark text-white d-flex align-items-center justify-content-center me-3"
                                    style="width:50px;height:50px;font-weight:bold;"
                                >
                                    <?= strtoupper(substr($user['Name'],0,1)) ?>
                                </div>

                            <?php endif; ?>

                            <div class="flex-grow-1">

                                <strong>
                                    <?= htmlspecialchars($user['Name']) ?>
                                </strong>

                                <br>

                                <small class="text-muted">
                                    <?= htmlspecialchars($user['Email']) ?>
                                </small>

                            </div>

                            <a
                                href="settings_user_edit.php?id=<?= (int)$user['UserID'] ?>"
                                class="btn btn-outline-secondary btn-sm"
                            >
                                Edit
                            </a>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>

    </div>

</div>

<?php include 'footer.php'; ?>