<?php
require_once __DIR__ . '/config.php';

session_start();

if ( !isset( $_SESSION[ 'user_id' ] ) ) {
    header( 'Location: index.php' );
    exit();
}

// Fetch current user data including signature
$user_id = $_SESSION[ 'user_id' ];
$stmt = $mysqli->prepare( 'SELECT signature FROM users WHERE id = ?' );
$current_signature = null;
if ( $stmt ) {
    $stmt->bind_param( 'i', $user_id );
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $current_signature = $user[ 'signature' ] ?? null;
    $stmt->close();
}
?>

<?php if ( isset( $_SESSION[ 'message' ] ) ): ?>
<div class="alert alert-<?php echo $_SESSION['msg_type']; ?> text-center">
    <?php echo $_SESSION[ 'message' ];
unset( $_SESSION[ 'message' ] );
?>
</div>
<?php endif;
?>

<!DOCTYPE html>
<html lang='en'>

<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Profile</title>
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'>
    <style>
    body {
        background-color: #f4f4f4;
    }

    .container {
        margin-top: 50px;
    }

    .card {
        border-radius: 10px;
        transition: transform 0.3s ease-in-out;
    }

    .card:hover {
        transform: scale(1.02);
    }

    .fade-in {
        opacity: 0;
        transform: translateY(-10px);
        animation: fadeIn 0.5s ease-in-out forwards;
    }

    @keyframes fadeIn {
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    </style>
</head>

<body class='fade-in'>

    <div class='container'>
        <div class='row justify-content-center'>
            <div class='col-md-6'>
                <div class='card shadow p-4'>
                    <h2 class='text-center'>Update Profile</h2>
                    <form method='POST' action='process.php' enctype='multipart/form-data'>
                        <div class='mb-3'>
                            <label class='form-label'>Username</label>
                            <input type='text' name='username' value="<?php echo $_SESSION['username']; ?>"
                                class='form-control' required>
                        </div>
                        <div class='mb-3'>
                            <label class='form-label'>Digital Signature</label>
                            <?php if ( $current_signature ): ?>
                            <div class='mb-2'>
                                <img src="<?php echo htmlspecialchars($current_signature); ?>" alt='Current Signature'
                                    style='max-height: 60px; border: 1px solid #ddd; padding: 2px;'>
                            </div>
                            <?php endif;
?>
                            <input type='file' name='signature' class='form-control' accept='image/*'>
                            <div class='form-text text-muted'>Upload a PNG/JPG image of your signature.</div>
                        </div>

                        <hr class='my-4'>
                        <h5 class='mb-3'>Change Password</h5>
                        <div class='mb-3'>
                            <label class='form-label'>Current Password</label>
                            <input type='password' name='current_password' class='form-control'
                                placeholder='Current Password'>
                        </div>
                        <div class='mb-3'>
                            <label class='form-label'>New Password</label>
                            <input type='password' name='password' class='form-control'
                                placeholder='Leave blank to keep current' minlength='8' maxlength='8'>
                        </div>
                        <div class='mb-3'>
                            <label class='form-label'>Confirm New Password</label>
                            <input type='password' name='confirm_password' class='form-control' minlength='8'
                                maxlength='8'>
                        </div>
                        <button type='submit' name='update' class='btn btn-primary w-100'>Update</button>
                    </form>
                    <a href='index.php' class='btn btn-secondary mt-3 w-100'>Back</a>
                </div>
            </div>
        </div>
    </div>

</body>

</html>