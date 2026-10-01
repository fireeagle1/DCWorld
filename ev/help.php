<?php include 'header.php'; ?>

<div class="container mt-5">
    <h1 class="mb-4">Help & Instructions</h1>

    <div class="card mb-4">
        <div class="card-body">
            <h3>Overview</h3>
            <p>This system automates the process of tracking and paying for electric vehicle (EV) charging sessions. Here's how it works:</p>

            <h4>Monthly Export Process</h4>
            <p>On the first day of every month, Charlie will use the Pod Point app to export the charging data for the previous month. This data includes all the charging sessions that took place during that month.</p>
            <p>Once exported, the data is sent via email to Charlie's email address. This email is automatically forwarded to the dedicated <strong>exports inbox</strong>, where it is processed by the system.</p>

            <h4>Generating Monthly Data</h4>
            <p>Upon receiving the email, the system processes the attached CSV file and generates a new tab for the month within this application. The total cost for all charging sessions in that month is automatically calculated based on the current cost per kWh.</p>

            <h4>Making a Payment</h4>
            <p>After the monthly data is processed, Charlie can click on the link provided within the application to review the charging sessions and the total cost. This link will also provide an option to generate a BACS payment, which Charlie can send from the bills bank account to settle the charges.</p>

            <h4>Updating Cost per kWh</h4>
            <p>If the cost per kWh changes, you can update it by clicking <a href="settings.php">here</a>.</p>
        </div>
    </div>
</div>

<?php include '../footer.php'; ?>

</body>
</html>
