<?php
/**
 * header.php – unified navigation for “The Dash”
 */
require_once __DIR__ . '/config.php';
if (!isset($page_title)) {
    $page_title = 'The Dash';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="icon" href="https://assets.dcworld.uk/images/faviconnew.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --primary-gradient-start: #38b2ac;
            --primary-gradient-end:   #3182ce;
            --white:  #ffffff;
            --black:  #00171f;
            --light-gray: #f8f9fa;
        }

        body { background-color: var(--light-gray); color: var(--black); }
        header,
        .navbar {
            background: linear-gradient(to right,
                var(--primary-gradient-start),
                var(--primary-gradient-end));
        }

        .navbar-brand,
        .navbar-nav .nav-link { color: var(--white) !important; font-weight: 600; }
        .navbar-nav .nav-link:hover { color: #f8f9fa !important; }

        /* Nested dropdown support (Bootstrap 5 has no native sub-menus) */
        .dropdown-submenu .dropdown-menu { left: 100%; top: 0; margin-left: .1rem; }
        @media (hover:hover)   { .dropdown-submenu:hover > .dropdown-menu { display:block; } }
        @media (max-width:992px){ .dropdown-submenu > .dropdown-menu { margin-left:0; } }

        .navbar-brand img { height: 50px; }

        /* External button */
        .nav-external-btn{
            display:inline-flex;
            align-items:center;
            gap:.45rem;
            border:1px solid rgba(255,255,255,.45);
            border-radius: 999px;
            padding: .35rem .75rem;
            line-height: 1;
            white-space: nowrap;
            background: rgba(255,255,255,.12);
        }
        .nav-external-btn:hover{
            background: rgba(255,255,255,.2);
            border-color: rgba(255,255,255,.65);
        }
        .external-icon{
            display:inline-block;
            width: .95em;
            height: .95em;
            position: relative;
            top: -1px;
        }

        /* Divider like your screenshot (vertical rule) */
        .nav-divider{
            width: 1px;
            height: 26px;
            background: rgba(255,255,255,.55);
            margin: 0 .85rem;
            align-self: center;
        }

        /* Mobile behavior: show a horizontal divider instead of a vertical one */
        @media (max-width: 991.98px){
            .nav-divider{
                width: 100%;
                height: 1px;
                margin: .6rem 0;
                background: rgba(255,255,255,.35);
            }
            .nav-external-item{
                width: 100%;
                display:flex;
                justify-content:center;
                padding-top: .15rem;
            }
        }
    </style>
<?php if (!empty($wardrobe_modern)): ?>
    <link rel="stylesheet" href="/wardrobe-modern.css?v=1">
<?php endif; ?>
</head>
<body<?= !empty($wardrobe_modern) ? ' class="wardrobe-page"' : '' ?>>

<?php if (($_SERVER['HTTP_HOST'] ?? '') === 'dev.tyche.dcworld.uk'): ?>
    <div class="bg-danger text-white text-center py-2">DEV&nbsp;ENVIRONMENT</div>
<?php endif; ?>

<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">

        <!-- Brand logo acts as “Home” button -->
        <a class="navbar-brand" href="/dashboard.php">
            <img src="https://assets.dcworld.uk/images/The%20Dash.png" alt="The Dash Logo">
        </a>

        <!-- Mobile toggler -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#mainNav" aria-controls="mainNav"
                aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Collapsible menu -->
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto align-items-lg-center">

                <!-- Diary & Organisation -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="diaryOrg"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        Diary &amp; Organisation
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="diaryOrg">
                        <li><a class="dropdown-item" href="/calendar.php">Calendar</a></li>
                        <li><a class="dropdown-item" href="/ops.php">Update Locations</a></li>
                    </ul>
                </li>

                <!-- Guest Manager -->
                <li class="nav-item">
                    <a class="nav-link" href="/home/guest_manager.php">Guest Manager</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/finance.php">Finance</a>
                </li>

                <!-- Contacts -->
                <li class="nav-item">
                    <a class="nav-link" href="/home/manage_contacts.php">Contacts</a>
                </li>

                <!-- Wardrobe -->
                <li class="nav-item">
                    <a class="nav-link" href="/wardrobe.php">Wardrobe</a>
                </li>

                <!-- My Profile / Admin -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="profileDrop"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        My&nbsp;Profile
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="profileDrop">
                        <li><a class="dropdown-item" href="https://dev.tyche.dcworld.uk/functions/dash.php">Admin</a></li>
                        <li><a class="dropdown-item" href="/calendar-images.php">Calendar Images</a></li>
                        <li><a class="dropdown-item" href="/myuser.php">My Profile</a></li>
                        <li><a class="dropdown-item" href="/communications.php">Communicate</a></li>
                        <li><a class="dropdown-item" href="/dcism.php">“DC'ism”</a></li>
                        <li><a class="dropdown-item" href="/CkTools.php">CK Tools</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/logout.php">Logout</a></li>
                    </ul>
                </li>

                <!-- Vertical divider -->
                <li class="nav-item d-none d-lg-flex" aria-hidden="true">
                    <div class="nav-divider"></div>
                </li>
                <!-- Mobile divider -->
                <li class="nav-item d-lg-none" aria-hidden="true">
                    <div class="nav-divider"></div>
                </li>

                <!-- External home button (far right) -->
                <li class="nav-item nav-external-item">
                    <a class="nav-link nav-external-btn"
                       href="https://home.dcworld.uk"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="Open DCWorld Home (external)">
                        <span>Home Assistant</span>
                        <svg class="external-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M14 3h7v7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M21 3l-9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M10 7H7a4 4 0 0 0-4 4v6a4 4 0 0 0 4 4h6a4 4 0 0 0 4-4v-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                </li>

            </ul>
        </div>
    </div>
</nav>

</body>
</html>
